<?php

namespace App\Modules\Marketing\Services;

use App\Models\User;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\Marketing\Models\MessageTemplate;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Shared\Models\Location;
use App\Modules\Workspace\Enums\TaskPriority;
use Illuminate\Validation\ValidationException;

/**
 * What a workflow may be made of — and the check that a definition is made only of it. A workflow is a **trigger** (something that happens), **conditions**
 * (facts that must match) and **actions** (what to do), all chosen from the lists below; there is no free-form code, no way to name a table or a
 * permission, and nothing here can approve a document, skip consent or leave the company.
 *
 * Definition: `{"params": {...}, "conditions": [{"field", "op", "value"}], "actions": [{"type", ...}]}`.
 */
final class WorkflowDefinition
{
    public const MAX_ACTIONS = 5;

    public const MAX_CONDITIONS = 8;

    /** @var array<string, array{label: string, subject: string, params: array<string, array{label: string, default: int, min: int, max: int}>}> */
    public const TRIGGERS = [
        'lead_created' => ['label' => 'A lead is created', 'subject' => 'lead', 'params' => []],
        'stage_changed' => ['label' => 'A lead changes stage', 'subject' => 'lead', 'params' => []],
        'followup_overdue' => ['label' => 'A follow-up is overdue', 'subject' => 'lead', 'params' => ['after_hours' => ['label' => 'Overdue by (hours)', 'default' => 24, 'min' => 1, 'max' => 720]]],
        'deal_won' => ['label' => 'A deal is won', 'subject' => 'deal', 'params' => []],
        'deal_lost' => ['label' => 'A deal is lost', 'subject' => 'deal', 'params' => []],
        'quotation_expiring' => ['label' => 'A sent quotation is nearing its expiry', 'subject' => 'quotation', 'params' => ['days_before' => ['label' => 'Days before it expires', 'default' => 3, 'min' => 1, 'max' => 60]]],
        'order_approved' => ['label' => 'A sales order is approved', 'subject' => 'order', 'params' => []],
    ];

    /** @var array<string, string> field => label */
    public const FIELDS = ['source' => 'Lead source', 'stage' => 'Stage', 'branch' => 'Branch', 'owner' => 'Owner', 'value' => 'Value (₹)'];

    /** @var array<string, string> */
    public const ACTIONS = [
        'create_task' => 'Create a task',
        'schedule_reminder' => 'Schedule a reminder',
        'notify_manager' => 'Notify the owner’s manager',
        'assign_lead' => 'Assign the lead',
        'send_message' => 'Queue an approved message',
    ];

    /** The subject kinds a trigger offers an action on. */
    private const LEAD_ONLY = ['assign_lead'];

    /**
     * Check and tidy a definition typed on a screen. Everything not on the lists is refused, not ignored.
     *
     * @param  array<string, mixed>  $raw
     * @return array{params: array<string, int>, conditions: list<array{field: string, op: string, value: mixed}>, actions: list<array<string, mixed>>}
     *
     * @throws ValidationException keyed `trigger`, `conditions.N`, `actions.N`
     */
    public function clean(string $trigger, array $raw, User $author): array
    {
        $meta = self::TRIGGERS[$trigger] ?? throw ValidationException::withMessages(['trigger' => 'Choose what starts the workflow.']);
        $errors = [];
        $people = ReportInput::people($author);

        $params = [];
        foreach ($meta['params'] as $key => $rule) {
            $v = $raw['params'][$key] ?? $rule['default'];
            $valid = is_numeric($v) && (int) $v >= $rule['min'] && (int) $v <= $rule['max'];
            $params[$key] = $valid ? (int) $v : 0;

            if (! $valid) {
                $errors["params.{$key}"] = "{$rule['label']}: a whole number from {$rule['min']} to {$rule['max']}.";
            }
        }

        $conditions = [];
        $rawConditions = array_values(array_filter((array) ($raw['conditions'] ?? []), 'is_array'));
        count($rawConditions) > self::MAX_CONDITIONS && $errors['conditions'] = 'At most '.self::MAX_CONDITIONS.' conditions.';

        foreach (array_slice($rawConditions, 0, self::MAX_CONDITIONS) as $i => $c) {
            $field = (string) ($c['field'] ?? '');
            $value = $c['value'] ?? null;

            if (! array_key_exists($field, self::FIELDS)) {
                $errors["conditions.{$i}"] = 'Choose what to check.';

                continue;
            }

            if ($field === 'value') {
                $op = in_array($c['op'] ?? '', ['gte', 'lte'], true) ? $c['op'] : null;

                if ($op === null || ! is_scalar($value) || ! preg_match('/^\d{1,13}(\.\d{1,2})?$/', trim((string) $value))) {
                    $errors["conditions.{$i}"] = 'Value: choose “at least” or “at most” and an amount in rupees.';

                    continue;
                }

                $conditions[] = ['field' => 'value', 'op' => $op, 'value' => trim((string) $value)];

                continue;
            }

            $list = array_values(array_unique(array_filter(array_map('strval', (array) $value), fn ($x) => $x !== '')));
            $allowed = match ($field) {
                'stage' => array_map(fn (LeadStage $s) => $s->value, LeadStage::cases()),
                'owner' => array_map('strval', array_keys($people)),
                'branch' => Location::query()->pluck('id')->map(fn ($id) => (string) $id)->all(),
                default => LeadSource::query()->pluck('id')->map(fn ($id) => (string) $id)->all(),
            };

            if ($list === [] || array_diff($list, $allowed) !== []) {
                $errors["conditions.{$i}"] = self::FIELDS[$field].': choose one or more from the list.';

                continue;
            }

            $conditions[] = ['field' => $field, 'op' => 'in', 'value' => $field === 'stage' ? $list : array_map('intval', $list)];
        }

        $actions = [];
        $rawActions = array_values(array_filter((array) ($raw['actions'] ?? []), 'is_array'));
        $rawActions === [] && $errors['actions'] = 'Add at least one action.';
        count($rawActions) > self::MAX_ACTIONS && $errors['actions'] = 'At most '.self::MAX_ACTIONS.' actions.';

        foreach (array_slice($rawActions, 0, self::MAX_ACTIONS) as $i => $a) {
            $type = (string) ($a['type'] ?? '');
            $text = fn (string $k, int $max): string => mb_substr(trim((string) ($a[$k] ?? '')), 0, $max);
            $assignee = (string) ($a['assignee'] ?? 'owner');
            $hours = fn (string $k, int $default): int => is_numeric($a[$k] ?? null) && (int) $a[$k] >= 0 && (int) $a[$k] <= 8760 ? (int) $a[$k] : $default;

            if (! array_key_exists($type, self::ACTIONS)) {
                $errors["actions.{$i}"] = 'Choose what to do.';

                continue;
            }

            if (in_array($type, self::LEAD_ONLY, true) && $meta['subject'] !== 'lead') {
                $errors["actions.{$i}"] = 'Assigning a lead only makes sense when the trigger is about a lead.';

                continue;
            }

            if (in_array($type, ['create_task', 'schedule_reminder'], true)) {
                $title = $text('title', 200);
                $okAssignee = in_array($assignee, ['owner', 'manager'], true) || (preg_match('/^user:(\d+)$/', $assignee, $m) === 1 && array_key_exists((int) $m[1], $people));

                if ($title === '' || ! $okAssignee) {
                    $errors["actions.{$i}"] = $title === '' ? 'Say what the task is.' : 'Give it to the owner, the owner’s manager, or someone in your reach.';

                    continue;
                }

                $priority = array_key_exists((string) ($a['priority'] ?? 'normal'), TaskPriority::options()) ? (string) ($a['priority'] ?? 'normal') : 'normal';
                $actions[] = $type === 'create_task'
                    ? ['type' => $type, 'title' => $title, 'assignee' => $assignee, 'due_hours' => max(1, $hours('due_hours', 24)), 'priority' => $priority]
                    : ['type' => $type, 'title' => $title, 'assignee' => $assignee, 'hours' => max(1, $hours('hours', 24)), 'priority' => $priority];

                continue;
            }

            if ($type === 'notify_manager') {
                $message = $text('message', 300);
                $message === '' ? $errors["actions.{$i}"] = 'Write the message to the manager.' : $actions[] = ['type' => $type, 'message' => $message];

                continue;
            }

            if ($type === 'assign_lead') {
                $to = (string) ($a['user_id'] ?? '');

                if (! ctype_digit($to) || ! array_key_exists((int) $to, $people)) {
                    $errors["actions.{$i}"] = 'Assign to yourself or someone on a team you manage.';

                    continue;
                }

                $actions[] = ['type' => $type, 'user_id' => (int) $to, 'reason' => $text('reason', 200) ?: 'Assigned by a workflow'];

                continue;
            }

            // send_message
            $template = ctype_digit((string) ($a['template_id'] ?? '')) ? MessageTemplate::query()->find((int) $a['template_id']) : null;

            if ($template === null) {
                $errors["actions.{$i}"] = 'Choose a message template.';

                continue;
            }

            $actions[] = ['type' => $type, 'template_id' => (int) $template->getKey()];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['params' => $params, 'conditions' => $conditions, 'actions' => $actions];
    }

    /**
     * A sentence for each part, for the screens.
     *
     * @param  array<string, mixed>  $c
     */
    public function describeCondition(array $c): string
    {
        $label = self::FIELDS[$c['field']] ?? $c['field'];

        return match ($c['op']) {
            'gte' => "{$label} is at least ₹ {$c['value']}",
            'lte' => "{$label} is at most ₹ {$c['value']}",
            default => "{$label} is one of: ".implode(', ', array_map('strval', (array) $c['value'])),
        };
    }
}
