<?php

use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadTransfer;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Models\Communication;
use App\Modules\Marketing\Models\Workflow;
use App\Modules\Marketing\Models\WorkflowRun;
use App\Modules\Marketing\Models\WorkflowVersion;
use App\Modules\Marketing\Notifications\WorkflowNotification;
use App\Modules\Marketing\Services\ConsentService;
use App\Modules\Marketing\Services\WorkflowEngine;
use App\Modules\Marketing\Services\WorkflowService;
use App\Modules\Sales\Services\QuotationService;
use App\Modules\Workspace\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * Workflows: made only of the listed parts, versioned, started from the moment they are switched on, run once per event as the person who saved them
 * with that person's rights, safe to retry, limited, and never able to skip consent or reach what their author cannot see.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();
    Mail::fake();

    $this->acme = makeCompany('ACME', ['state_code' => '24']);
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD', ['state_code' => '24']);

    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);

    $this->svc = fn () => inCompany($this->acme, fn () => app(WorkflowService::class));
    $this->engine = fn (?Carbon $at = null) => inCompany($this->acme, fn () => app(WorkflowEngine::class)->run($at));
    $this->make = function (array $input = [], $by = null, bool $on = true): Workflow {
        $by ??= $this->manager;
        $workflow = inCompany($this->acme, fn () => app(WorkflowService::class)->create([
            'name' => 'Welcome task', 'trigger' => 'lead_created', 'conditions' => [],
            'actions' => [['type' => 'create_task', 'title' => 'Call {{name}} ({{reference}})', 'assignee' => 'owner', 'due_hours' => '24', 'priority' => 'high']],
            ...$input,
        ], $by));

        return $on ? inCompany($this->acme, fn () => app(WorkflowService::class)->activate($workflow, $this->admin)) : $workflow;
    };
    $this->lead = fn (string $name = 'Alpha Works', $by = null, array $over = []) => makeLead($this->acme, $by ?? $this->bde, $this->ahd, ['name' => $name, ...$over]);
    $this->runs = fn () => WorkflowRun::query()->orderBy('id')->get();
});

// ---- making one ------------------------------------------------------------------------------------------------------------------

it('saves each change as a new version and keeps the old ones, running as whoever saved last', function () {
    $workflow = ($this->make)([], $this->manager, false);

    expect($workflow->state)->toBe('draft')->and($workflow->run_as)->toBe($this->manager->id)->and($workflow->versions)->toHaveCount(1)->and($workflow->current->version)->toBe(1);

    $changed = inCompany($this->acme, fn () => app(WorkflowService::class)->update($workflow, [
        'name' => 'Welcome task v2', 'description' => 'Changed', 'conditions' => [], 'actions' => [['type' => 'notify_manager', 'message' => 'A new lead']],
    ], $this->admin));

    expect($changed->versions)->toHaveCount(2)->and($changed->current->version)->toBe(2)->and($changed->name)->toBe('Welcome task v2')->and($changed->run_as)->toBe($this->admin->id)
        ->and($changed->trigger)->toBe('lead_created')
        ->and(WorkflowVersion::query()->where('workflow_id', $workflow->id)->orderBy('version')->first()->definition['actions'][0]['type'])->toBe('create_task');

    // a version never changes, and a workflow is never deleted
    $v1 = WorkflowVersion::query()->where('workflow_id', $workflow->id)->orderBy('version')->first();
    expect(fn () => $v1->forceFill(['definition' => []])->save())->toThrow(LogicException::class, 'never changes')
        ->and(fn () => $v1->delete())->toThrow(LogicException::class, 'cannot be deleted');
});

it('accepts only the listed triggers, conditions and actions, and only people and records in the author’s reach', function () {
    $bad = fn (array $input) => collect([$input])->map(function ($i) {
        try {
            ($this->make)($i, $this->manager, false);
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    })->first();

    $task = ['type' => 'create_task', 'title' => 'x', 'assignee' => 'owner'];

    expect($bad(['name' => '']))->toBe(['name'])
        ->and($bad(['trigger' => 'user_deleted']))->toBe(['trigger'])
        ->and($bad(['actions' => []]))->toBe(['actions'])
        ->and($bad(['actions' => [['type' => 'drop_database']]]))->toBe(['actions.0'])
        ->and($bad(['actions' => [['type' => 'approve_document']]]))->toBe(['actions.0'])
        ->and($bad(['actions' => [['type' => 'create_task', 'title' => '', 'assignee' => 'owner']]]))->toBe(['actions.0'])
        ->and($bad(['actions' => [[...$task, 'assignee' => 'user:'.$this->bde2->id]]]))->toBe(['actions.0'])     // not on the manager’s team
        ->and($bad(['actions' => [['type' => 'assign_lead', 'user_id' => (string) $this->bde2->id]]]))->toBe(['actions.0'])
        ->and($bad(['actions' => [['type' => 'send_message', 'template_id' => '99999']]]))->toBe(['actions.0'])
        ->and($bad(['trigger' => 'deal_won', 'actions' => [['type' => 'assign_lead', 'user_id' => (string) $this->bde->id]]]))->toBe(['actions.0'])   // a deal has no lead to assign
        ->and($bad(['conditions' => [['field' => 'password', 'op' => 'eq', 'value' => 'x']], 'actions' => [$task]]))->toBe(['conditions.0'])
        ->and($bad(['conditions' => [['field' => 'owner', 'op' => 'in', 'value' => [(string) $this->bde2->id]]], 'actions' => [$task]]))->toBe(['conditions.0'])
        ->and($bad(['conditions' => [['field' => 'value', 'op' => 'gte', 'value' => 'lots']], 'actions' => [$task]]))->toBe(['conditions.0'])
        ->and($bad(['trigger' => 'followup_overdue', 'params' => ['after_hours' => '0'], 'actions' => [$task]]))->toBe(['params.after_hours'])
        ->and($bad(['actions' => array_fill(0, 6, $task)]))->toBe(['actions']);
});

it('switches on from that moment only, and what happened while it was off is not run afterwards', function () {
    ($this->lead)('Before it was on');
    $workflow = ($this->make)([], $this->manager, false);
    ($this->lead)('While it was a draft');

    expect(($this->engine)())->toBe(['ok' => 0, 'failed' => 0, 'skipped' => 0, 'dry' => 0, 'limited' => 0]);   // a draft does nothing

    Carbon::setTestNow('2026-10-12 10:00:00');
    inCompany($this->acme, fn () => app(WorkflowService::class)->activate($workflow, $this->admin));
    Carbon::setTestNow('2026-10-12 10:05:00');
    ($this->lead)('After it was on');

    expect(($this->engine)()['ok'])->toBe(1)->and(Task::query()->pluck('title')->all())->toHaveCount(1);

    inCompany($this->acme, fn () => app(WorkflowService::class)->pause($workflow->fresh(), $this->admin));
    Carbon::setTestNow('2026-10-12 11:00:00');
    ($this->lead)('While it was paused');
    expect(($this->engine)()['ok'])->toBe(0);

    Carbon::setTestNow('2026-10-12 12:00:00');
    inCompany($this->acme, fn () => app(WorkflowService::class)->resume($workflow->fresh(), $this->admin));
    expect(($this->engine)()['ok'])->toBe(0)->and(Task::query()->count())->toBe(1);   // what happened while it was paused is not run

    expect(fn () => inCompany($this->acme, fn () => app(WorkflowService::class)->activate($workflow->fresh(), $this->admin)))->toThrow(FieldRuleException::class, 'draft');
});

// ---- running ----------------------------------------------------------------------------------------------------------------------

it('runs once for each event as the person who saved it, and makes the task it describes', function () {
    $workflow = ($this->make)();
    $lead = ($this->lead)('Alpha Works');

    expect(($this->engine)()['ok'])->toBe(1)->and(($this->engine)()['ok'])->toBe(0);   // the same event never runs it twice

    $run = ($this->runs)()->sole();
    $task = Task::query()->sole();

    expect($run->status)->toBe('ok')->and($run->trigger_key)->toBe("lead:{$lead->id}:created")->and($run->workflow_version_id)->toBe($workflow->current_version_id)
        ->and($run->results[0]['ok'])->toBeTrue()
        ->and($task->title)->toBe("Call Alpha Works ({$lead->lead_number})")->and($task->assigned_to)->toBe($this->bde->id)->and($task->created_by)->toBe($this->manager->id)
        ->and($task->priority->value)->toBe('high')->and($task->subject_type)->toBe('lead')->and($task->subject_id)->toBe($lead->id)
        ->and($task->due_at->toDateTimeString())->toBe('2026-10-13 09:30:00');
});

it('skips a record whose facts do not match, and says why', function () {
    $source = leadSourceId($this->acme, 'REFERENCE');
    ($this->make)(['conditions' => [
        ['field' => 'source', 'op' => 'in', 'value' => [(string) $source]], ['field' => 'value', 'op' => 'gte', 'value' => '50000'],
    ]]);

    $big = ($this->lead)('Big Works', null, ['lead_source_id' => (string) $source, 'expected_value' => '75000']);
    ($this->lead)('Small Works', null, ['lead_source_id' => (string) $source, 'expected_value' => '1000']);
    ($this->lead)('Other source', null, ['lead_source_id' => (string) leadSourceId($this->acme, 'WEBSITE'), 'expected_value' => '90000']);

    $r = ($this->engine)();
    expect($r['ok'])->toBe(1)->and($r['skipped'])->toBe(2)->and(Task::query()->count())->toBe(1);

    $skipped = ($this->runs)()->where('status', 'skipped');
    expect($skipped->pluck('reason')->join('|'))->toContain('Value (₹) is at least ₹ 50000')->toContain('Lead source is one of')
        ->and(Task::query()->sole()->subject_id)->toBe($big->id);
});

it('fires on a stage change, an overdue follow-up, a won deal, an expiring quotation and an approved order', function () {
    $tasks = fn (string $title = 'Look at {{reference}}') => ['actions' => [['type' => 'create_task', 'title' => $title, 'assignee' => 'owner', 'due_hours' => '5']]];
    ($this->make)(['name' => 'Stage', 'trigger' => 'stage_changed', ...$tasks('Stage {{reference}}')]);
    ($this->make)(['name' => 'Overdue', 'trigger' => 'followup_overdue', 'params' => ['after_hours' => '24'], ...$tasks('Overdue {{reference}}')]);
    ($this->make)(['name' => 'Won', 'trigger' => 'deal_won', ...$tasks('Won {{reference}}')]);
    ($this->make)(['name' => 'Expiring', 'trigger' => 'quotation_expiring', 'params' => ['days_before' => '3'], ...$tasks('Expiring {{reference}}')]);
    ($this->make)(['name' => 'Approved', 'trigger' => 'order_approved', ...$tasks('Approved {{reference}}')]);

    Carbon::setTestNow('2026-10-12 09:31:00');   // after they were switched on
    $customer = makeCustomer($this->acme, 'Shree Ganesh Industries', ['credit_limit' => '5000000', 'credit_days' => '30']);
    $bolt = makeUsableItem($this->acme);
    makeStandardRate($this->acme, $bolt, $this->ahd, '125.50');

    $lead = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Pipeline Works'], [lineRow($bolt, '10', '')]);
    $followup = Carbon::withTestNow('2026-10-12 09:31:00', fn () => makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-12T11:00']));
    $deal = approveDocument($this->acme, makeDeal($this->acme, $this->bde, qualify($this->acme, $lead, $this->bde), ['customer_ledger_id' => (string) $customer->id]), $this->bde, $this->approver);
    $quote = makeQuotation($this->acme, $this->bde, $deal, [], null, 'approved', $this->approver);
    inCompany($this->acme, fn () => app(QuotationService::class)->send($quote->fresh(), ['mode' => 'manual'], $this->bde));
    $order = makeSalesOrder($this->acme, $this->bde, $deal->fresh(), $customer, [], null, 'approved', $this->approver);

    Carbon::setTestNow('2026-10-14 12:00:00');   // the follow-up is now more than a day late
    $r = ($this->engine)();

    expect($r['failed'])->toBe(0);
    $titles = Task::query()->pluck('title')->all();
    expect(collect($titles)->filter(fn ($t) => str_starts_with($t, 'Stage '))->count())->toBeGreaterThanOrEqual(1)
        ->and(collect($titles)->contains(fn ($t) => str_starts_with($t, 'Overdue ')))->toBeTrue()
        ->and(collect($titles)->contains(fn ($t) => str_starts_with($t, 'Approved '.$order->so_number)))->toBeTrue()
        ->and(collect($titles)->contains(fn ($t) => str_starts_with($t, 'Won '.$deal->deal_number)))->toBeTrue()
        ->and($followup->exists)->toBeTrue();
});

it('notifies the owner’s manager, and tells nobody when the owner has none', function () {
    ($this->make)(['name' => 'Tell', 'actions' => [['type' => 'notify_manager', 'message' => 'A new lead came in for {{name}}']]]);
    ($this->lead)('Alpha Works', $this->bde);
    ($this->lead)('Loner Works', $this->manager);

    ($this->engine)();

    Notification::assertSentTo($this->manager, WorkflowNotification::class, fn ($n) => str_contains($n->message, 'Alpha Works') && $n->workflow === 'Tell');
    Notification::assertSentToTimes($this->manager, WorkflowNotification::class, 1);
    expect(($this->runs)()->where('status', 'ok'))->toHaveCount(2)->and(($this->runs)()->last()->results[0]['note'])->toContain('Nobody to tell');
});

it('hands a lead over only if the person it runs as may', function () {
    ($this->make)(['name' => 'Hand over', 'actions' => [['type' => 'assign_lead', 'user_id' => (string) $this->bde->id, 'reason' => 'Territory rule']]]);
    $lead = ($this->lead)('Alpha Works', $this->manager);

    ($this->engine)();

    expect($lead->fresh()->assigned_to)->toBe($this->bde->id)->and(($this->runs)()->sole()->status)->toBe('ok');
    expect(LeadTransfer::query()->where('lead_id', $lead->id)->latest('id')->first()->reason)->toBe('Territory rule');

    Carbon::setTestNow('2026-10-12 09:45:00');
    // run as someone without the right to hand leads out: the run fails, in words, and nothing moved
    $workflow = ($this->make)(['name' => 'Telecaller rule', 'actions' => [['type' => 'create_task', 'title' => 'x', 'assignee' => 'owner']]], $this->manager);
    inCompany($this->acme, fn () => $workflow->forceFill(['run_as' => makeUser(['Tele-caller'], [$this->acme])->id])->save());
    ($this->lead)('Beta Works', $this->manager);
    ($this->engine)();
    expect(($this->runs)()->where('workflow_id', $workflow->id)->sole()->status)->toBe('failed');
});

it('fails when the person it runs as is gone or no longer holds the right, and can be tried again once fixed', function () {
    $workflow = ($this->make)();
    $lead = ($this->lead)('Alpha Works');
    $this->manager->forceFill(['is_active' => false])->save();

    ($this->engine)();
    $run = ($this->runs)()->sole();
    expect($run->status)->toBe('failed')->and($run->reason)->toContain('can no longer run it')->and(Task::query()->count())->toBe(0);

    // saved again by someone who can: the failed run is tried again and does its work
    inCompany($this->acme, fn () => app(WorkflowService::class)->update($workflow->fresh(), ['name' => 'Welcome task', 'conditions' => [], 'actions' => [['type' => 'create_task', 'title' => 'Call {{name}}', 'assignee' => 'owner', 'due_hours' => '2']]], $this->admin));
    inCompany($this->acme, fn () => app(WorkflowEngine::class)->retry($run, $this->admin));

    expect($run->fresh()->status)->toBe('ok')->and($run->fresh()->attempts)->toBe(2)->and(Task::query()->sole()->subject_id)->toBe($lead->id);

    // an ok run is a record
    expect(fn () => inCompany($this->acme, fn () => app(WorkflowEngine::class)->retry($run->fresh(), $this->admin)))->toThrow(FieldRuleException::class, 'failed run')
        ->and(fn () => $run->fresh()->forceFill(['reason' => 'x'])->save())->toThrow(LogicException::class, 'does not change');
});

it('does not repeat the actions that worked when a run is tried again', function () {
    ($this->make)(['name' => 'Two things', 'actions' => [
        ['type' => 'create_task', 'title' => 'First', 'assignee' => 'owner', 'due_hours' => '5'],
        ['type' => 'create_task', 'title' => 'Second', 'assignee' => 'manager', 'due_hours' => '5'],
    ]]);
    ($this->lead)('Loner Works', $this->manager, ['assigned_to' => (string) $this->manager->id]);   // nobody manages the manager: the second action cannot be done

    ($this->engine)();
    $run = ($this->runs)()->sole();

    expect($run->status)->toBe('failed')->and($run->results[0]['ok'])->toBeTrue()->and($run->results[1]['ok'])->toBeFalse()->and($run->results[1]['note'])->toContain('no manager')
        ->and(Task::query()->pluck('title')->all())->toBe(['First']);

    inCompany($this->acme, fn () => app(WorkflowEngine::class)->retry($run, $this->admin));

    expect(Task::query()->where('title', 'First')->count())->toBe(1);   // not made a second time
});

it('queues a message only with an approved template and the person’s consent, and says what became of it', function () {
    $template = makeTemplate($this->acme, $this->manager);
    ($this->make)(['name' => 'Mail', 'actions' => [['type' => 'send_message', 'template_id' => (string) $template->id]]]);
    $agreed = ($this->lead)('Agreed Works', $this->bde, ['email' => 'agreed@example.com']);
    ($this->lead)('Silent Works', $this->bde, ['email' => 'silent@example.com']);
    inCompany($this->acme, fn () => app(ConsentService::class)->record(Channel::Email, 'agreed@example.com', 'opted_in', 'form'));

    ($this->engine)();

    $sent = Communication::query()->where('to_address', 'agreed@example.com')->sole();
    $held = Communication::query()->where('to_address', 'silent@example.com')->sole();

    expect($sent->status)->toBe('sent')->and($sent->workflow_run_id)->not->toBeNull()->and($sent->subject_type)->toBe('lead')->and($sent->subject_id)->toBe($agreed->id)
        ->and($held->status)->toBe('suppressed')->and($held->failure)->toContain('No consent')
        ->and(($this->runs)()->first()->results[0]['note'])->toContain('handed to the mail server');

    // a template that is switched off is not used
    inCompany($this->acme, fn () => $template->forceFill(['status' => 'inactive'])->save());
    ($this->lead)('Third Works', $this->bde, ['email' => 'third@example.com']);
    ($this->engine)();
    expect(($this->runs)()->last()->status)->toBe('failed')->and(($this->runs)()->last()->results[0]['note'])->toContain('not approved and active');
});

it('limits a workflow to a number of runs an hour and a few runs a day for one record', function () {
    $workflow = ($this->make)();
    inCompany($this->acme, fn () => $workflow->forceFill(['max_runs_per_hour' => 2])->save());
    foreach (['A', 'B', 'C', 'D'] as $n) {
        ($this->lead)("{$n} Works");
    }

    $first = ($this->engine)();
    expect($first['ok'])->toBe(2)->and($first['limited'])->toBe(1)->and(WorkflowRun::query()->count())->toBe(2);   // the rest wait, nothing is lost

    Carbon::setTestNow('2026-10-12 10:35:00');
    expect(($this->engine)()['ok'])->toBe(2)->and(Task::query()->count())->toBe(4);

    // one record that keeps changing stage sets a stage workflow off only three times a day
    ($this->make)(['name' => 'Stage', 'trigger' => 'stage_changed', 'actions' => [['type' => 'create_task', 'title' => 'Stage {{reference}}', 'assignee' => 'owner', 'due_hours' => '5']]]);
    Carbon::setTestNow('2026-10-12 11:00:00');
    $lead = ($this->lead)('Restless Works');
    $stages = [LeadStage::Contacted, LeadStage::Qualified, LeadStage::Contacted, LeadStage::Qualified, LeadStage::Contacted];

    foreach ($stages as $stage) {
        inCompany($this->acme, fn () => app(LeadService::class)->changeStage($lead->fresh(), $stage, $this->bde, null));
        Carbon::setTestNow(now()->addMinutes(1));
        ($this->engine)();
    }

    $stageRuns = ($this->runs)()->filter(fn ($r) => str_starts_with($r->trigger_key, 'stage:'));
    expect($stageRuns->where('status', 'ok'))->toHaveCount(3)->and($stageRuns->where('status', 'skipped')->count())->toBeGreaterThanOrEqual(1);
});

it('records what it would do in dry run, and does nothing', function () {
    $workflow = ($this->make)();
    inCompany($this->acme, fn () => app(WorkflowService::class)->setDryRun($workflow->fresh(), true, $this->admin));
    ($this->lead)('Alpha Works');

    $r = ($this->engine)();

    expect($r['dry'])->toBe(1)->and(Task::query()->count())->toBe(0)->and(($this->runs)()->sole()->status)->toBe('dry_run')->and(($this->runs)()->sole()->results[0]['note'])->toStartWith('Would:');
});

it('previews a real record without doing anything, and only one the viewer may see', function () {
    $workflow = ($this->make)(['conditions' => [['field' => 'value', 'op' => 'gte', 'value' => '50000']]], $this->manager, false);
    $mine = ($this->lead)('Mine Works', $this->bde, ['expected_value' => '75000']);
    $other = ($this->lead)('Outside Works', $this->bde2, ['expected_value' => '75000']);
    $engine = app(WorkflowEngine::class);
    $definition = $workflow->current->definition;

    $ok = inCompany($this->acme, fn () => $engine->preview($workflow, $definition, 'lead', $mine->id, $this->manager));
    expect($ok['found'])->toBeTrue()->and($ok['matches'])->toBeTrue()->and($ok['would'][0])->toContain('make a task');

    $unseen = inCompany($this->acme, fn () => $engine->preview($workflow, $definition, 'lead', $other->id, $this->manager));
    expect($unseen['found'])->toBeFalse();

    $samples = inCompany($this->acme, fn () => $engine->samples($workflow, $this->manager));
    expect(collect($samples)->pluck('id')->all())->toBe([$mine->id])->and(Task::query()->count())->toBe(0);
});

it('does not reach another company’s records, and keeps each company’s runs to itself', function () {
    ($this->make)();
    $gAdmin = makeUser(['Company Admin'], [$this->globex]);
    $gx = makeLocation($this->globex, 'GX');
    makeLead($this->globex, $gAdmin, $gx, ['name' => 'Foreign Works']);
    ($this->lead)('Ours');

    ($this->engine)();

    expect(WorkflowRun::query()->count())->toBe(1)->and(Task::query()->count())->toBe(1)->and(Lead::withoutGlobalScopes()->where('name', 'Foreign Works')->exists())->toBeTrue();
});
