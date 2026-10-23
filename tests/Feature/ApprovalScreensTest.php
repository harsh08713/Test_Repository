<?php

use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Http\Livewire\ApprovalInbox;
use App\Modules\Shared\Http\Livewire\ApprovalPanel;
use App\Modules\Shared\Services\ApprovalService;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = makeCompany('ACME');
    $this->ahd = makeLocation($this->company, 'AHD');

    $this->maker = makeUser(['Purchase User'], [$this->company], attributes: ['name' => 'Maker Mia']);
    $this->accounts = makeUser(['Accounts User'], [$this->company], attributes: ['name' => 'Accounts Abe']);
    $this->ops = makeUser(['Operations User'], [$this->company]);

    $this->doc = makeDocument($this->company, $this->ahd, ['name' => 'Gasket']);
    $this->props = ['type' => 'test_document', 'id' => $this->doc->id];
});

// ---- approval panel ------------------------------------------------------------------------------

it('shows the status and a submit button to someone who may edit a draft', function () {
    Livewire::actingAs($this->maker)->test(ApprovalPanel::class, $this->props)
        ->assertSee('Draft')->assertSee('Submit for approval')
        ->assertDontSee('Your decision is needed');
});

it('submits for approval from the panel', function () {
    Livewire::actingAs($this->maker)->test(ApprovalPanel::class, $this->props)
        ->call('submit')->assertHasNoErrors()
        ->assertSee('Pending approval')->assertDontSee('Submit for approval');

    expect($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Pending);
});

it('shows approve and reject only to the person who must decide', function () {
    app(ApprovalService::class)->submit($this->doc, $this->maker);

    Livewire::actingAs($this->accounts)->test(ApprovalPanel::class, $this->props)
        ->assertSee('Your decision is needed')->assertSee('Approve')->assertSee('Reject');

    Livewire::actingAs($this->maker)->test(ApprovalPanel::class, $this->props)
        ->assertDontSee('Your decision is needed');       // maker–checker

    Livewire::actingAs($this->ops)->test(ApprovalPanel::class, $this->props)
        ->assertDontSee('Your decision is needed')->assertDontSee('Submit for approval');
});

it('approves from the panel and records the comment in the history', function () {
    app(ApprovalService::class)->submit($this->doc, $this->maker);

    Livewire::actingAs($this->accounts)->test(ApprovalPanel::class, $this->props)
        ->set('comment', 'Spec matches the datasheet')->call('approve')
        ->assertHasNoErrors()
        ->assertSee('Approved')->assertSee('Spec matches the datasheet')->assertSee('Accounts Abe');

    expect($this->doc->fresh()->isApproved())->toBeTrue();
});

it('needs a reason to reject and then returns the document to its owner', function () {
    app(ApprovalService::class)->submit($this->doc, $this->maker);

    $panel = Livewire::actingAs($this->accounts)->test(ApprovalPanel::class, $this->props)
        ->call('reject')->assertHasErrors(['comment' => 'required']);
    expect($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Pending);

    $panel->set('comment', 'Wrong thread pitch')->call('reject')->assertHasNoErrors()->assertSee('Rejected');
    expect($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Rejected);

    // The owner can fix and resubmit.
    Livewire::actingAs($this->maker)->test(ApprovalPanel::class, $this->props)
        ->assertSee('This was rejected')->assertSee('Wrong thread pitch')->assertSee('Submit for approval');
});

it('shows a clear error instead of crashing when the action is no longer valid', function () {
    app(ApprovalService::class)->submit($this->doc, $this->maker);
    $stale = Livewire::actingAs($this->accounts)->test(ApprovalPanel::class, $this->props);

    app(ApprovalService::class)->approve($this->doc->fresh(), $this->accounts);   // someone beat them to it

    $stale->call('approve')->assertHasErrors(['approval']);
});

it('blocks a forged approve call from a user who may not decide', function () {
    app(ApprovalService::class)->submit($this->doc, $this->maker);

    // The buttons are hidden for these users, but the server must still refuse a hand-crafted call.
    Livewire::actingAs($this->ops)->test(ApprovalPanel::class, $this->props)->call('approve')->assertForbidden();
    Livewire::actingAs($this->maker)->test(ApprovalPanel::class, $this->props)->call('approve')->assertForbidden();

    expect($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Pending);
});

it('stops someone who may approve items from approving their own submission', function () {
    $both = makeUser(['Purchase User', 'Accounts User'], [$this->company]);
    $mine = makeDocument($this->company, $this->ahd, ['name' => 'Mine']);
    app(ApprovalService::class)->submit($mine, $both);

    Livewire::actingAs($both)->test(ApprovalPanel::class, ['type' => 'test_document', 'id' => $mine->id])
        ->assertDontSee('Your decision is needed')
        ->call('approve')->assertHasErrors(['approval']);

    expect($mine->fresh()->approval_status)->toBe(ApprovalStatus::Pending);
});

it('refuses to open a document the user cannot view or that is not approvable', function () {
    Livewire::actingAs(makeUser([], [$this->company]))->test(ApprovalPanel::class, $this->props)->assertForbidden();
    Livewire::actingAs($this->maker)->test(ApprovalPanel::class, ['type' => 'comment', 'id' => 1])->assertNotFound();
    Livewire::actingAs($this->maker)->test(ApprovalPanel::class, ['type' => 'nonsense', 'id' => 1])->assertNotFound();
});

it('does not reveal documents of another company', function () {
    $globex = makeCompany('GLOBEX');
    $foreign = makeUser(['Accounts User'], [$globex]);

    Livewire::actingAs($foreign)->test(ApprovalPanel::class, $this->props)->assertNotFound();
});

// ---- inbox ---------------------------------------------------------------------------------------

it('lists waiting documents for the person who can decide them', function () {
    app(ApprovalService::class)->submit($this->doc, $this->maker);

    Livewire::actingAs($this->accounts)->test(ApprovalInbox::class)
        ->assertSee('Gasket')->assertSee('Maker Mia')->assertSee('Approve');

    Livewire::actingAs($this->maker)->test(ApprovalInbox::class)
        ->assertDontSee('Gasket')->assertSee('Nothing is waiting for you.');
});

it('approves from the inbox', function () {
    app(ApprovalService::class)->submit($this->doc, $this->maker);

    $inbox = Livewire::actingAs($this->accounts)->test(ApprovalInbox::class);
    $approvalId = $inbox->instance()->items->first()->id;

    $inbox->call('approve', $approvalId)->assertHasNoErrors()->assertSee('Nothing is waiting for you.');

    expect($this->doc->fresh()->isApproved())->toBeTrue();
});

it('rejects from the inbox only with a reason', function () {
    app(ApprovalService::class)->submit($this->doc, $this->maker);

    $inbox = Livewire::actingAs($this->accounts)->test(ApprovalInbox::class);
    $approvalId = $inbox->instance()->items->first()->id;

    $inbox->call('startReject', $approvalId)->assertSet('deciding', $approvalId)
        ->call('reject')->assertHasErrors(['comment']);
    expect($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Pending);

    $inbox->set('comment', 'Duplicate of item 4')->call('reject')->assertHasNoErrors()->assertSet('deciding', null);
    expect($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Rejected);
});

it('cannot decide something that is not in the user’s own inbox', function () {
    app(ApprovalService::class)->submit($this->doc, $this->maker);
    $approvalId = Livewire::actingAs($this->accounts)->test(ApprovalInbox::class)->instance()->items->first()->id;

    Livewire::actingAs($this->ops)->test(ApprovalInbox::class)
        ->call('approve', $approvalId)->assertHasErrors(['inbox']);

    expect($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Pending);
});
