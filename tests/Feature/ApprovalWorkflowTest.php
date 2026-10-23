<?php

use App\Modules\Shared\Enums\ApprovalLevelStatus;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Events\ApprovalRequested;
use App\Modules\Shared\Events\DocumentApproved;
use App\Modules\Shared\Events\DocumentRejected;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Approval;
use App\Modules\Shared\Models\ApprovalLevel;
use App\Modules\Shared\Notifications\ApprovalRequestedNotification;
use App\Modules\Shared\Notifications\DocumentDecisionNotification;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\TestDocument;

beforeEach(function () {
    $this->company = makeCompany('ACME');
    $this->ahd = makeLocation($this->company, 'AHD');

    $this->maker = makeUser(['Purchase User'], [$this->company], attributes: ['name' => 'Maker']);
    $this->accounts = makeUser(['Accounts User'], [$this->company], attributes: ['name' => 'Accounts']);
    $this->approver = makeUser(['Approver'], [$this->company], attributes: ['name' => 'Approver']);

    $this->doc = makeDocument($this->company, $this->ahd, ['name' => 'Widget']);
    $this->service = app(ApprovalService::class);
});

function twoLevelChain(): void
{
    foreach ([1 => 'Accounts User', 2 => 'Approver'] as $level => $role) {
        $row = new ApprovalLevel(['document_type' => 'item', 'level' => $level, 'name' => "Level {$level}", 'approver_role' => $role]);
        $row->forceFill(['company_id' => test()->company->id])->save();
    }
}

// ---- submission --------------------------------------------------------------------------------

it('creates a single pending level when no chain is configured', function () {
    Event::fake([ApprovalRequested::class]);

    $levels = $this->service->submit($this->doc, $this->maker);

    expect($levels)->toHaveCount(1)
        ->and($levels->first()->status)->toBe(ApprovalLevelStatus::Pending)
        ->and($levels->first()->submitted_by)->toBe($this->maker->id)
        ->and($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Pending);

    Event::assertDispatched(ApprovalRequested::class, 1);
});

it('creates one row per configured level', function () {
    twoLevelChain();

    $levels = $this->service->submit($this->doc, $this->maker);

    expect($levels->pluck('level')->all())->toBe([1, 2])
        ->and($levels->pluck('approver_role')->all())->toBe(['Accounts User', 'Approver']);
});

it('cannot be submitted twice or once approved', function () {
    $this->service->submit($this->doc, $this->maker);
    $this->service->submit($this->doc, $this->maker);
})->throws(ApprovalStateException::class, 'draft or rejected');

it('refuses to submit a document that is not ready', function () {
    $this->doc->forceFill(['remarks' => 'incomplete'])->save();

    try {
        $this->service->submit($this->doc, $this->maker);
    } catch (ApprovalStateException $e) {
        expect($e->getMessage())->toBe('The document is not complete yet.')
            ->and($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Draft)
            ->and(Approval::withoutGlobalScopes()->count())->toBe(0);

        return;
    }

    $this->fail('Expected the submit hook to block the submission.');
});

it('never lets approval columns be mass assigned', function () {
    new TestDocument(['name' => 'Forged', 'approval_status' => 'approved']);
})->throws(MassAssignmentException::class);

// ---- approving ---------------------------------------------------------------------------------

it('approves a single-level document and stamps who and when', function () {
    $this->service->submit($this->doc, $this->maker);

    $this->service->approve($this->doc, $this->accounts, 'Looks fine');

    $doc = $this->doc->fresh();
    expect($doc->approval_status)->toBe(ApprovalStatus::Approved)
        ->and($doc->approved_by)->toBe($this->accounts->id)
        ->and($doc->approved_at)->not->toBeNull()
        ->and($doc->isApproved())->toBeTrue();

    $row = Approval::withoutGlobalScopes()->first();
    expect($row->status)->toBe(ApprovalLevelStatus::Approved)
        ->and($row->acted_by)->toBe($this->accounts->id)
        ->and($row->comment)->toBe('Looks fine')
        ->and($row->acted_at)->not->toBeNull();
});

it('fires DocumentApproved exactly once, only after the final level', function () {
    twoLevelChain();
    $this->service->submit($this->doc, $this->maker);

    Event::fake([DocumentApproved::class, ApprovalRequested::class]);

    $this->service->approve($this->doc, $this->accounts);
    Event::assertNotDispatched(DocumentApproved::class);
    Event::assertDispatched(ApprovalRequested::class, 1); // level 2 is now waiting

    $this->service->approve($this->doc, $this->approver);
    Event::assertDispatched(DocumentApproved::class, 1);
    expect($this->doc->fresh()->isApproved())->toBeTrue();
});

it('cannot be approved a second time', function () {
    $this->service->submit($this->doc, $this->maker);
    $this->service->approve($this->doc, $this->accounts);

    Event::fake([DocumentApproved::class]);

    expect(fn () => $this->service->approve($this->doc, $this->approver))
        ->toThrow(ApprovalStateException::class, 'no pending approval');

    Event::assertNotDispatched(DocumentApproved::class);
});

it('decides levels strictly in order', function () {
    twoLevelChain();
    $this->service->submit($this->doc, $this->maker);

    // The level-2 approver cannot jump the queue.
    expect(fn () => $this->service->approve($this->doc, $this->approver))->toThrow(AuthorizationException::class);
    expect($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Pending);

    $this->service->approve($this->doc, $this->accounts);
    expect($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Pending);

    // ...and level 1's approver cannot approve level 2.
    expect(fn () => $this->service->approve($this->doc, $this->accounts))->toThrow(AuthorizationException::class);

    $this->service->approve($this->doc, $this->approver);
    expect($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Approved);
});

it('rejects a user who has no approve permission', function () {
    $this->service->submit($this->doc, $this->maker);
    $outsider = makeUser(['Tele-caller'], [$this->company]);

    expect(fn () => $this->service->approve($this->doc, $outsider))->toThrow(AuthorizationException::class, 'not an approver');
    expect($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Pending);
});

it('rejects an approver from another company', function () {
    $this->service->submit($this->doc, $this->maker);
    $globex = makeCompany('GLOBEX');
    $foreign = makeUser(['Accounts User'], [$globex]);

    expect(fn () => $this->service->approve($this->doc, $foreign))->toThrow(AuthorizationException::class, 'company');
});

it('rejects a deactivated approver', function () {
    $this->service->submit($this->doc, $this->maker);
    $this->accounts->forceFill(['is_active' => false])->save();

    expect(fn () => $this->service->approve($this->doc, $this->accounts->fresh()))->toThrow(AuthorizationException::class, 'deactivated');
});

it('lets a configured level name one specific user', function () {
    $row = new ApprovalLevel(['document_type' => 'item', 'level' => 1, 'approver_user_id' => $this->accounts->id]);
    $row->forceFill(['company_id' => $this->company->id])->save();

    $this->service->submit($this->doc, $this->maker);

    // $this->approver has the Approve permission but is not the named user.
    expect(fn () => $this->service->approve($this->doc, $this->approver))->toThrow(AuthorizationException::class);

    $this->service->approve($this->doc, $this->accounts);
    expect($this->doc->fresh()->isApproved())->toBeTrue();
});

it('lets a Super Admin decide any level', function () {
    twoLevelChain();
    $admin = makeUser(['Super Admin'], [$this->company]);

    $this->service->submit($this->doc, $this->maker);
    $this->service->approve($this->doc, $admin);
    $this->service->approve($this->doc, $admin);

    expect($this->doc->fresh()->isApproved())->toBeTrue();
});

// ---- maker / checker ---------------------------------------------------------------------------

it('blocks approving what you submitted yourself', function () {
    $both = makeUser(['Purchase User', 'Accounts User'], [$this->company]);

    $this->service->submit($this->doc, $both);

    expect(fn () => $this->service->approve($this->doc, $both))->toThrow(AuthorizationException::class, 'created or submitted');
});

it('blocks approving what you created, even when someone else submitted it', function () {
    $creator = makeUser(['Purchase User', 'Accounts User'], [$this->company]);
    $this->doc->forceFill(['created_by' => $creator->id])->save();

    $this->service->submit($this->doc, $this->maker);

    expect(fn () => $this->service->approve($this->doc, $creator))->toThrow(AuthorizationException::class, 'created or submitted');
});

it('allows self-approval when the company switches the control off', function () {
    app(SettingsService::class)->set('approvals.prevent_self_approval', false, $this->company->id);
    $both = makeUser(['Purchase User', 'Accounts User'], [$this->company]);

    $this->service->submit($this->doc, $both);
    $this->service->approve($this->doc, $both);

    expect($this->doc->fresh()->isApproved())->toBeTrue();
});

// ---- rejecting ---------------------------------------------------------------------------------

it('rejects with a comment, returning the document to its owner', function () {
    $this->service->submit($this->doc, $this->maker);
    Event::fake([DocumentRejected::class]);

    $this->service->reject($this->doc, $this->accounts, 'Wrong HSN code');

    $row = Approval::withoutGlobalScopes()->first();
    expect($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Rejected)
        ->and($this->doc->fresh()->approved_by)->toBeNull()
        ->and($row->status)->toBe(ApprovalLevelStatus::Rejected)
        ->and($row->comment)->toBe('Wrong HSN code')
        ->and($row->acted_by)->toBe($this->accounts->id);

    Event::assertDispatched(DocumentRejected::class, fn ($e) => $e->comment === 'Wrong HSN code' && $e->rejector->is($this->accounts));
});

it('requires a comment to reject', function () {
    $this->service->submit($this->doc, $this->maker);

    expect(fn () => $this->service->reject($this->doc, $this->accounts, '   '))
        ->toThrow(ApprovalStateException::class, 'comment is required');
    expect($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Pending);
});

it('cancels the levels still waiting when an earlier level rejects', function () {
    twoLevelChain();
    $this->service->submit($this->doc, $this->maker);

    $this->service->reject($this->doc, $this->accounts, 'Not now');

    expect(Approval::withoutGlobalScopes()->orderBy('level')->pluck('status')->map->value->all())
        ->toBe(['rejected', 'cancelled']);
});

it('keeps the full history when a rejected document is resubmitted', function () {
    $this->service->submit($this->doc, $this->maker);
    $this->service->reject($this->doc, $this->accounts, 'Fix the rate');

    $this->service->submit($this->doc->fresh(), $this->maker);
    $this->service->approve($this->doc->fresh(), $this->accounts, 'Now fine');

    $history = Approval::withoutGlobalScopes()->orderBy('cycle')->get();

    expect($history->pluck('cycle')->all())->toBe([1, 2])
        ->and($history->pluck('status')->map->value->all())->toBe(['rejected', 'approved'])
        ->and($history->pluck('comment')->all())->toBe(['Fix the rate', 'Now fine'])
        ->and($this->doc->fresh()->isApproved())->toBeTrue();
});

// ---- inbox, locking, notifications, audit ------------------------------------------------------

it('lists in the inbox only what the user can decide right now', function () {
    twoLevelChain();
    $this->service->submit($this->doc, $this->maker);

    expect($this->service->pendingFor($this->accounts))->toHaveCount(1)
        ->and($this->service->pendingFor($this->approver))->toHaveCount(0)   // level 2 is not actionable yet
        ->and($this->service->pendingFor($this->maker))->toHaveCount(0);

    $this->service->approve($this->doc, $this->accounts);

    expect($this->service->pendingFor($this->accounts))->toHaveCount(0)
        ->and($this->service->pendingFor($this->approver))->toHaveCount(1);
});

it('shows the inbox only documents of the active company and branches', function () {
    $this->service->submit($this->doc, $this->maker);

    $mum = makeLocation($this->company, 'MUM');
    $this->service->submit(makeDocument($this->company, $mum, ['name' => 'Mumbai item']), $this->maker);

    $ahdOnly = makeUser(['Accounts User'], [$this->company], locations: [$this->ahd]);
    $this->actingAs($ahdOnly);

    $titles = $this->service->pendingFor($ahdOnly)->map(fn ($a) => $a->approvable->name)->all();

    expect($titles)->toBe(['Widget']);
});

it('locks a document for editing while pending or approved', function () {
    $editor = makeUser(['Purchase User'], [$this->company]);

    expect(Gate::forUser($editor)->allows('update', $this->doc))->toBeTrue();

    $this->service->submit($this->doc, $this->maker);
    expect(Gate::forUser($editor)->allows('update', $this->doc->fresh()))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $this->doc->fresh()))->toBeFalse();

    $this->service->approve($this->doc, $this->accounts);
    expect(Gate::forUser($editor)->allows('update', $this->doc->fresh()))->toBeFalse();
});

it('unlocks a rejected document so it can be corrected', function () {
    $editor = makeUser(['Purchase User'], [$this->company]);

    $this->service->submit($this->doc, $this->maker);
    $this->service->reject($this->doc, $this->accounts, 'Please fix');

    expect(Gate::forUser($editor)->allows('update', $this->doc->fresh()))->toBeTrue();
});

it('only offers approved, active records to transactions', function () {
    $approved = makeDocument($this->company, $this->ahd, ['name' => 'Approved one']);
    $this->service->submit($approved, $this->maker);
    $this->service->approve($approved, $this->accounts);

    $inactive = makeDocument($this->company, $this->ahd, ['name' => 'Approved but inactive', 'status' => 'inactive']);
    $this->service->submit($inactive, $this->maker);
    $this->service->approve($inactive, $this->accounts);

    $this->service->submit($this->doc, $this->maker); // pending
    makeDocument($this->company, $this->ahd, ['name' => 'Draft']);

    expect(TestDocument::usable()->pluck('name')->all())->toBe(['Approved one']);
});

it('notifies the approvers when a document is submitted, but not the submitter', function () {
    Notification::fake();

    $this->service->submit($this->doc, $this->maker);

    Notification::assertSentTo($this->accounts, ApprovalRequestedNotification::class);
    Notification::assertSentTo($this->approver, ApprovalRequestedNotification::class);
    Notification::assertNotSentTo($this->maker, ApprovalRequestedNotification::class);
});

it('only notifies the approvers of the level that is waiting', function () {
    twoLevelChain();
    Notification::fake();

    $this->service->submit($this->doc, $this->maker);

    Notification::assertSentTo($this->accounts, ApprovalRequestedNotification::class);
    Notification::assertNotSentTo($this->approver, ApprovalRequestedNotification::class);

    $this->service->approve($this->doc, $this->accounts);
    Notification::assertSentTo($this->approver, ApprovalRequestedNotification::class);
});

it('tells the submitter how the document was decided', function () {
    Notification::fake();

    $this->service->submit($this->doc, $this->maker);
    $this->service->reject($this->doc, $this->accounts, 'Needs a datasheet');

    Notification::assertSentTo($this->maker, DocumentDecisionNotification::class,
        fn ($n) => ! $n->approved && $n->comment === 'Needs a datasheet' && $n->decidedBy === 'Accounts');

    $this->service->submit($this->doc->fresh(), $this->maker);
    $this->service->approve($this->doc->fresh(), $this->accounts);

    Notification::assertSentTo($this->maker, DocumentDecisionNotification::class, fn ($n) => $n->approved);
});

it('stores the notification in-app with a readable message', function () {
    $this->service->submit($this->doc, $this->maker);

    $data = $this->accounts->notifications()->first()->data;

    expect($data['kind'])->toBe('approval_requested')
        ->and($data['message'])->toContain('Maker')->toContain('Widget')
        ->and($data['url'])->toBe('/test-documents/'.$this->doc->id);
});

it('writes every submission and decision to the audit log with user and comment', function () {
    $this->service->submit($this->doc, $this->maker);
    $this->service->reject($this->doc, $this->accounts, 'Wrong colour');
    $this->service->submit($this->doc->fresh(), $this->maker);
    $this->service->approve($this->doc->fresh(), $this->accounts, 'OK');

    $log = Activity::query()->withoutGlobalScopes()
        ->where('log_name', 'approval')->orderBy('id')->get();

    expect($log->pluck('event')->all())->toBe(['submitted', 'rejected', 'submitted', 'approved'])
        ->and($log->pluck('causer_id')->all())->toBe([$this->maker->id, $this->accounts->id, $this->maker->id, $this->accounts->id])
        ->and($log->pluck('company_id')->unique()->all())->toBe([$this->company->id])
        ->and($log[1]->properties['comment'])->toBe('Wrong colour')
        ->and($log[1]->properties['old']['approval_status'])->toBe('pending')
        ->and($log[1]->properties['attributes']['approval_status'])->toBe('rejected');
});
