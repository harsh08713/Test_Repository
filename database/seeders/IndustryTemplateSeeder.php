<?php

namespace Database\Seeders;

use App\Modules\Industry\Models\IndustryTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The 15 industry templates and the default loss workflow: system data shared by every company, so it is seeded in
 * every environment (also production) and is safe to run again.
 *
 * A template that an operator has already moved past version 1 is left alone: this seeder only ever writes version 1.
 *
 * Row formats in database/seeders/industries/*.php:
 *   kpis       [code, name, formula_key, unit, direction, green, amber, red, numerator, denominator]
 *   categories [code, name, default_formula_key, default_severity]
 *   fields     [key, label, type, unit, options|null, required, group]
 *   reports    [report_key, name]
 */
class IndustryTemplateSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->defaultWorkflow();

            foreach (glob(database_path('seeders/industries/*.php')) ?: [] as $file) {
                /** @var array<string, mixed> $definition */
                $definition = require $file;
                $this->template($definition);
            }
        });
    }

    /** @param  array<string, mixed>  $d */
    private function template(array $d): void
    {
        $template = IndustryTemplate::query()->firstOrNew(['code' => $d['code']]);

        if ($template->exists && $template->version > 1) {
            return;
        }

        $template->fill(['name' => $d['name'], 'icon' => $d['icon'], 'description' => $d['description'], 'version' => 1, 'status' => 'active', 'is_system' => true])->save();

        $now = now();
        $id = $template->id;

        foreach ($d['kpis'] as [$code, $name, $formula, $unit, $direction, $green, $amber, $red, $num, $den]) {
            DB::table('kpi_definitions')->upsert([[
                'industry_template_id' => $id, 'version' => 1, 'code' => $code, 'name' => $name, 'formula_key' => $formula,
                'numerator_desc' => $num, 'denominator_desc' => $den, 'unit' => $unit, 'multiplier' => $unit === '%' ? 100 : 1,
                'direction' => $direction, 'threshold_green' => $green, 'threshold_amber' => $amber, 'threshold_red' => $red,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]], ['industry_template_id', 'version', 'code'], ['name', 'formula_key', 'numerator_desc', 'denominator_desc', 'unit', 'multiplier', 'direction', 'updated_at']);
        }

        foreach ($d['categories'] as [$code, $name, $formula, $severity]) {
            DB::table('loss_categories')->upsert([[
                'industry_template_id' => $id, 'version' => 1, 'code' => $code, 'name' => $name,
                'default_formula_key' => $formula, 'default_severity' => $severity, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]], ['industry_template_id', 'version', 'code'], ['name', 'default_formula_key', 'default_severity', 'updated_at']);
        }

        foreach ($d['fields'] as $order => [$key, $label, $type, $unit, $options, $required, $group]) {
            DB::table('template_fields')->upsert([[
                'industry_template_id' => $id, 'version' => 1, 'key' => $key, 'label' => $label, 'type' => $type, 'unit' => $unit,
                'options' => $options === null ? null : json_encode($options), 'validation_rules' => null,
                'is_required' => $required, 'sort_order' => $order, 'group' => $group, 'created_at' => $now, 'updated_at' => $now,
            ]], ['industry_template_id', 'version', 'key'], ['label', 'type', 'unit', 'options', 'is_required', 'sort_order', 'group', 'updated_at']);
        }

        foreach ($d['reports'] as [$key, $name]) {
            DB::table('template_reports')->upsert([[
                'industry_template_id' => $id, 'version' => 1, 'report_key' => $key, 'name' => $name, 'config' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]], ['industry_template_id', 'version', 'report_key'], ['name', 'updated_at']);
        }
    }

    /** The states and moves of brief §6.2 step 7: used by every template that has none of its own. */
    private function defaultWorkflow(): void
    {
        $now = now();

        $states = [
            ['draft', 'Draft', true, false, 'slate'], ['submitted', 'Submitted', false, false, 'blue'], ['triage', 'Triage', false, false, 'blue'],
            ['investigation', 'Investigation', false, false, 'amber'], ['action_required', 'Action required', false, false, 'orange'],
            ['verification', 'Verification', false, false, 'violet'], ['closed', 'Closed', false, true, 'green'],
            ['rejected', 'Rejected', false, true, 'red'], ['on_hold', 'On hold', false, false, 'slate'], ['reopened', 'Reopened', false, false, 'orange'],
        ];

        foreach ($states as $order => [$code, $name, $initial, $final, $color]) {
            $this->upsertDefault('workflow_states', ['code' => $code], [
                'name' => $name, 'is_initial' => $initial, 'is_final' => $final, 'color' => $color, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // from, to, action, permission, requires comment, requires approval
        $moves = [
            ['draft', 'submitted', 'submit', 'loss_incident.create', false, false],
            ['submitted', 'triage', 'triage', 'loss_incident.edit', false, false],
            ['submitted', 'rejected', 'reject', 'loss_incident.approve', true, false],
            ['triage', 'investigation', 'investigate', 'loss_incident.edit', false, false],
            ['triage', 'rejected', 'reject', 'loss_incident.approve', true, false],
            ['investigation', 'action_required', 'require_action', 'loss_incident.edit', false, false],
            ['action_required', 'verification', 'verify', 'loss_incident.edit', false, false],
            ['verification', 'action_required', 'send_back', 'loss_incident.approve', true, false],
            ['verification', 'closed', 'close', 'loss_incident.approve', false, true],
            ['investigation', 'on_hold', 'hold', 'loss_incident.edit', true, false],
            ['action_required', 'on_hold', 'hold', 'loss_incident.edit', true, false],
            ['on_hold', 'investigation', 'resume', 'loss_incident.edit', false, false],
            ['closed', 'reopened', 'reopen', 'loss_incident.approve', true, false],
            ['reopened', 'investigation', 'investigate', 'loss_incident.edit', false, false],
        ];

        foreach ($moves as [$from, $to, $action, $permission, $comment, $approval]) {
            $this->upsertDefault('workflow_transitions', ['from_state' => $from, 'to_state' => $to], [
                'action' => $action, 'required_permission' => $permission, 'requires_comment' => $comment, 'requires_approval' => $approval,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /**
     * The default workflow has a null template id, which a unique key cannot match (NULL is never equal to NULL),
     * so look the row up by hand instead of upserting.
     *
     * @param  array<string, string>  $key
     * @param  array<string, mixed>  $values
     */
    private function upsertDefault(string $table, array $key, array $values): void
    {
        $query = DB::table($table)->whereNull('industry_template_id')->where('version', 1)->where($key);

        if ($query->exists()) {
            unset($values['created_at']);
            $query->update($values);

            return;
        }

        DB::table($table)->insert([...$key, ...$values, 'industry_template_id' => null, 'version' => 1]);
    }
}
