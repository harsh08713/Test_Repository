<?php

namespace App\Modules\Loss\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Finance\Models\CostCenter;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Loss\Models\RootCauseAnalysis;
use App\Modules\Loss\Models\WorkflowEvent;
use App\Modules\Loss\Notifications\LossAlertNotification;
use App\Modules\Masters\Models\Department;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Unit;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\NumberSeriesService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The one door for loss incident data (its state moves through LossWorkflowService; its money through LossCalculationService).
 *
 *  - **Reporting** (`create`): under an industry template the company has switched on, at the version it is pinned to (kept on the incident), with a
 *    loss category of that version and the template's extra fields validated on the server; at a branch the person works in. Numbered first.
 *    An optional first figure (`impact`) is added in the same transaction.
 *  - **From a source** (`source_type` + `source_id`: scrap, expired batch, rejection, sales return — LossSources): the source must be one the person
 *    can see. If an incident already covers the same source, the new one is kept but **linked to it as a duplicate** and its money is not counted.
 *  - **Escalation**: a safety, quality or compliance incident, or a critical one, tells everyone holding `loss_incident.approve` at once (claimed
 *    once with `escalated_at`).
 *  - **Assignment**, **containment** and **root cause** are their own steps, each audited.
 */
final class LossIncidentService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly NumberSeriesService $numbers,
        private readonly IndustryTemplateService $templates,
        private readonly LossWorkflowService $workflow,
        private readonly LossCalculationService $calculator,
        private readonly LossSources $sources,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input, User $actor): LossIncident
    {
        Gate::forUser($actor)->authorize('create', LossIncident::class);

        $data = $this->validated($input, $actor, null);
        $source = $this->source($input);

        $incident = DB::transaction(function () use ($data, $source, $input, $actor) {
            $number = $this->numbers->next(DocumentType::LossIncident, $data['location_id'], now()->startOfDay());
            $template = IndustryTemplate::query()->findOrFail($data['industry_template_id']);

            $incident = new LossIncident;
            $incident->forceFill([
                'company_id' => (int) $this->tenant->activeCompanyId(), 'incident_number' => $number, 'reported_at' => now(), 'reporter_id' => $actor->getKey(),
                'state' => $this->workflow->initialState($template, $data['template_version']), 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
                'source_type' => $source['type'] ?? null, 'source_id' => $source['id'] ?? null, 'primary_incident_id' => $source === null ? null : $this->primaryFor($source),
            ] + $data)->save();

            WorkflowEvent::record($incident, null, $incident->state, 'reported', $actor);
            activity('loss_incidents')->causedBy($actor)->performedOn($incident)->event('reported')
                ->withProperties(['source' => $source === null ? null : "{$source['type']}#{$source['id']}", 'duplicate_of' => $incident->primary_incident_id])->log('reported');

            if (is_array($input['impact'] ?? null) && ($input['impact']['formula_key'] ?? '') !== '') {
                $this->calculator->addOnReport($incident, $input['impact'], $actor);
            }

            return $incident;
        });

        if ($incident->needsImmediateEscalation()) {
            $this->escalate($incident->fresh() ?? $incident);
        }

        return $incident->refresh();
    }

    /**
     * Change what was reported, while the incident is open. The template, its version and the source never change.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(LossIncident $incident, array $input, User $actor): LossIncident
    {
        Gate::forUser($actor)->authorize('update', $incident);

        $data = $this->validated([...$input, 'industry_template_id' => $incident->industry_template_id], $actor, $incident);
        unset($data['industry_template_id'], $data['template_version']);

        $wasUrgent = $incident->needsImmediateEscalation();

        $fresh = DB::transaction(function () use ($incident, $data, $actor) {
            $fresh = LossIncident::query()->lockForUpdate()->findOrFail($incident->getKey());
            $fresh->forceFill(['updated_by' => $actor->getKey()] + $data)->save();   // audited by the model, field by field

            return $fresh;
        });

        if (! $wasUrgent && $fresh->needsImmediateEscalation()) {
            $this->escalate($fresh);
        }

        return $fresh->refresh();
    }

    /** Name the investigator and the accountable owner (both must be able to open incidents in this company). */
    public function assign(LossIncident $incident, ?int $investigatorId, ?int $ownerId, User $actor): LossIncident
    {
        Gate::forUser($actor)->authorize('assign', $incident);

        $errors = [];
        foreach (['investigator_id' => $investigatorId, 'owner_id' => $ownerId] as $field => $id) {
            if ($id !== null && ! $this->eligible($id, (int) $incident->company_id)) {
                $errors[$field] = 'Choose someone in this company who can work on loss incidents.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $fresh = DB::transaction(function () use ($incident, $investigatorId, $ownerId, $actor) {
            $fresh = LossIncident::query()->lockForUpdate()->findOrFail($incident->getKey());
            $before = ['investigator_id' => $fresh->investigator_id, 'owner_id' => $fresh->owner_id];
            $fresh->forceFill(['investigator_id' => $investigatorId, 'owner_id' => $ownerId, 'updated_by' => $actor->getKey()])->save();

            activity('loss_incidents')->causedBy($actor)->performedOn($fresh)->event('assigned')
                ->withProperties(['before' => $before, 'after' => ['investigator_id' => $investigatorId, 'owner_id' => $ownerId]])->log('assigned');

            return $fresh;
        });

        foreach (array_unique(array_filter([$investigatorId, $ownerId])) as $userId) {
            if ($userId !== $actor->getKey() && ($user = User::query()->find($userId)) !== null) {
                $user->notify(new LossAlertNotification('loss_assigned', $this->titleOf($fresh), "You have been named on loss incident {$fresh->incident_number}.", route('loss.incidents.show', $fresh)));
            }
        }

        return $fresh;
    }

    /** Record what was done to stop the loss getting worse. */
    public function contain(LossIncident $incident, string $notes, User $actor): LossIncident
    {
        Gate::forUser($actor)->authorize('investigate', $incident);
        $notes = trim($notes);

        if (mb_strlen($notes) < 3 || mb_strlen($notes) > 2000) {
            throw ValidationException::withMessages(['containment_notes' => 'Say what was done to contain it (3 to 2000 characters).']);
        }

        return DB::transaction(function () use ($incident, $notes, $actor) {
            $fresh = LossIncident::query()->lockForUpdate()->findOrFail($incident->getKey());
            $fresh->forceFill(['contained_at' => $fresh->contained_at ?? now(), 'containment_notes' => $notes, 'updated_by' => $actor->getKey()])->save();

            return $fresh;
        });
    }

    /**
     * Record a root-cause analysis: five whys (a chain of whys and the root cause) or fishbone (a cause category, contributing factors and the root cause).
     *
     * @param  array<string, mixed>  $input  method, cause_category, root_cause, findings, whys[], contributing_factors[]
     */
    public function recordRootCause(LossIncident $incident, array $input, User $actor): RootCauseAnalysis
    {
        Gate::forUser($actor)->authorize('investigate', $incident);

        $method = (string) ($input['method'] ?? '');
        $category = trim((string) ($input['cause_category'] ?? ''));
        $rootCause = trim((string) ($input['root_cause'] ?? ''));
        $findings = trim((string) ($input['findings'] ?? ''));
        $list = fn (string $key) => array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) ($input[$key] ?? [])), fn ($v) => $v !== ''));
        $whys = $list('whys');
        $factors = $list('contributing_factors');
        $errors = [];

        if (! array_key_exists($method, RootCauseAnalysis::METHODS)) {
            $errors['method'] = 'Choose 5 whys or fishbone.';
        }

        if (mb_strlen($rootCause) < 3 || mb_strlen($rootCause) > 2000) {
            $errors['root_cause'] = 'State the root cause (3 to 2000 characters).';
        }

        if ($method === 'five_whys' && ($whys === [] || count($whys) > 5)) {
            $errors['whys'] = 'Give between one and five whys.';
        }

        if ($method === 'fishbone' && ! array_key_exists($category, RootCauseAnalysis::CATEGORIES)) {
            $errors['cause_category'] = 'Choose the cause category.';
        }

        if ($category !== '' && ! array_key_exists($category, RootCauseAnalysis::CATEGORIES)) {
            $errors['cause_category'] = 'Choose one of the cause categories.';
        }

        if (count($factors) > 20 || count(array_filter([...$whys, ...$factors], fn ($v) => mb_strlen($v) > 500)) > 0 || mb_strlen($findings) > 5000) {
            $errors['findings'] = 'Too long: at most 20 factors, 500 characters each, and 5000 characters of findings.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($incident, $method, $category, $rootCause, $findings, $whys, $factors, $actor) {
            $rca = new RootCauseAnalysis;
            $rca->forceFill([
                'company_id' => (int) $incident->company_id, 'loss_incident_id' => $incident->getKey(), 'method' => $method, 'cause_category' => $category === '' ? null : $category,
                'root_cause' => $rootCause, 'findings' => $findings === '' ? null : $findings, 'whys' => $method === 'five_whys' ? $whys : null,
                'contributing_factors' => $factors === [] ? null : $factors, 'analysed_by' => $actor->getKey(), 'analysed_at' => now(),
            ])->save();

            activity('loss_incidents')->causedBy($actor)->performedOn($incident)->event('root_cause_recorded')
                ->withProperties(['analysis' => $rca->id, 'method' => $method, 'category' => $rca->cause_category])->log('root_cause_recorded');

            return $rca;
        });
    }

    /**
     * Tell everyone who decides loss incidents in the company, once. Claimed with a conditional UPDATE so a retried or concurrent call sends nothing twice.
     */
    public function escalate(LossIncident $incident): bool
    {
        $claimed = LossIncident::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($incident->getKey())->whereNull('escalated_at')->update(['escalated_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        $why = implode(', ', array_filter([
            $incident->is_safety ? 'safety' : null, $incident->is_quality ? 'quality' : null, $incident->is_compliance ? 'compliance' : null,
            $incident->severity === 'critical' ? 'critical severity' : null,
        ]));
        $note = new LossAlertNotification('loss_escalated', $this->titleOf($incident),
            "Loss incident {$incident->incident_number} was reported as a {$why} risk and needs containing now.", route('loss.incidents.show', $incident));

        foreach (User::query()->permission('loss_incident.approve')->where('is_active', true)->whereHas('companies', fn ($q) => $q->where('companies.id', $incident->company_id))->get() as $user) {
            $user->notify($note);
        }

        return true;
    }

    // ---- internals ------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(array $input, User $actor, ?LossIncident $editing): array
    {
        $errors = [];
        $companyId = (int) $this->tenant->activeCompanyId();
        $int = fn (string $key) => is_numeric($input[$key] ?? null) ? (int) $input[$key] : null;
        $text = fn (string $key) => trim((string) ($input[$key] ?? ''));

        // template: switched on for the company (or, when editing, the one it was made under)
        $template = ($id = $int('industry_template_id')) !== null ? IndustryTemplate::query()->find($id) : null;
        $version = $editing?->template_version;

        if ($template === null || ($editing === null && ! $this->templates->isEnabled($template))) {
            $errors['industry_template_id'] = 'Choose one of the industry templates your company uses.';
        } else {
            $version ??= $this->templates->versionFor($template);
        }

        $category = ($id = $int('loss_category_id')) !== null && $template !== null && $version !== null
            ? LossCategory::query()->whereKey($id)->where('industry_template_id', $template->id)->where('version', $version)->where('is_active', true)->first() : null;

        if ($category === null) {
            $errors['loss_category_id'] = 'Choose a loss category of this industry template.';
        }

        // branch the person works in
        $locationId = $int('location_id');
        $reach = $this->tenant->accessibleLocationIdsFor($actor, $companyId);

        if ($locationId === null || ! Location::query()->whereKey($locationId)->exists() || ($reach !== null && ! in_array($locationId, $reach, true))) {
            $errors['location_id'] = 'Choose a branch you work in.';
        }

        $departmentId = $int('department_id');
        if ($departmentId !== null && ! Department::query()->whereKey($departmentId)->exists()) {
            $errors['department_id'] = 'Choose a department of this company.';
        }

        $costCenterId = $int('cost_center_id');
        if ($costCenterId !== null && ! CostCenter::query()->whereKey($costCenterId)->where('status', 'active')->exists()) {
            $errors['cost_center_id'] = 'Choose an active cost centre of this company.';
        }

        $itemId = $int('item_id');
        $item = $itemId === null ? null : Item::query()->whereKey($itemId)->where('approval_status', 'approved')->first();
        if ($itemId !== null && $item === null) {
            $errors['item_id'] = 'Choose an approved item.';
        }

        $batchId = $int('batch_id');
        if ($batchId !== null && ($item === null || ! Batch::query()->whereKey($batchId)->where('item_id', $item->id)->exists())) {
            $errors['batch_id'] = 'That batch is not a batch of the item.';
        }

        $unitId = $int('unit_id');
        if ($unitId !== null && ! Unit::query()->whereKey($unitId)->exists()) {
            $errors['unit_id'] = 'Choose a unit of this company.';
        }

        $quantity = $text('quantity');
        if ($quantity !== '' && (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $quantity) !== 1)) {
            $errors['quantity'] = 'Enter a quantity, with up to 3 decimals.';
        }

        $occurred = $text('occurred_at');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $occurred) ?: \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $occurred);
        if ($parsed === false) {
            $errors['occurred_at'] = 'Enter when it happened (date and time).';
        } elseif ($parsed->format('Y-m-d H:i') > now()->format('Y-m-d H:i')) {
            $errors['occurred_at'] = 'It cannot have happened in the future.';
        }

        $severity = $text('severity');
        if (! array_key_exists($severity, LossIncident::SEVERITIES)) {
            $errors['severity'] = 'Choose how serious it is.';
        }

        $title = $text('title');
        $description = $text('description');
        if (mb_strlen($title) < 3 || mb_strlen($title) > 200) {
            $errors['title'] = 'Give it a short title (3 to 200 characters).';
        }
        if (mb_strlen($description) < 3 || mb_strlen($description) > 5000) {
            $errors['description'] = 'Describe what happened (3 to 5000 characters).';
        }

        foreach (['process' => 150, 'asset_ref' => 100] as $field => $max) {
            if (mb_strlen($text($field)) > $max) {
                $errors[$field] = "At most {$max} characters.";
            }
        }

        $custom = [];
        if ($template !== null && $version !== null) {
            try {
                $custom = $this->templates->validateFields($template, $version, (array) ($input['custom_fields'] ?? []));
            } catch (ValidationException $e) {
                $errors = [...$errors, ...$e->errors()];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn ($m) => is_array($m) ? $m[0] : $m, $errors));
        }

        $flag = fn (string $key) => filter_var($input[$key] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'industry_template_id' => $template->id, 'template_version' => $version, 'loss_category_id' => $category->id, 'location_id' => $locationId,
            'department_id' => $departmentId, 'cost_center_id' => $costCenterId, 'process' => $text('process') ?: null, 'item_id' => $itemId, 'batch_id' => $batchId, 'asset_ref' => $text('asset_ref') ?: null,
            'occurred_at' => $parsed->format('Y-m-d H:i:00'), 'quantity' => $quantity === '' ? null : bcadd($quantity, '0', 3), 'unit_id' => $unitId,
            'severity' => $severity, 'is_safety' => $flag('is_safety'), 'is_quality' => $flag('is_quality'), 'is_compliance' => $flag('is_compliance'),
            'title' => $title, 'description' => $description, 'custom_fields' => $custom === [] ? null : $custom,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{type: string, id: int}|null
     *
     * @throws FieldRuleException
     */
    private function source(array $input): ?array
    {
        $type = trim((string) ($input['source_type'] ?? ''));

        if ($type === '') {
            return null;
        }

        $id = is_numeric($input['source_id'] ?? null) ? (int) $input['source_id'] : 0;

        if ($this->sources->describe($type, $id) === null) {
            throw new FieldRuleException('That record cannot be reported as a loss (it is not one of yours, or it is not a loss).');
        }

        return ['type' => $type, 'id' => $id];
    }

    /**
     * The incident that already covers this source, if any (not a duplicate itself, not rejected).
     *
     * @param  array{type: string, id: int}  $source
     */
    private function primaryFor(array $source): ?int
    {
        $id = LossIncident::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->tenant->activeCompanyId())
            ->where('source_type', $source['type'])->where('source_id', $source['id'])->whereNull('primary_incident_id')->where('state', '<>', 'rejected')
            ->lockForUpdate()->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function eligible(int $userId, int $companyId): bool
    {
        $user = User::query()->where('is_active', true)->find($userId);

        return $user !== null && $user->can('loss_incident.view') && $this->tenant->userCanAccessCompany($user, $companyId);
    }

    private function titleOf(LossIncident $incident): string
    {
        return "{$incident->incident_number} — {$incident->title}";
    }
}
