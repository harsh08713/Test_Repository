<?php

use App\Modules\CRM\Console\SendFollowupReminders;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Lead;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Sales\Services\QuotationService;
use App\Modules\Shared\Http\Livewire\SystemHealth;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Services\Heartbeat;
use App\Modules\Shared\Tenancy\TenantContext;
use App\Modules\Workspace\Http\Livewire\SlaBreachList;
use App\Modules\Workspace\Http\Livewire\TaskForm;
use App\Modules\Workspace\Http\Livewire\TaskShow;
use App\Modules\Workspace\Models\SlaBreach;
use App\Modules\Workspace\Models\SlaClock;
use App\Modules\Workspace\Models\SlaPolicy;
use App\Modules\Workspace\Models\Task;
use App\Modules\Workspace\Models\TaskAttachment;
use App\Modules\Workspace\Notifications\SlaBreachedNotification;
use App\Modules\Workspace\Services\Customer360;
use App\Modules\Workspace\Services\SlaClockService;
use App\Modules\Workspace\Services\SlaSweep;
use App\Modules\Workspace\Services\TaskAttachmentService;
use App\Modules\Workspace\Services\TaskService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * What Phase 14 left open, closed: pausing a clock, timing sent quotations and documents waiting for approval, the breach log for salespeople, one follow-up
 * alert rather than two, files on tasks, a picker for the record a task is about, leads that look like a customer, and the system health page.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();
    Storage::fake('local');

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
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);

    $this->policy = function (array $over = []): SlaPolicy {
        return inCompany($this->acme, function () use ($over) {
            $p = new SlaPolicy;
            $p->fill(['name' => 'A policy', 'kind' => 'first_response', 'target_minutes' => 60, 'business_hours' => false, 'escalate_to_manager' => true, ...array_diff_key($over, ['created_at' => 1])]);
            $p->save();
            $p->forceFill(['created_at' => $over['created_at'] ?? Carbon::now()->subDay()])->saveQuietly();

            return $p;
        });
    };
    $this->sweep = fn () => inCompany($this->acme, fn () => app(SlaSweep::class)->run());
    $this->task = fn ($by, array $input = []) => inCompany($this->acme, fn () => app(TaskService::class)->create(['title' => 'A task', ...$input], $by));
    $this->clock = fn () => SlaClock::query()->orderByDesc('id')->first();
});

// ---- pause --------------------------------------------------------------------------------------------------------------------

it('pauses a clock so it cannot run out, and moves its deadline on by the time it was paused', function () {
    ($this->policy)(['target_minutes' => 60]);
    makeLead($this->acme, $this->bde, $this->ahd);
    ($this->sweep)();
    $clock = ($this->clock)();
    $svc = fn () => inCompany($this->acme, fn () => app(SlaClockService::class));

    Carbon::setTestNow('2026-10-12 09:50:00');
    expect(fn () => $svc()->pause($clock, '  ', $this->manager))->toThrow(ValidationException::class);
    $paused = $svc()->pause($clock, 'Waiting for the customer’s drawing', $this->manager);
    expect($paused->paused_at)->not->toBeNull()->and($paused->pause_reason)->toBe('Waiting for the customer’s drawing');
    expect(fn () => $svc()->pause($paused, 'again', $this->manager))->toThrow(FieldRuleException::class, 'already paused');

    Carbon::setTestNow('2026-10-12 12:00:00');   // long past the original deadline of 10:30
    expect(($this->sweep)()['breached'])->toBe(0)->and(SlaBreach::query()->count())->toBe(0);

    Carbon::setTestNow('2026-10-12 12:10:00');   // paused for 2 hours 20 minutes in all
    $resumed = $svc()->resume($paused->fresh(), $this->manager);
    expect($resumed->paused_at)->toBeNull()->and($resumed->paused_minutes)->toBe(140)->and($resumed->due_at->toDateTimeString())->toBe('2026-10-12 12:50:00');

    Carbon::setTestNow('2026-10-12 12:40:00');
    expect(($this->sweep)()['breached'])->toBe(0);
    Carbon::setTestNow('2026-10-12 12:55:00');
    expect(($this->sweep)()['breached'])->toBe(1);

    // a clock that has run out cannot be paused, and an unpaused one cannot be resumed
    expect(fn () => $svc()->pause($resumed->fresh(), 'late', $this->manager))->toThrow(FieldRuleException::class, 'run out')
        ->and(fn () => $svc()->resume($resumed->fresh(), $this->manager))->toThrow(FieldRuleException::class, 'not paused');
    expect(ActivityLog::query()->where('log_name', 'sla')->whereIn('event', ['paused', 'resumed'])->count())->toBe(2);
});

it('lets a manager pause and resume from the running clocks, and nobody else', function () {
    ($this->policy)(['target_minutes' => 60]);
    makeLead($this->acme, $this->bde, $this->ahd);
    ($this->sweep)();
    $clock = ($this->clock)();

    Livewire::actingAs($this->manager)->test(SlaBreachList::class)->set('tab', 'running')->assertSee('Within time')
        ->call('openPause', $clock->id)->set('reason', 'Customer on leave')->call('pauseClock')->assertHasNoErrors();
    expect($clock->fresh()->paused_at)->not->toBeNull();
    Livewire::actingAs($this->manager)->test(SlaBreachList::class)->set('tab', 'running')->assertSee('Paused')->assertSee('Customer on leave')->call('resumeClock', $clock->id);
    expect($clock->fresh()->paused_at)->toBeNull();

    // a salesperson sees their clocks but cannot pause them; a colleague’s clock is not found
    Livewire::actingAs($this->bde)->test(SlaBreachList::class)->set('tab', 'running')->assertSee('Within time')->call('openPause', $clock->id)->assertForbidden();
    Livewire::actingAs($this->bde2)->test(SlaBreachList::class)->set('tab', 'running')->call('openPause', $clock->id)->assertNotFound();
});

// ---- quotations and approvals ---------------------------------------------------------------------------------------------------

it('times a sent quotation until the customer answers, and stops when it is replaced', function () {
    ($this->policy)(['name' => 'Quotation reply', 'kind' => 'quotation_response', 'target_minutes' => 2880]);   // two days
    $customer = makeCustomer($this->acme, 'Shree Ganesh Industries', ['credit_limit' => '5000000', 'credit_days' => '30']);
    $bolt = makeUsableItem($this->acme);
    makeStandardRate($this->acme, $bolt, $this->ahd, '125.50');
    $lead = makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($bolt, '10', '')]);
    $deal = approveDocument($this->acme, makeDeal($this->acme, $this->bde, qualify($this->acme, $lead, $this->bde), ['customer_ledger_id' => (string) $customer->id]), $this->bde, $this->approver);
    $quotation = makeQuotation($this->acme, $this->bde, $deal, [], null, 'approved', $this->approver);
    inCompany($this->acme, fn () => app(QuotationService::class)->send($quotation->fresh(), ['mode' => 'manual'], $this->bde));

    ($this->sweep)();
    $clock = SlaClock::query()->where('kind', 'quotation_response')->sole();
    expect($clock->owner_id)->toBe($this->bde->id)->and($clock->due_at->toDateTimeString())->toBe('2026-10-14 09:30:00');

    Carbon::setTestNow('2026-10-14 10:00:00');
    expect(($this->sweep)()['breached'])->toBe(1);
    Notification::assertSentTo($this->bde, SlaBreachedNotification::class, fn ($n) => $n->kind === 'Quotation' && str_contains($n->what, $quotation->quotation_number));
    Notification::assertSentTo($this->manager, SlaBreachedNotification::class);

    // answered afterwards: met late, and no second breach
    inCompany($this->acme, fn () => app(QuotationService::class)->accept($quotation->fresh(), 'PO-1', $this->bde));
    ($this->sweep)();
    expect($clock->fresh()->state)->toBe('met')->and(SlaBreach::query()->count())->toBe(1);
});

it('times a document waiting for an approver, tells the approver, and meets the clock when it is decided', function () {
    ($this->policy)(['name' => 'Approvals', 'kind' => 'approval', 'target_minutes' => 30, 'escalate_to_manager' => false]);
    $template = makeTemplate($this->acme, $this->manager, [], false);
    inCompany($this->acme, fn () => app(ApprovalService::class)->submit($template, $this->manager));

    ($this->sweep)();
    $clock = SlaClock::query()->where('kind', 'approval')->sole();
    expect($clock->due_at->toDateTimeString())->toBe('2026-10-12 10:00:00');

    Carbon::setTestNow('2026-10-12 10:05:00');
    expect(($this->sweep)()['breached'])->toBe(1);
    Notification::assertSentTo($this->approver, SlaBreachedNotification::class, fn ($n) => $n->kind === 'Approval' && str_contains($n->what, 'Autumn offer'));
    Notification::assertNotSentTo($this->telecaller, SlaBreachedNotification::class);

    inCompany($this->acme, fn () => app(ApprovalService::class)->approve($template->fresh(), $this->approver));
    ($this->sweep)();
    expect($clock->fresh()->state)->toBe('met')->and(SlaBreach::query()->count())->toBe(1);
});

// ---- who sees the log, and one follow-up alert ----------------------------------------------------------------------------------------

it('lets a salesperson read their own breaches, not a colleague’s', function () {
    ($this->policy)(['target_minutes' => 5]);
    $mine = makeLead($this->acme, $this->bde, $this->ahd);
    $theirs = makeLead($this->acme, $this->bde2, $this->ahd);
    ($this->sweep)();
    Carbon::setTestNow('2026-10-12 10:00:00');
    ($this->sweep)();

    Livewire::actingAs($this->bde)->test(SlaBreachList::class)->assertSee($mine->lead_number)->assertDontSee($theirs->lead_number);
    Livewire::actingAs($this->bde2)->test(SlaBreachList::class)->assertSee($theirs->lead_number)->assertDontSee($mine->lead_number);
    Livewire::actingAs(makeUser(['Purchase User'], [$this->acme]))->test(SlaBreachList::class)->assertForbidden();   // no lead, follow-up, task or SLA right at all
    $this->actingAs($this->bde)->get(route('dashboard'))->assertSee(route('workspace.sla.breaches'), false);
});

it('leaves the older 24-hour follow-up alert alone only when no follow-up policy covers the follow-up', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    $before = Carbon::withTestNow('2026-10-10 09:00:00', fn () => makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-10T10:00']));
    Carbon::setTestNow('2026-10-12 09:30:00');

    Artisan::call(SendFollowupReminders::class === '' ? '' : 'crm:followup-reminders');
    expect($before->fresh()->escalated_at)->not->toBeNull();   // no policy: the manager was told by the older alert

    // with a follow-up policy in force when the follow-up was planned, the SLA sweep is the one that tells (once, in the log)
    ($this->policy)(['name' => 'Follow-ups', 'kind' => 'followup', 'target_minutes' => 30, 'created_at' => '2026-10-01 00:00:00']);
    $later = Carbon::withTestNow('2026-10-11 09:00:00', fn () => makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-11T10:00']));
    Carbon::setTestNow('2026-10-12 12:00:00');
    Artisan::call('crm:followup-reminders');

    expect($later->fresh()->escalated_at)->toBeNull();
    ($this->sweep)();
    expect(SlaBreach::query()->where('kind', 'followup')->count())->toBeGreaterThanOrEqual(1);
});

// ---- files on a task -----------------------------------------------------------------------------------------------------------------

it('keeps a file on a task privately, hands it only to those who may open the task, and removes it while the task is live', function () {
    $task = ($this->task)($this->bde, ['title' => 'Send the drawing']);
    $file = UploadedFile::fake()->create('Drawing 12 (final).pdf', 120, 'application/pdf');

    Livewire::actingAs($this->bde)->test(TaskShow::class, ['task' => $task])->set('attachment', $file)->call('addFile')->assertHasNoErrors();
    $row = TaskAttachment::query()->sole();

    expect($row->name)->toBe('Drawing 12 (final).pdf')->and($row->uploaded_by)->toBe($this->bde->id)->and(Storage::disk('local')->exists($row->path))->toBeTrue()
        ->and($row->path)->toStartWith("task-attachments/{$this->acme->id}/{$task->id}/");

    $url = route('workspace.tasks.attachments.download', [$task, $row]);
    $this->actingAs($this->bde)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/octet-stream');
    $this->actingAs($this->manager)->get($url)->assertOk();                 // manages the owner
    $this->actingAs($this->bde2)->get($url)->assertForbidden();             // outside the reach
    $this->actingAs(makeUser(['Company Admin'], [$this->globex]))->get($url)->assertNotFound();
    $this->actingAs($this->bde)->get(route('workspace.tasks.attachments.download', [($this->task)($this->bde), $row]))->assertNotFound();   // not this task’s file

    Livewire::actingAs($this->bde2)->test(TaskShow::class, ['task' => $task])->assertForbidden();
    Livewire::actingAs($this->bde)->test(TaskShow::class, ['task' => $task])->call('removeFile', $row->id);
    expect($row->fresh()->removed_at)->not->toBeNull()->and($row->fresh()->removed_by)->toBe($this->bde->id)->and(Storage::disk('local')->exists($row->path))->toBeFalse();
    $this->actingAs($this->bde)->get($url)->assertNotFound();
    expect(fn () => $row->fresh()->delete())->toThrow(LogicException::class, 'cannot be deleted');
});

it('refuses a file that is the wrong kind, too big, not what its name says, too many, or on a finished task', function () {
    $task = ($this->task)($this->bde);
    $try = fn ($file) => Livewire::actingAs($this->bde)->test(TaskShow::class, ['task' => $task->fresh()])->set('attachment', $file)->call('addFile');

    $try(UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'))->assertHasErrors('attachment');
    $try(UploadedFile::fake()->create('big.pdf', TaskAttachmentService::MAX_KB + 1, 'application/pdf'))->assertHasErrors('attachment');
    // a script with a .pdf name: the file's real content decides, not its name (a fake upload takes its type from the name, so a real file is used)
    $path = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($path, '<?php echo "hi";');
    expect(fn () => inCompany($this->acme, fn () => app(TaskAttachmentService::class)->attach($task->fresh(), new UploadedFile($path, 'script.pdf', null, null, true), $this->bde)))->toThrow(FieldRuleException::class, 'does not match');
    @unlink($path);
    expect(TaskAttachment::query()->count())->toBe(0);

    foreach (range(1, TaskAttachmentService::MAX_FILES) as $n) {
        $try(UploadedFile::fake()->create("file{$n}.pdf", 10, 'application/pdf'))->assertHasNoErrors();
    }
    $try(UploadedFile::fake()->create('one-too-many.pdf', 10, 'application/pdf'))->assertHasErrors('attachment');
    expect(TaskAttachment::query()->count())->toBe(TaskAttachmentService::MAX_FILES);

    inCompany($this->acme, fn () => app(TaskService::class)->complete($task->fresh(), null, $this->bde));
    $try(UploadedFile::fake()->create('late.pdf', 10, 'application/pdf'))->assertForbidden();   // a finished task is closed
});

// ---- the record picker ------------------------------------------------------------------------------------------------------------------

it('offers, as a task is made, only the records the person may open, and makes the task about the one chosen', function () {
    $mine = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Alpha Works']);
    makeLead($this->acme, $this->bde2, $this->ahd, ['name' => 'Alphabet Works']);
    makeCustomer($this->acme, 'Alpha Industries');

    $form = Livewire::actingAs($this->bde)->test(TaskForm::class)->set('subjectSearch', 'alp')->assertSee('Alpha Works')->assertSee('Alpha Industries')->assertDontSee('Alphabet Works');
    Livewire::actingAs($this->bde)->test(TaskForm::class)->set('subjectSearch', 'a')->assertDontSee('Alpha Works');   // too short to search

    $form->call('pickSubject', 'lead', $mine->id)->assertSee('About:')->assertSee('Alpha Works')->set('t.title', 'Follow up')->call('save')->assertHasNoErrors();
    $task = Task::query()->sole();
    expect($task->subject_type)->toBe('lead')->and($task->subject_id)->toBe($mine->id);

    // a made-up pick is refused at the service, and a type that is not allowed is not found
    Livewire::actingAs($this->bde)->test(TaskForm::class)->call('pickSubject', 'user', 1)->assertNotFound();
    Livewire::actingAs($this->bde)->test(TaskForm::class)->call('pickSubject', 'lead', 99999)->set('t.title', 'x')->call('save')->assertHasErrors('subject_id');
    Livewire::actingAs($this->bde)->test(TaskForm::class)->call('pickSubject', 'lead', $mine->id)->call('clearSubject')->assertDontSee('About:');
});

// ---- customer 360 ------------------------------------------------------------------------------------------------------------------------

it('suggests leads that share a customer’s phone number or e-mail, without counting them as the customer’s', function () {
    $customer = makeCustomer($this->acme, 'Shree Ganesh Industries', ['email' => 'accounts@ganesh.example', 'phone' => '+91 98250 11111']);
    $byPhone = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Phone match', 'mobile' => '9825011111']);
    $dup = ['acknowledge_duplicate' => true];
    $byMail = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Mail match', 'email' => 'ACCOUNTS@ganesh.example']);
    makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Stranger', 'email' => 'someone@else.example']);
    makeLead($this->acme, $this->bde2, $this->ahd, ['name' => 'Outside reach', 'mobile' => '9825011111', ...$dup]);
    $linked = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Already linked', 'mobile' => '9825011111', ...$dup]);
    Lead::query()->whereKey($linked->id)->update(['customer_ledger_id' => $customer->id, 'lead_type' => 'existing']);

    $build = fn ($user) => inCompany($this->acme, function () use ($user, $customer) {
        Auth::setUser($user);
        app(TenantContext::class)->flush();

        return app(Customer360::class)->build(Ledger::query()->findOrFail($customer->id), $user);
    });

    $data = $build($this->bde);
    expect($data['possible']->pluck('name')->sort()->values()->all())->toBe(['Mail match', 'Phone match'])      // not the stranger, not the colleague’s, not the one already linked
        ->and($data['totals']['leads'])->toBe(1)                                                                // only the linked lead counts
        ->and($build($this->admin)['possible']->pluck('name')->all())->toContain('Outside reach')
        ->and($byPhone->exists && $byMail->exists)->toBeTrue();
    $this->actingAs($this->bde)->get(route('crm.customers.show', $customer))->assertSee('Possibly this customer')->assertSee('Phone match');
});

// ---- system health ------------------------------------------------------------------------------------------------------------------------

it('says whether the scheduler and its jobs are running, and what the queue, mail, storage and cache are', function () {
    $beats = app(Heartbeat::class);
    Cache::flush();

    $checks = fn () => collect(app(SystemHealth::class)->checks($beats))->keyBy('name');

    expect($checks()['Scheduler']['status'])->toBe('warn')->and($checks()['Scheduler']['detail'])->toContain('Never seen');

    Artisan::call('system:heartbeat');
    expect($checks()['Scheduler']['status'])->toBe('ok');

    Carbon::setTestNow('2026-10-12 09:36:00');
    expect($checks()['Scheduler']['status'])->toBe('fail')->and($checks()['Scheduler']['detail'])->toContain('should run every minute');

    // the jobs report in when they run
    Carbon::setTestNow('2026-10-12 09:30:00');
    Artisan::call('sla:check');
    Artisan::call('tasks:remind');
    expect($checks()['SLA sweep']['status'])->toBe('ok')->and($checks()['Task reminders']['status'])->toBe('ok');
    Carbon::setTestNow('2026-10-12 10:00:00');
    expect($checks()['SLA sweep']['status'])->toBe('fail');

    expect($checks()['Queue']['status'])->toBe('warn')->and($checks()['Queue']['detail'])->toContain('sync')   // the tests run jobs at once
        ->and($checks()['E-mail']['status'])->toBe('warn')->and($checks()['Private file storage']['status'])->toBe('ok')->and($checks()['Database']['status'])->toBe('ok');
});

it('shows the health page to those who may see settings, and warns an administrator when the scheduler has gone quiet', function () {
    Artisan::call('system:heartbeat');

    Livewire::actingAs($this->admin)->test(SystemHealth::class)->assertSee('System health')->assertSee('Scheduler')->assertSee('Setting it up');
    Livewire::actingAs($this->bde)->test(SystemHealth::class)->assertForbidden();
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertDontSee('The scheduler has not run for a while');

    Carbon::setTestNow('2026-10-12 09:50:00');
    $this->actingAs($this->admin)->get(route('dashboard'))->assertSee('The scheduler has not run for a while')->assertSee(route('admin.health'), false);
    $this->actingAs($this->bde)->get(route('dashboard'))->assertDontSee('The scheduler has not run for a while');
    expect(Lead::query()->count())->toBe(0);
});
