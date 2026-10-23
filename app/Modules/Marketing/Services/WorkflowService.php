<?php

namespace App\Modules\Marketing\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Marketing\Models\Workflow;
use App\Modules\Marketing\Models\WorkflowVersion;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Workflow data has one door. Saving never edits a definition: it writes a **new version** (the old ones stay, and a run remembers the version it ran
 * with). The person who saves is the person the workflow **runs as** — so everything it does is checked against what they may do — and what they
 * save is checked against the same limits as everything else (`WorkflowDefinition`): only the listed triggers, conditions and actions, only people in
 * their reach, only approved templates when it runs.
 *
 * A workflow is made as a draft; switching it on (`activate`) starts it **from that moment** — what happened before is never run — and so does resuming
 * it after a pause (what happened while it was paused is not run either). `dry_run` makes it record what it would do without doing it.
 */
final class WorkflowService
{
    public function __construct(private readonly WorkflowDefinition $definitions, private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $input  name, description, trigger, params, conditions, actions
     *
     * @throws ValidationException
     */
    public function create(array $input, User $actor): Workflow
    {
        [$head, $definition] = $this->check($input, $actor);

        return DB::transaction(function () use ($head, $definition, $actor) {
            $workflow = new Workflow;
            $workflow->fill(['name' => $head['name'], 'description' => $head['description']]);
            $workflow->forceFill([
                'company_id' => (int) $this->tenant->activeCompanyId(), 'trigger' => $head['trigger'], 'state' => 'draft', 'run_as' => $actor->getKey(),
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ])->save();

            $this->newVersion($workflow, $definition, $actor);

            return $workflow->refresh();
        });
    }

    /**
     * Save a change as a new version. The trigger cannot change (a different trigger is a different workflow).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(Workflow $workflow, array $input, User $actor): Workflow
    {
        $input['trigger'] = $workflow->trigger;
        [$head, $definition] = $this->check($input, $actor);

        return DB::transaction(function () use ($workflow, $head, $definition, $actor) {
            $fresh = Workflow::query()->whereKey($workflow->getKey())->lockForUpdate()->firstOrFail();
            $fresh->fill(['name' => $head['name'], 'description' => $head['description']]);
            $fresh->forceFill(['run_as' => $actor->getKey(), 'updated_by' => $actor->getKey()])->save();
            $this->newVersion($fresh, $definition, $actor);

            return $fresh->refresh();
        });
    }

    /** @throws FieldRuleException */
    public function activate(Workflow $workflow, User $actor): Workflow
    {
        return $this->move($workflow, $actor, ['draft'], 'active', 'Only a draft can be switched on; use Resume for a paused one.', true);
    }

    /** @throws FieldRuleException */
    public function pause(Workflow $workflow, User $actor): Workflow
    {
        return $this->move($workflow, $actor, ['active'], 'paused', 'Only a workflow that is on can be paused.', false);
    }

    /** @throws FieldRuleException */
    public function resume(Workflow $workflow, User $actor): Workflow
    {
        return $this->move($workflow, $actor, ['paused'], 'active', 'Only a paused workflow can be resumed.', true);
    }

    public function setDryRun(Workflow $workflow, bool $dry, User $actor): Workflow
    {
        $workflow->forceFill(['dry_run' => $dry, 'updated_by' => $actor->getKey()])->save();
        activity('workflows')->causedBy($actor)->performedOn($workflow)->event($dry ? 'dry_run_on' : 'dry_run_off')->log($dry ? 'Workflow set to record without acting' : 'Workflow set to act');

        return $workflow->refresh();
    }

    // ------------------------------------------------------------------------------------------------------------------------------

    /** @param list<string> $from */
    private function move(Workflow $workflow, User $actor, array $from, string $to, string $message, bool $restart): Workflow
    {
        return DB::transaction(function () use ($workflow, $actor, $from, $to, $message, $restart) {
            $fresh = Workflow::query()->whereKey($workflow->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($fresh->state, $from, true)) {
                throw new FieldRuleException($message);
            }

            if ($to === 'active' && $fresh->current_version_id === null) {
                throw new FieldRuleException('This workflow has no saved definition to run.');
            }

            // it starts from now: what happened while it was off is not run afterwards
            $fresh->forceFill(['state' => $to, 'updated_by' => $actor->getKey()] + ($restart ? ['activated_at' => now()] : []))->save();
            activity('workflows')->causedBy($actor)->performedOn($fresh)->event($to === 'active' ? ($from === ['paused'] ? 'resumed' : 'activated') : 'paused')->log('Workflow '.$to);

            return $fresh->refresh();
        });
    }

    /** @param array<string, mixed> $definition */
    private function newVersion(Workflow $workflow, array $definition, User $actor): WorkflowVersion
    {
        $next = (int) WorkflowVersion::query()->where('workflow_id', $workflow->getKey())->max('version') + 1;

        $version = new WorkflowVersion;
        $version->forceFill(['company_id' => $workflow->company_id, 'workflow_id' => $workflow->getKey(), 'version' => $next, 'definition' => $definition, 'saved_by' => $actor->getKey()])->save();
        $workflow->forceFill(['current_version_id' => $version->getKey()])->save();

        return $version;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array{name: string, description: string|null, trigger: string}, 1: array<string, mixed>}
     *
     * @throws ValidationException
     */
    private function check(array $input, User $actor): array
    {
        $v = Validator::make([
            'name' => is_scalar($input['name'] ?? null) ? trim((string) $input['name']) : '', 'description' => is_scalar($input['description'] ?? null) && trim((string) $input['description']) !== '' ? trim((string) $input['description']) : null,
            'trigger' => (string) ($input['trigger'] ?? ''),
        ], [
            'name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:2000'], 'trigger' => ['required', Rule::in(array_keys(WorkflowDefinition::TRIGGERS))],
        ], ['name.required' => 'Give the workflow a name.', 'trigger.required' => 'Choose what starts the workflow.', 'trigger.in' => 'Choose what starts the workflow.'])->validate();

        return [$v, $this->definitions->clean($v['trigger'], $input, $actor)];
    }
}
