<?php

namespace App\Modules\Marketing\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Services\LeadTransferService;
use App\Modules\Marketing\Models\MessageTemplate;
use App\Modules\Marketing\Models\Workflow;
use App\Modules\Marketing\Models\WorkflowRun;
use App\Modules\Marketing\Models\WorkflowVersion;
use App\Modules\Marketing\Notifications\WorkflowNotification;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use App\Modules\Workspace\Services\TaskService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs the workflows that are on, on a schedule (`automation:run`). For each, it finds the occurrences of its trigger since it was switched on, and for
 * each one, once:
 *
 *  1. **claims** it by writing the run (`unique(workflow_id, trigger_key)` — the same event never runs the same workflow twice, however many sweeps overlap);
 *  2. checks the **limits** (a workflow's runs per hour, and per record per day), then the **conditions** (a run whose conditions fail is recorded as skipped);
 *  3. does the **actions**, as the person the workflow runs as, with the same checks as if that person did them by hand: they must still be active and hold
 *     the rights, must be able to see the record, and every action goes through the service the screens use (tasks, lead transfers, consent-checked messages).
 *     A workflow can never approve a document, skip consent, or reach another company.
 *
 * In dry-run the actions are only described. A failed run can be retried: the actions that worked are not done again.
 * Nothing a workflow does is itself a trigger (no action creates a lead, changes a stage, approves anything), so workflows cannot set each other off.
 */
final class WorkflowEngine
{
    /** A run that has been "running" this long never finished. */
    private const STUCK_MINUTES = 10;

    private const PER_RECORD_PER_DAY = 3;

    private const PER_SWEEP = 100;

    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly SubjectFacts $facts,
        private readonly TenantContext $tenant,
        private readonly TaskService $tasks,
        private readonly CommunicationService $messages,
        private readonly TeamDirectory $teams,
        private readonly LeadTransferService $transfers,
        private readonly TemplateRenderer $renderer,
    ) {}

    /** @return array{ok: int, failed: int, skipped: int, dry: int, limited: int} */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $out = ['ok' => 0, 'failed' => 0, 'skipped' => 0, 'dry' => 0, 'limited' => 0];

        $this->tenant->withoutTenancy(function () use ($now, &$out) {
            WorkflowRun::withoutGlobalScopes(Tenancy::SCOPES)->where('status', 'running')->where('updated_at', '<', $now->copy()->subMinutes(self::STUCK_MINUTES))
                ->update(['status' => 'failed', 'reason' => 'The run did not finish.']);

            foreach (Workflow::withoutGlobalScopes(Tenancy::SCOPES)->where('state', 'active')->whereNotNull('current_version_id')->orderBy('id')->get() as $workflow) {
                $version = WorkflowVersion::withoutGlobalScopes(Tenancy::SCOPES)->find($workflow->current_version_id);
                $runAs = User::query()->find($workflow->run_as);

                if ($version === null || $workflow->activated_at === null) {
                    continue;
                }

                $found = $this->facts->occurrences($workflow->trigger, (int) $workflow->company_id, $workflow->activated_at, $version->definition['params'] ?? [], 500);
                $known = $found === [] ? [] : WorkflowRun::withoutGlobalScopes(Tenancy::SCOPES)->where('workflow_id', $workflow->getKey())->whereIn('trigger_key', array_column($found, 0))->pluck('trigger_key')->all();
                $fresh = array_values(array_filter($found, fn (array $o) => ! in_array($o[0], $known, true)));
                $room = $workflow->max_runs_per_hour - WorkflowRun::withoutGlobalScopes(Tenancy::SCOPES)->where('workflow_id', $workflow->getKey())->where('created_at', '>=', $now->copy()->subHour())->count();

                foreach (array_slice($fresh, 0, self::PER_SWEEP) as $occurrence) {
                    if ($room-- <= 0) {
                        $out['limited']++;

                        break;   // the rest wait for the next sweep: nothing is recorded, so nothing is lost
                    }

                    $status = $this->process($workflow, $version, $runAs, $occurrence, $now);
                    $key = ['ok' => 'ok', 'failed' => 'failed', 'skipped' => 'skipped', 'dry_run' => 'dry'][$status] ?? null;
                    $key === null || $out[$key]++;
                }
            }
        });

        return $out;
    }

    /**
     * What a workflow would do with one record, without doing it (the “test” on the workflow's page).
     *
     * @param  array<string, mixed>  $definition
     * @return array{found: bool, matches: bool, failed: list<string>, would: list<string>, facts: array<string, mixed>|null}
     */
    public function preview(Workflow $workflow, array $definition, string $subjectType, int $subjectId, User $viewer): array
    {
        $facts = $this->facts->load($subjectType, $subjectId, (int) $workflow->company_id);

        if ($facts === null || ! $this->canSee($viewer, $facts)) {
            return ['found' => false, 'matches' => false, 'failed' => [], 'would' => [], 'facts' => null];
        }

        [$matches, $failed] = $this->evaluate($definition['conditions'] ?? [], $facts);
        $would = $matches ? array_map(fn (array $a) => $this->describeAction($a, $facts), $definition['actions'] ?? []) : [];

        return ['found' => true, 'matches' => $matches, 'failed' => $failed, 'would' => $would, 'facts' => $facts];
    }

    /**
     * Some recent records the trigger would have fired for, so the author can test against real ones (shown only if the viewer can open them).
     *
     * @return list<array{type: string, id: int, label: string}>
     */
    public function samples(Workflow $workflow, User $viewer, int $limit = 8): array
    {
        $version = $workflow->current;
        $out = [];

        foreach (array_reverse($this->facts->occurrences($workflow->trigger, (int) $workflow->company_id, now()->subDays(60), $version?->definition['params'] ?? [], 60, true)) as [, $type, $id]) {
            $facts = $this->facts->load($type, $id, (int) $workflow->company_id);

            if ($facts !== null && $this->canSee($viewer, $facts) && ! in_array($type.$id, array_map(fn ($o) => $o['type'].$o['id'], $out), true)) {
                $out[] = ['type' => $type, 'id' => $id, 'label' => $facts['number'].' · '.$facts['name']];
            }

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** Try a failed run again: the actions that worked are not repeated. */
    public function retry(WorkflowRun $run, User $by): WorkflowRun
    {
        $claimed = WorkflowRun::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($run->getKey())->where('status', 'failed')->where('attempts', '<', self::MAX_ATTEMPTS)
            ->update(['status' => 'running', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($claimed !== 1) {
            throw new FieldRuleException('Only a failed run can be tried again, and only a few times.');
        }

        $fresh = WorkflowRun::withoutGlobalScopes(Tenancy::SCOPES)->findOrFail($run->getKey());
        $workflow = Workflow::withoutGlobalScopes(Tenancy::SCOPES)->findOrFail($fresh->workflow_id);
        $version = WorkflowVersion::withoutGlobalScopes(Tenancy::SCOPES)->findOrFail($fresh->workflow_version_id);

        $this->tenant->withCompany((int) $workflow->company_id, fn () => $this->execute($workflow, $version, $fresh, User::query()->find($workflow->run_as), $workflow->dry_run, now()));

        activity('workflows')->causedBy($by)->performedOn($workflow)->event('retried')->withProperties(['run' => $fresh->getKey()])->log('A failed workflow run was tried again');

        return $fresh->refresh();
    }

    // ------------------------------------------------------------------------------------------------------------------------------

    /** @param array{0: string, 1: string, 2: int} $occurrence */
    private function process(Workflow $workflow, WorkflowVersion $version, ?User $runAs, array $occurrence, Carbon $now): string
    {
        [$key, $type, $id] = $occurrence;

        $run = new WorkflowRun;
        $run->forceFill([
            'company_id' => $workflow->company_id, 'workflow_id' => $workflow->getKey(), 'workflow_version_id' => $version->getKey(), 'trigger_key' => $key,
            'subject_type' => $type, 'subject_id' => $id, 'status' => 'running', 'attempts' => 1, 'executed_at' => $now,
        ]);

        try {
            $run->save();
        } catch (UniqueConstraintViolationException) {
            return 'claimed';   // another sweep got there first
        }

        $recent = WorkflowRun::withoutGlobalScopes(Tenancy::SCOPES)->where('workflow_id', $workflow->getKey())->where('subject_type', $type)->where('subject_id', $id)
            ->whereIn('status', ['ok', 'failed', 'dry_run'])->where('created_at', '>=', $now->copy()->subDay())->count();

        if ($recent >= self::PER_RECORD_PER_DAY) {
            return $this->finish($run, 'skipped', 'This record has already set this workflow off '.self::PER_RECORD_PER_DAY.' times today.', []);
        }

        return $this->tenant->withCompany((int) $workflow->company_id, fn () => $this->execute($workflow, $version, $run, $runAs, $workflow->dry_run, $now));
    }

    /** @return string the status the run ended in */
    private function execute(Workflow $workflow, WorkflowVersion $version, WorkflowRun $run, ?User $runAs, bool $dry, Carbon $now): string
    {
        $facts = $this->facts->load($run->subject_type, (int) $run->subject_id, (int) $workflow->company_id);

        if ($facts === null) {
            return $this->finish($run, 'skipped', 'The record is no longer there.', []);
        }

        [$matches, $failed] = $this->evaluate($version->definition['conditions'] ?? [], $facts);

        if (! $matches) {
            return $this->finish($run, 'skipped', 'Conditions not met: '.implode('; ', $failed), []);
        }

        if ($runAs === null || ! $runAs->is_active || ! $runAs->can('workflow.edit') || ! $this->tenant->userCanAccessCompany($runAs, (int) $workflow->company_id)) {
            return $this->finish($run, 'failed', 'The person this workflow runs as can no longer run it (inactive, or without the right). Save it again as someone who can.', []);
        }

        if (! $this->canSee($runAs, $facts)) {
            return $this->finish($run, 'failed', 'The person this workflow runs as cannot see this record, so nothing was done.', []);
        }

        $previous = collect($run->results ?? [])->keyBy('index');
        $results = [];
        $bad = false;
        $before = Auth::user();

        try {
            Auth::setUser($runAs);
            $this->tenant->flush();

            foreach ($version->definition['actions'] ?? [] as $index => $action) {
                if (($previous->get($index)['ok'] ?? false) === true) {
                    $results[] = $previous->get($index);   // done on an earlier attempt: not done again

                    continue;
                }

                try {
                    $note = $dry ? 'Would: '.$this->describeAction($action, $facts) : $this->act($action, $facts, $runAs, $workflow, $run, $index);
                    $results[] = ['index' => $index, 'type' => $action['type'], 'ok' => true, 'note' => $note];
                } catch (FieldRuleException|ValidationException $e) {
                    $bad = true;
                    $results[] = ['index' => $index, 'type' => $action['type'], 'ok' => false, 'note' => $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage()];
                } catch (Throwable $e) {
                    report($e);
                    $bad = true;
                    $results[] = ['index' => $index, 'type' => $action['type'], 'ok' => false, 'note' => 'This action could not be done.'];
                }
            }
        } finally {
            $before === null ? Auth::forgetGuards() : Auth::setUser($before);
            $this->tenant->flush();
        }

        return $this->finish($run, $bad ? 'failed' : ($dry ? 'dry_run' : 'ok'), $bad ? 'An action failed; the ones that worked are not repeated if you try again.' : null, $results);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $f
     */
    private function act(array $action, array $f, User $user, Workflow $workflow, WorkflowRun $run, int $index): string
    {
        $companyId = (int) $workflow->company_id;
        $values = ['name' => (string) $f['name'], 'first_name' => (string) (preg_split('/\s+/', trim((string) $f['name']))[0] ?? ''), 'reference' => (string) $f['number'], 'company' => (string) Company::withoutGlobalScopes()->whereKey($companyId)->value('name')];

        switch ($action['type']) {
            case 'create_task':
            case 'schedule_reminder':
                $assignee = $this->assignee($action['assignee'], $f, $companyId);
                $hours = (int) ($action['due_hours'] ?? $action['hours']);
                $task = $this->tasks->create([
                    'title' => $this->renderer->render(null, $action['title'], $values)['body'], 'priority' => $action['priority'] ?? 'normal',
                    'due_at' => now()->addHours($hours)->format('Y-m-d H:i:s'), 'remind_at' => $action['type'] === 'schedule_reminder' ? now()->addHours($hours)->subMinutes(15)->format('Y-m-d H:i:s') : null,
                    'assigned_to' => (string) $assignee, 'subject_type' => $f['task_type'], 'subject_id' => (string) $f['id'],
                ], $user);

                return "Task “{$task->title}” made for ".(User::query()->find($assignee)->name ?? 'someone').'.';

            case 'notify_manager':
                $manager = $f['owner_id'] === null ? null : $this->teams->managerOf((int) $f['owner_id'], $companyId);

                if ($manager === null) {
                    return 'Nobody to tell: the owner has no manager.';
                }

                $manager->notify(new WorkflowNotification($f['number'].' — '.$f['name'], $this->renderer->render(null, $action['message'], $values)['body'], $workflow->name, $f['url']));

                return 'Told '.$manager->name.'.';

            case 'assign_lead':
                $user->can('lead.assign') || throw new FieldRuleException('The person this workflow runs as may not hand leads over.');
                $f['type'] === 'lead' || throw new FieldRuleException('Only a lead can be assigned.');
                $f['owner_id'] === (int) $action['user_id'] && throw new FieldRuleException('The lead is already with that person.');
                $this->transfers->transfer([$f['id']], (int) $action['user_id'], $action['reason'], $user);

                return 'Lead handed to '.(User::query()->find((int) $action['user_id'])->name ?? 'someone').'.';

            default:   // send_message
                $user->can('message_template.view') || throw new FieldRuleException('The person this workflow runs as may not use message templates.');
                $template = MessageTemplate::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->find($action['template_id']) ?? throw new FieldRuleException('The template is gone.');
                $message = $this->messages->queue($template, [
                    'name' => (string) $f['name'], 'address' => $template->channel->value === 'email' ? $f['email'] : $f['mobile'],
                    'subject_type' => $f['lead_id'] === null ? null : 'lead', 'subject_id' => $f['lead_id'],
                    'values' => ['reference' => (string) $f['number'], 'owner_name' => (string) ($f['owner_id'] === null ? '' : User::query()->find($f['owner_id'])?->name)],
                ], "w{$run->getKey()}:a{$index}", null, null, (int) $run->getKey(), $user);

                return 'Message “'.$template->name.'”: '.strtolower($message->statusLabel()).($message->failure ? ' ('.$message->failure.')' : '').'.';
        }
    }

    /** @param array<string, mixed> $f */
    private function assignee(string $who, array $f, int $companyId): int
    {
        if ($who === 'owner') {
            return $f['owner_id'] ?? throw new FieldRuleException('The record has no owner to give the task to.');
        }

        if ($who === 'manager') {
            $manager = $f['owner_id'] === null ? null : $this->teams->managerOf((int) $f['owner_id'], $companyId);

            return $manager === null ? throw new FieldRuleException('The owner has no manager to give the task to.') : (int) $manager->getKey();
        }

        return (int) substr($who, 5);   // "user:ID", already checked when it was saved; TaskService checks it again
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     * @param  array<string, mixed>  $f
     * @return array{0: bool, 1: list<string>}
     */
    private function evaluate(array $conditions, array $f): array
    {
        $failed = [];

        foreach ($conditions as $c) {
            $have = ['source' => $f['source_id'], 'stage' => $f['stage'], 'branch' => $f['branch'], 'owner' => $f['owner_id'], 'value' => $f['value']][$c['field']] ?? null;
            $ok = match ($c['op']) {
                'gte' => $have !== null && bccomp((string) $have, (string) $c['value'], 2) >= 0,
                'lte' => $have !== null && bccomp((string) $have, (string) $c['value'], 2) <= 0,
                default => $have !== null && in_array($have, (array) $c['value'], false),
            };
            $ok || $failed[] = (new WorkflowDefinition)->describeCondition($c);
        }

        return [$failed === [], $failed];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $f
     */
    private function describeAction(array $a, array $f): string
    {
        return match ($a['type']) {
            'create_task' => "make a task “{$a['title']}” for the {$a['assignee']}, due in {$a['due_hours']} hours",
            'schedule_reminder' => "remind the {$a['assignee']} in {$a['hours']} hours: “{$a['title']}”",
            'notify_manager' => 'tell the owner’s manager: “'.$a['message'].'”',
            'assign_lead' => 'hand the lead to '.(User::query()->find($a['user_id'])->name ?? 'someone'),
            default => 'queue the template “'.(MessageTemplate::withoutGlobalScopes(Tenancy::SCOPES)->find($a['template_id'])->name ?? 'gone').'” to '.$f['name'].' (if the template is approved and consent allows it)',
        };
    }

    /** @param array<string, mixed> $f */
    private function canSee(User $user, array $f): bool
    {
        $model = $this->facts->model($f['type'], (int) $f['id'], (int) $f['company_id']);

        return $model !== null && Gate::forUser($user)->allows('view', $model);
    }

    /** @param list<array<string, mixed>> $results */
    private function finish(WorkflowRun $run, string $status, ?string $reason, array $results): string
    {
        $run->forceFill(['status' => $status, 'reason' => $reason === null ? null : mb_substr($reason, 0, 300), 'results' => $results, 'executed_at' => now()])->save();

        return $status;
    }
}
