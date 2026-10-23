<?php

use App\Modules\Masters\Models\Ledger;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Shared\Http\Livewire\Comments;
use App\Modules\Shared\Tenancy\TenantContext;
use App\Modules\Workspace\Http\Livewire\Customer360Page;
use App\Modules\Workspace\Http\Livewire\SlaBreachList;
use App\Modules\Workspace\Http\Livewire\SlaSettings;
use App\Modules\Workspace\Http\Livewire\TaskForm;
use App\Modules\Workspace\Http\Livewire\TaskList;
use App\Modules\Workspace\Http\Livewire\TaskShow;
use App\Modules\Workspace\Models\Holiday;
use App\Modules\Workspace\Models\SlaBreach;
use App\Modules\Workspace\Models\SlaPolicy;
use App\Modules\Workspace\Models\Task;
use App\Modules\Workspace\Services\Customer360;
use App\Modules\Workspace\Services\SlaSweep;
use App\Modules\Workspace\Services\TaskService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * The screens of Phase 14: tasks (list, board, calendar, form, page), the SLA rules and breach log, and Customer 360 — each showing a person only
 * what they may see, and refusing what they may not do.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME', ['state_code' => '24']);
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD', ['state_code' => '24']);

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);

    $this->customer = makeCustomer($this->acme, 'Shree Ganesh Industries', ['credit_limit' => '100000', 'credit_days' => '30']);
    $this->bolt = makeUsableItem($this->acme);
    makeStandardRate($this->acme, $this->bolt, $this->ahd, '125.50');

    $this->sale = function ($by, string $quantity = '10', string $upTo = 'approved') {
        $lead = makeLead($this->acme, $by, $this->ahd, [], [lineRow($this->bolt, $quantity, '')]);
        $deal = approveDocument($this->acme, makeDeal($this->acme, $by, qualify($this->acme, $lead, $by), ['customer_ledger_id' => (string) $this->customer->id]), $by, $this->approver);

        return makeSalesOrder($this->acme, $by, $deal, $this->customer, [], null, $upTo, $this->approver);
    };
    $this->task = fn ($by, array $input = []) => inCompany($this->acme, fn () => app(TaskService::class)->create(['title' => 'Call the customer', ...$input], $by));
    $this->view = fn ($user) => inCompany($this->acme, function () use ($user) {
        Auth::setUser($user);
        app(TenantContext::class)->flush();

        return app(Customer360::class)->build(Ledger::query()->findOrFail($this->customer->id), $user);
    });
});

// ---- tasks ---------------------------------------------------------------------------------------------------------------------

it('lists, boards and calendars exactly the tasks a person may see', function () {
    $mine = ($this->task)($this->bde, ['title' => 'Mine', 'due_at' => '2026-10-14T10:00']);
    $theirs = ($this->task)($this->bde2, ['title' => 'Not mine', 'due_at' => '2026-10-14T11:00']);
    $given = ($this->task)($this->manager, ['title' => 'From the manager', 'assigned_to' => (string) $this->bde->id, 'due_at' => '2026-10-15T11:00']);

    Livewire::actingAs($this->bde)->test(TaskList::class)->assertSee('Mine')->assertDontSee('Not mine')->assertSee('From the manager');
    Livewire::actingAs($this->manager)->test(TaskList::class)->set('show', 'team')->assertSee('Mine')->assertDontSee('Not mine');
    Livewire::actingAs($this->admin)->test(TaskList::class)->set('show', 'all')->assertSee('Mine')->assertSee('Not mine');
    Livewire::actingAs($this->bde)->test(TaskList::class)->set('show', 'given')->assertDontSee('From the manager')->assertDontSee('Mine');
    Livewire::actingAs($this->manager)->test(TaskList::class)->set('show', 'given')->assertSee('From the manager');

    // board: three columns; the calendar puts the task on its day
    Livewire::actingAs($this->bde)->test(TaskList::class)->set('mode', 'board')->assertSee('To do')->assertSee('In progress')->assertSee('Mine')->assertDontSee('Not mine');
    Livewire::actingAs($this->bde)->test(TaskList::class)->set('mode', 'calendar')->set('month', '2026-10')->assertSee('October 2026')->assertSee('10:00 Mine')->assertDontSee('Not mine')
        ->call('shiftMonth', 1)->assertSee('November 2026')->assertDontSee('10:00 Mine');

    // narrow by state and search
    Livewire::actingAs($this->bde)->test(TaskList::class)->set('state', 'overdue')->assertDontSee('Mine');
    Livewire::actingAs($this->bde)->test(TaskList::class)->set('search', 'manager')->assertSee('From the manager')->assertDontSee('Mine');

    // a stranger in another company sees nothing of ours
    Livewire::actingAs($this->globexAdmin)->test(TaskList::class)->set('show', 'all')->assertDontSee('Mine');
    expect($theirs->exists)->toBeTrue()->and($mine->exists)->toBeTrue()->and($given->exists)->toBeTrue();
});

it('starts and finishes a task from the list, and refuses a task the person cannot work', function () {
    $mine = ($this->task)($this->bde, ['title' => 'Mine']);
    $theirs = ($this->task)($this->bde2, ['title' => 'Not mine']);

    $list = Livewire::actingAs($this->bde)->test(TaskList::class)->call('startTask', $mine->id);
    expect($mine->fresh()->status->value)->toBe('in_progress');

    $list->call('openFinish', $mine->id)->assertSet('finishing', $mine->id)->set('note', 'Done and dusted')->call('finishTask')->assertSet('finishing', 0);
    expect($mine->fresh()->status->value)->toBe('done')->and($mine->fresh()->completion_note)->toBe('Done and dusted');

    Livewire::actingAs($this->bde)->test(TaskList::class)->call('startTask', $theirs->id)->assertForbidden();
    Livewire::actingAs($this->bde)->test(TaskList::class)->call('openFinish', $theirs->id)->assertForbidden();
    expect($theirs->fresh()->status->value)->toBe('open');

    // a manager works their team’s task; a colleague outside the team cannot
    $teams = ($this->task)($this->bde, ['title' => 'Team task']);
    Livewire::actingAs($this->manager)->test(TaskList::class)->call('startTask', $teams->id);
    expect($teams->fresh()->status->value)->toBe('in_progress');
});

it('makes a task from a record’s link, and refuses a record the person cannot open', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Alpha Works']);
    $other = makeLead($this->acme, $this->bde2, $this->ahd, ['name' => 'Beta Works']);

    Livewire::actingAs($this->bde)->withQueryParams(['type' => 'lead', 'id' => (string) $lead->id])->test(TaskForm::class)
        ->assertSee('Alpha Works')->set('t.title', 'Send the brochure')->set('t.priority', 'high')->set('t.due_at', '2026-10-14T11:00')->call('save')->assertHasNoErrors();

    $task = Task::query()->sole();
    expect($task->subject_id)->toBe($lead->id)->and($task->priority->value)->toBe('high');

    Livewire::actingAs($this->bde)->withQueryParams(['type' => 'lead', 'id' => (string) $other->id])->test(TaskForm::class)
        ->assertDontSee('Beta Works')->set('t.title', 'Sneaky')->call('save')->assertHasErrors('subject_id');
    expect(Task::query()->count())->toBe(1);

    Livewire::actingAs($this->bde)->test(TaskForm::class)->set('t.title', '')->call('save')->assertHasErrors('title');
    Livewire::actingAs($this->telecaller)->withQueryParams(['type' => '', 'id' => ''])->test(TaskForm::class)->set('t.title', 'Call back')->call('save')->assertHasNoErrors();
});

it('shows a task, lets its people work it, and keeps a finished one closed', function () {
    $task = ($this->task)($this->manager, ['title' => 'Review the quotes', 'assigned_to' => (string) $this->bde->id, 'due_at' => '2026-10-14T16:00']);

    Livewire::actingAs($this->bde)->test(TaskShow::class, ['task' => $task])->assertSee('Review the quotes')->assertSee('Who has had it')
        ->call('startTask')->call('openDialog', 'finish')->set('note', 'Reviewed')->call('finishTask');
    expect($task->fresh()->status->value)->toBe('done');

    // a finished task offers nothing and refuses everything
    Livewire::actingAs($this->bde)->test(TaskShow::class, ['task' => $task->fresh()])->assertDontSee('Call off')->call('openDialog', 'cancel')->assertForbidden();

    // giving it to somebody needs the right to assign: the salesperson has not got it
    $open = ($this->task)($this->bde, ['title' => 'Another']);
    Livewire::actingAs($this->bde)->test(TaskShow::class, ['task' => $open])->call('openDialog', 'give')->assertForbidden();
    Livewire::actingAs($this->manager)->test(TaskShow::class, ['task' => $open])->call('openDialog', 'give')->set('giveTo', (string) $this->manager->id)->set('reason', 'Taking it over')->call('giveTask');
    expect($open->fresh()->assigned_to)->toBe($this->manager->id);

    // nobody outside the reach opens it; another company gets a 404
    $this->actingAs($this->bde2)->get(route('workspace.tasks.show', $open))->assertForbidden();
    $this->actingAs($this->globexAdmin)->get(route('workspace.tasks.show', $open))->assertNotFound();
});

it('lets people comment on a task they can open', function () {
    $task = ($this->task)($this->bde, ['title' => 'Commented']);

    Livewire::actingAs($this->bde)->test(Comments::class, ['type' => 'task', 'id' => $task->id])->set('body', 'On it')->call('add')->assertHasNoErrors();
    expect($task->comments()->count())->toBe(1);

    Livewire::actingAs($this->bde2)->test(Comments::class, ['type' => 'task', 'id' => $task->id])->assertForbidden();
});

it('shows the task menu to those who may open tasks', function () {
    $this->actingAs($this->bde)->get(route('workspace.tasks.index'))->assertOk()->assertSee('New task');
    $this->actingAs($this->approver)->get(route('workspace.tasks.index'))->assertForbidden();
    $this->actingAs($this->bde)->get(route('dashboard'))->assertOk()->assertSee(route('workspace.tasks.index'), false);
    $this->actingAs($this->approver)->get(route('dashboard'))->assertOk()->assertDontSee(route('workspace.tasks.index'), false);
});

// ---- SLA settings and the breach log -------------------------------------------------------------------------------------------

it('adds, changes and switches a policy off, with the checks a policy needs', function () {
    $page = Livewire::actingAs($this->admin)->test(SlaSettings::class)->call('newPolicy')
        ->set('p.name', 'First response')->set('p.target_minutes', '90')->call('savePolicy')->assertHasNoErrors();

    $policy = SlaPolicy::query()->sole();
    expect($policy->target_minutes)->toBe(90)->and($policy->kind->value)->toBe('first_response')->and($policy->business_hours)->toBeTrue();

    $page->call('editPolicy', $policy->id)->set('p.target_minutes', '45')->call('savePolicy');
    expect($policy->fresh()->target_minutes)->toBe(45);

    $page->call('togglePolicy', $policy->id);
    expect($policy->fresh()->status)->toBe('inactive');

    // checks
    $new = fn () => Livewire::actingAs($this->admin)->test(SlaSettings::class)->call('newPolicy');
    $new()->set('p.name', '')->call('savePolicy')->assertHasErrors('name');
    $new()->set('p.name', 'x')->set('p.target_minutes', '-5')->call('savePolicy')->assertHasErrors('target_minutes');
    $new()->set('p.name', 'x')->set('p.kind', 'task')->set('p.lead_source_id', (string) leadSourceId($this->acme))->call('savePolicy')->assertHasErrors('lead_source_id');
    $new()->set('p.name', 'x')->set('p.kind', 'first_response')->set('p.priority', 'high')->call('savePolicy')->assertHasErrors('priority');
    $new()->set('p.name', 'x')->set('p.lead_source_id', '999999')->call('savePolicy')->assertHasErrors('lead_source_id');
    $new()->set('p.name', 'x')->set('p.kind', 'nonsense')->call('savePolicy')->assertHasErrors('kind');
    expect(SlaPolicy::query()->count())->toBe(1);

    // only those with the right
    Livewire::actingAs($this->bde)->test(SlaSettings::class)->assertForbidden();
    Livewire::actingAs($this->manager)->test(SlaSettings::class)->assertOk()->call('newPolicy')->assertForbidden();   // the manager may look, not change
});

it('adds and removes holidays, one to a day', function () {
    $page = Livewire::actingAs($this->admin)->test(SlaSettings::class)
        ->set('h.holiday_date', '2026-11-08')->set('h.name', 'Diwali')->call('addHoliday')->assertHasNoErrors();
    expect(Holiday::query()->count())->toBe(1);

    $page->set('h.holiday_date', '2026-11-08')->set('h.name', 'Again')->call('addHoliday')->assertHasErrors('holiday_date');
    $page->set('h.holiday_date', 'someday')->set('h.name', 'x')->call('addHoliday')->assertHasErrors('holiday_date');
    $page->call('removeHoliday', Holiday::query()->sole()->id);
    expect(Holiday::query()->count())->toBe(0);

    // it can be added again after it was removed
    $page->set('h.holiday_date', '2026-11-08')->set('h.name', 'Diwali')->call('addHoliday')->assertHasNoErrors();
    expect(Holiday::query()->count())->toBe(1);
});

it('shows the breach log to the people it concerns only', function () {
    inCompany($this->acme, function () {
        $p = new SlaPolicy;
        $p->fill(['name' => 'First response', 'kind' => 'first_response', 'target_minutes' => 5, 'business_hours' => false, 'escalate_to_manager' => true])->save();
        $p->forceFill(['created_at' => now()->subDay()])->saveQuietly();
    });
    $mine = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Alpha Works']);
    $outside = makeLead($this->acme, $this->bde2, $this->ahd, ['name' => 'Beta Works']);
    inCompany($this->acme, fn () => app(SlaSweep::class)->run());
    Carbon::setTestNow('2026-10-12 10:00:00');
    inCompany($this->acme, fn () => app(SlaSweep::class)->run());

    expect(SlaBreach::query()->count())->toBe(2);

    Livewire::actingAs($this->bde)->test(SlaBreachList::class)->assertSee($mine->lead_number)->assertDontSee($outside->lead_number);   // a salesperson reads their own
    Livewire::actingAs($this->manager)->test(SlaBreachList::class)->assertSee($mine->lead_number)->assertDontSee($outside->lead_number);   // the team, not the colleague outside it
    Livewire::actingAs($this->admin)->test(SlaBreachList::class)->assertSee($mine->lead_number)->assertSee($outside->lead_number);
    Livewire::actingAs($this->globexAdmin)->test(SlaBreachList::class)->assertDontSee($mine->lead_number);
    Livewire::actingAs(makeUser(['Purchase User'], [$this->acme]))->test(SlaBreachList::class)->assertForbidden();   // nothing to do with leads, tasks or SLAs
});

// ---- Customer 360 --------------------------------------------------------------------------------------------------------------

it('shows a customer’s records to each person as far as their own reach goes', function () {
    ($this->sale)($this->bde, '10');
    ($this->sale)($this->bde2, '30');
    ($this->task)($this->bde, ['title' => 'Chase the payment', 'subject_type' => 'ledger', 'subject_id' => (string) $this->customer->id]);

    $own = ($this->view)($this->bde);
    $team = ($this->view)($this->manager);
    $all = ($this->view)($this->admin);

    expect($own['totals']['orders'])->toBe(1)->and($own['orders'])->toHaveCount(1)->and($own['deals'])->toHaveCount(1)->and($own['quotations'])->toHaveCount(0)
        ->and($team['totals']['orders'])->toBe(1)->and($all['totals']['orders'])->toBe(2)->and($all['orders'])->toHaveCount(2)->and($all['invoices'])->toHaveCount(2)
        ->and($all['totals']['open_deals'])->toBe(0)   // both deals were won by their approved orders
        ->and($own['tasks']->pluck('title')->all())->toBe(['Chase the payment'])
        ->and($all['history'])->not->toBeEmpty();

    $net = SalesOrder::query()->where('approval_status', 'approved')->sum('taxable_total');
    expect((float) $all['totals']['sales'])->toBe((float) $net);
});

it('leaves out the sections a person has no right to, and never shows bank details', function () {
    ($this->sale)($this->bde);

    $accounts = ($this->view)($this->accounts);
    $telecaller = ($this->view)($this->telecaller);

    expect($accounts['orders'])->not->toBeNull()->and($accounts['invoices'])->not->toBeNull()->and($accounts['leads'])->toBeNull()->and($accounts['deals'])->toBeNull()->and($accounts['quotations'])->toBeNull()
        ->and($telecaller['orders'])->toBeNull()->and($telecaller['invoices'])->toBeNull()->and($telecaller['leads'])->not->toBeNull();

    foreach ([$accounts, $telecaller] as $data) {
        expect(array_keys($data))->not->toContain('bank')->and(json_encode($data))->not->toContain('account_number');
    }
});

it('works out the credit position from the limit and what is out on orders within the credit days', function () {
    ($this->sale)($this->bde, '300');   // ₹ 37,650 + GST

    $credit = ($this->view)($this->admin)['credit'];
    $order = SalesOrder::query()->sole();

    expect($credit['limit'])->toBe('100000.00')->and($credit['days'])->toBe(30)->and($credit['exposure'])->toBe(number_format((float) $order->grand_total, 2, '.', ''))
        ->and($credit['available'])->toBe(number_format(100000 - (float) $order->grand_total, 2, '.', ''))->and($credit['status'])->toBe('ok');

    inCompany($this->acme, fn () => Ledger::query()->whereKey($this->customer->id)->update(['credit_limit' => (string) ((float) $order->grand_total + 1000)]));
    expect(($this->view)($this->admin)['credit']['status'])->toBe('near');

    inCompany($this->acme, fn () => Ledger::query()->whereKey($this->customer->id)->update(['credit_limit' => (string) ((float) $order->grand_total - 1)]));
    expect(($this->view)($this->admin)['credit']['status'])->toBe('over');

    inCompany($this->acme, fn () => Ledger::query()->whereKey($this->customer->id)->update(['credit_limit' => null]));
    expect(($this->view)($this->admin)['credit']['status'])->toBe('no_limit');
});

it('opens the page for a customer ledger only, and only for those who may open ledgers', function () {
    $supplier = makeLedger($this->acme, $this->accounts, 'supplier', [], true, $this->approver);

    $this->actingAs($this->admin)->get(route('crm.customers.show', $this->customer))->assertOk()->assertSee('Shree Ganesh Industries')->assertSee('Credit terms')->assertSee('Not built yet');
    $this->actingAs($this->bde)->get(route('crm.customers.show', $this->customer))->assertOk();
    $this->actingAs($this->admin)->get(route('crm.customers.show', $supplier))->assertNotFound();        // a supplier is not a customer
    $this->actingAs($this->globexAdmin)->get(route('crm.customers.show', $this->customer))->assertNotFound();   // another company
    $this->actingAs($this->telecaller)->get(route('crm.customers.show', $this->customer))->assertForbidden();   // no right to ledgers
    auth()->logout();
    $this->get(route('crm.customers.show', $this->customer))->assertRedirect();

    // reached from the ledger, a deal and an order
    $order = ($this->sale)($this->bde);
    $this->actingAs($this->admin)->get(route('masters.ledgers.show', $this->customer))->assertSee(route('crm.customers.show', $this->customer), false);
    $this->actingAs($this->admin)->get(route('sales.orders.show', $order))->assertSee(route('crm.customers.show', $this->customer), false);
    Livewire::actingAs($this->bde)->test(Customer360Page::class, ['customer' => $this->customer->id])->assertSee('Approved sales, net of tax');
});
