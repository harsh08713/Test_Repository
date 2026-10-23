<?php

namespace App\Modules\Industry\Services;

use App\Models\User;
use App\Modules\Industry\Models\CompanyIndustryTemplate;
use App\Modules\Industry\Models\IndustrySetting;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\KpiDefinition;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Industry\Models\TemplateField;
use App\Modules\Industry\Models\TemplateReport;
use App\Modules\Industry\Models\WorkflowState;
use App\Modules\Industry\Models\WorkflowTransition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The one door for industry-template data: which templates a company uses, what a template looks like for that
 * company (shared rows of its pinned version plus the company's own threshold overrides), and the server-side
 * validation of a template's extra incident fields.
 *
 * The shared rows are system data. A company never edits them; a new version is published by an operator
 * (publishNewVersion), and a company moves to it on purpose (moveToCurrentVersion).
 */
final class IndustryTemplateService
{
    public const SETTING_THRESHOLDS = 'kpi_thresholds';

    /**
     * Templates the active company has switched on, in name order.
     *
     * @return Collection<int, IndustryTemplate>
     */
    public function enabled(): Collection
    {
        $ids = CompanyIndustryTemplate::query()->pluck('industry_template_id');

        return IndustryTemplate::query()->whereIn('id', $ids)->where('status', 'active')->orderBy('name')->get();
    }

    public function isEnabled(IndustryTemplate $template): bool
    {
        return CompanyIndustryTemplate::query()->where('industry_template_id', $template->id)->exists();
    }

    /** The version the active company sees: its pinned one if it uses the template, else the current one. */
    public function versionFor(IndustryTemplate $template): int
    {
        $pinned = CompanyIndustryTemplate::query()->where('industry_template_id', $template->id)->value('version_pinned');

        return $pinned === null ? $template->version : (int) $pinned;
    }

    public function enable(IndustryTemplate $template, User $actor): void
    {
        Gate::forUser($actor)->authorize('configure', IndustryTemplate::class);

        if ($template->status !== 'active') {
            throw ValidationException::withMessages(['template' => 'That template is switched off by the system.']);
        }

        $row = CompanyIndustryTemplate::query()->firstOrNew(['industry_template_id' => $template->id]);

        if ($row->exists) {
            return;     // already on: nothing to change, nothing to audit
        }

        $row->fill(['version_pinned' => $template->version, 'enabled_by' => $actor->id, 'enabled_at' => now()])->save();

        activity('industry')->causedBy($actor)->performedOn($template)->event('enabled')
            ->withProperties(['version' => $template->version])->log("Enabled the {$template->name} template.");
    }

    public function disable(IndustryTemplate $template, User $actor): void
    {
        Gate::forUser($actor)->authorize('configure', IndustryTemplate::class);

        $deleted = CompanyIndustryTemplate::query()->where('industry_template_id', $template->id)->delete();

        if ($deleted > 0) {
            activity('industry')->causedBy($actor)->performedOn($template)->event('disabled')
                ->log("Disabled the {$template->name} template.");
        }
    }

    public function moveToCurrentVersion(IndustryTemplate $template, User $actor): void
    {
        Gate::forUser($actor)->authorize('configure', IndustryTemplate::class);

        $row = CompanyIndustryTemplate::query()->where('industry_template_id', $template->id)->first();

        if ($row === null) {
            throw ValidationException::withMessages(['template' => 'Enable the template before moving it to a newer version.']);
        }

        $from = $row->version_pinned;

        if ($from === $template->version) {
            return;
        }

        $row->update(['version_pinned' => $template->version]);

        activity('industry')->causedBy($actor)->performedOn($template)->event('version_moved')
            ->withProperties(['from' => $from, 'to' => $template->version])
            ->log("Moved the {$template->name} template from version {$from} to {$template->version}.");
    }

    /**
     * What the template looks like for the active company: the pinned version's rows, KPI thresholds with the
     * company's overrides applied, and the workflow (the template's own, else the default).
     *
     * @return array{template: IndustryTemplate, version: int, enabled: bool, kpis: Collection<int, KpiDefinition>, categories: Collection<int, LossCategory>, fields: Collection<int, TemplateField>, reports: Collection<int, TemplateReport>, states: Collection<int, WorkflowState>, transitions: Collection<int, WorkflowTransition>}
     */
    public function resolve(IndustryTemplate $template): array
    {
        $version = $this->versionFor($template);
        $overrides = $this->thresholdOverrides($template);

        $kpis = KpiDefinition::query()->where('industry_template_id', $template->id)->where('version', $version)
            ->where('is_active', true)->orderBy('id')->get()
            ->each(function (KpiDefinition $kpi) use ($overrides) {
                foreach (($overrides[$kpi->code] ?? []) as $key => $value) {
                    $kpi->setAttribute("threshold_{$key}", $value);
                }
            });

        $workflow = $this->workflow($template, $version);

        return [
            'template' => $template,
            'version' => $version,
            'enabled' => $this->isEnabled($template),
            'kpis' => $kpis,
            'categories' => LossCategory::query()->where('industry_template_id', $template->id)->where('version', $version)->where('is_active', true)->orderBy('id')->get(),
            'fields' => TemplateField::query()->where('industry_template_id', $template->id)->where('version', $version)->orderBy('sort_order')->orderBy('id')->get(),
            'reports' => TemplateReport::query()->where('industry_template_id', $template->id)->where('version', $version)->orderBy('id')->get(),
            'states' => $workflow['states'],
            'transitions' => $workflow['transitions'],
        ];
    }

    /**
     * The loss workflow of a template at a given version (an incident uses the version it was made under): the template's own states and moves
     * if it has them, otherwise the default workflow.
     *
     * @return array{states: Collection<int, WorkflowState>, transitions: Collection<int, WorkflowTransition>}
     */
    public function workflow(IndustryTemplate $template, int $version): array
    {
        $own = WorkflowState::query()->where('industry_template_id', $template->id)->where('version', $version)->exists();
        $owner = $own ? $template->id : null;
        $workflowVersion = $own ? $version : 1;

        return [
            'states' => WorkflowState::query()->where('industry_template_id', $owner)->where('version', $workflowVersion)->orderBy('sort_order')->get(),
            'transitions' => WorkflowTransition::query()->where('industry_template_id', $owner)->where('version', $workflowVersion)->orderBy('id')->get(),
        ];
    }

    /** @return array<string, array<string, string>> KPI code => [green|amber|red => value] */
    public function thresholdOverrides(IndustryTemplate $template): array
    {
        $value = IndustrySetting::query()->where('industry_template_id', $template->id)->where('key', self::SETTING_THRESHOLDS)->value('value');

        return is_array($value) ? $value : [];
    }

    /**
     * Set the active company's thresholds for one KPI. Green is the good side of amber: for a higher-is-better KPI green ≥ amber,
     * for a lower-is-better one green ≤ amber. Blank clears an override and brings back the template's own number.
     */
    public function setKpiThresholds(IndustryTemplate $template, string $kpiCode, ?string $green, ?string $amber, ?string $red, User $actor): void
    {
        Gate::forUser($actor)->authorize('configure', IndustryTemplate::class);

        $kpi = KpiDefinition::query()->where('industry_template_id', $template->id)->where('version', $this->versionFor($template))
            ->where('code', $kpiCode)->first();

        if ($kpi === null) {
            throw ValidationException::withMessages(['kpi' => 'That KPI is not part of this template.']);
        }

        $new = [];
        foreach (['green' => $green, 'amber' => $amber, 'red' => $red] as $level => $raw) {
            $raw = $raw === null ? '' : trim($raw);

            if ($raw === '') {
                continue;
            }

            if (preg_match('/^-?\d{1,14}(\.\d{1,4})?$/', $raw) !== 1) {
                throw ValidationException::withMessages(["thresholds.{$level}" => 'Enter a number with up to 4 decimals.']);
            }

            $new[$level] = $raw;
        }

        $this->assertOrdered($kpi->direction, $new);

        DB::transaction(function () use ($template, $kpiCode, $new, $actor, $kpi) {
            $setting = IndustrySetting::query()->where('industry_template_id', $template->id)->where('key', self::SETTING_THRESHOLDS)
                ->lockForUpdate()->first() ?? new IndustrySetting(['industry_template_id' => $template->id, 'key' => self::SETTING_THRESHOLDS]);

            $all = is_array($setting->value) ? $setting->value : [];
            $before = $all[$kpiCode] ?? null;

            if ($new === []) {
                unset($all[$kpiCode]);
            } else {
                $all[$kpiCode] = $new;
            }

            $setting->fill(['value' => $all, 'updated_by' => $actor->id])->save();

            activity('industry')->causedBy($actor)->performedOn($template)->event('thresholds_changed')
                ->withProperties(['kpi' => $kpi->code, 'before' => $before, 'after' => $new === [] ? null : $new])
                ->log("Changed the thresholds of {$kpi->name}.");
        });
    }

    /** Green / amber / red for a KPI value; null when there is no value or no thresholds. @param  numeric-string|null  $value */
    public function status(KpiDefinition $kpi, ?string $value): ?string
    {
        if ($value === null || $kpi->threshold_green === null || $kpi->threshold_amber === null) {
            return null;
        }

        $higher = $kpi->direction === 'higher_better';

        if ($higher ? bccomp($value, $kpi->threshold_green, 4) >= 0 : bccomp($value, $kpi->threshold_green, 4) <= 0) {
            return 'green';
        }

        if ($higher ? bccomp($value, $kpi->threshold_amber, 4) >= 0 : bccomp($value, $kpi->threshold_amber, 4) <= 0) {
            return 'amber';
        }

        return 'red';
    }

    /**
     * Validate a loss incident's template fields against the template version and return the cleaned values.
     * Unknown keys are refused, not dropped: a form that sends a field the template does not have is out of date.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validateFields(IndustryTemplate $template, int $version, array $values, string $prefix = 'custom_fields'): array
    {
        $fields = TemplateField::query()->where('industry_template_id', $template->id)->where('version', $version)->get()->keyBy('key');
        $errors = [];
        $clean = [];

        foreach (array_diff_key($values, $fields->all()) as $key => $_) {
            $errors["{$prefix}.{$key}"] = 'This template has no such field.';
        }

        foreach ($fields as $key => $field) {
            $raw = $values[$key] ?? null;
            $blank = $raw === null || $raw === '' || $raw === [];

            if ($blank) {
                if ($field->is_required) {
                    $errors["{$prefix}.{$key}"] = "{$field->label} is required.";
                }

                continue;
            }

            $problem = $this->checkValue($field, $raw);

            if (is_string($problem)) {
                $errors["{$prefix}.{$key}"] = $problem;
            } else {
                $clean[$key] = $problem[0];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * Copy every row of the template's current version into the next one and make that current. Existing companies stay
     * on the version they are pinned to until they move. Run by an operator (a seeder or command), never from a screen.
     */
    public function publishNewVersion(IndustryTemplate $template): int
    {
        return DB::transaction(function () use ($template) {
            $template = IndustryTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $from = $template->version;
            $to = $from + 1;
            $now = now();

            foreach (['kpi_definitions', 'loss_categories', 'template_fields', 'template_reports', 'workflow_states', 'workflow_transitions'] as $table) {
                $rows = DB::table($table)->where('industry_template_id', $template->id)->where('version', $from)->get();

                if ($rows->isEmpty()) {
                    continue;
                }

                $copies = $rows->map(function (object $row) use ($to, $now) {
                    $copy = (array) $row;
                    unset($copy['id']);
                    $copy['version'] = $to;
                    $copy['created_at'] = $now;
                    $copy['updated_at'] = $now;

                    return $copy;
                });

                if ($table === 'loss_categories') {
                    // Parent links point at version rows: re-point them at the copies of the same codes.
                    $this->insertCategoryCopies($rows, $to, $now);

                    continue;
                }

                DB::table($table)->insert($copies->all());
            }

            $template->update(['version' => $to]);

            return $to;
        });
    }

    /** @param  Collection<int, \stdClass>  $rows */
    private function insertCategoryCopies(Collection $rows, int $to, mixed $now): void
    {
        $codeById = $rows->pluck('code', 'id');
        $newIds = [];

        foreach ($rows->sortBy(fn (\stdClass $r) => $r->parent_id === null ? 0 : 1) as $row) {
            $copy = (array) $row;
            unset($copy['id']);
            $copy['version'] = $to;
            $copy['parent_id'] = $row->parent_id === null ? null : ($newIds[$codeById[$row->parent_id] ?? ''] ?? null);
            $copy['created_at'] = $now;
            $copy['updated_at'] = $now;
            $newIds[$row->code] = DB::table('loss_categories')->insertGetId($copy);
        }
    }

    /** @param  array<string, string>  $levels */
    private function assertOrdered(string $direction, array $levels): void
    {
        $ordered = ['green', 'amber', 'red'];
        $present = array_values(array_filter($ordered, fn (string $l) => isset($levels[$l])));

        for ($i = 1; $i < count($present); $i++) {
            $better = $levels[$present[$i - 1]];
            $worse = $levels[$present[$i]];
            $ok = $direction === 'higher_better' ? bccomp($better, $worse, 4) >= 0 : bccomp($better, $worse, 4) <= 0;

            if (! $ok) {
                $word = $direction === 'higher_better' ? 'at least' : 'at most';
                throw ValidationException::withMessages(['thresholds' => "For this KPI {$present[$i - 1]} must be {$word} {$present[$i]}."]);
            }
        }
    }

    /** @return string|array{0: mixed} a problem, or the cleaned value in a one-element array */
    private function checkValue(TemplateField $field, mixed $raw): string|array
    {
        $rules = $field->validation_rules ?? [];
        $label = $field->label;

        switch ($field->type) {
            case 'number':
            case 'decimal':
                if (! is_scalar($raw) || preg_match($field->type === 'number' ? '/^-?\d{1,15}$/' : '/^-?\d{1,15}(\.\d{1,4})?$/', trim((string) $raw)) !== 1) {
                    return "{$label} must be ".($field->type === 'number' ? 'a whole number.' : 'a number with up to 4 decimals.');
                }

                $value = trim((string) $raw);

                if (isset($rules['min']) && bccomp($value, (string) $rules['min'], 4) < 0) {
                    return "{$label} must be at least {$rules['min']}.";
                }

                if (isset($rules['max']) && bccomp($value, (string) $rules['max'], 4) > 0) {
                    return "{$label} must be at most {$rules['max']}.";
                }

                return [$value];

            case 'boolean':
                $bool = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                return $bool === null ? "{$label} must be yes or no." : [$bool];

            case 'date':
            case 'datetime':
                $format = $field->type === 'date' ? 'Y-m-d' : 'Y-m-d H:i';
                $parsed = is_string($raw) ? \DateTimeImmutable::createFromFormat('!'.$format, $raw) : false;

                return $parsed !== false && $parsed->format($format) === $raw ? [$raw] : "{$label} must be a valid ".($field->type === 'date' ? 'date (YYYY-MM-DD).' : 'date and time (YYYY-MM-DD HH:MM).');

            case 'select':
                return is_string($raw) && in_array($raw, $field->options ?? [], true) ? [$raw] : "{$label} must be one of the listed choices.";

            case 'multiselect':
                if (! is_array($raw) || array_diff($raw, $field->options ?? []) !== []) {
                    return "{$label} must be chosen from the listed choices.";
                }

                return [array_values(array_unique($raw))];

            default:    // text
                if (! is_string($raw)) {
                    return "{$label} must be text.";
                }

                $max = (int) ($rules['max_length'] ?? 500);

                return mb_strlen($raw) > $max ? "{$label} must be at most {$max} characters." : [trim($raw)];
        }
    }
}
