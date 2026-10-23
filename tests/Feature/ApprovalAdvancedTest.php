<?php

use App\Modules\Shared\Enums\ApprovalLevelStatus;
use App\Modules\Shared\Enums\ApprovalMode;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Events\ApprovalRequested;
use App\Modules\Shared\Events\DocumentApproved;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Http\Livewire\ApprovalPanel;
use App\Modules\Shared\Http\Livewire\Settings\ApprovalLevels;
use App\Modules\Shared\Models\Approval;
use App\Modules\Shared\Models\ApprovalLevel;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
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

function chainOf(array $levels): void
{
    foreach ($levels as $i => $level) {
        $row = new ApprovalLevel(['document_type' => 'item', 'level' => $i + 1, ...$level]);
        $row->forceFill(['company_id' => test()->company->id])->save();
    }
}

function twoRoles(int $accountsDue = 0, int $approverDue = 0): void
{
    chainOf([
        ['name' => 'Finance', 'approver_role' => 'Accounts User', 'due_hours' => $accountsDue ?: null],
        ['name' => 'Final', 'approver_role' => 'Approver', 'due_hours' => $approverDue ?: null],
    ]);
}

function setMode(ApprovalMode $mode): void
{
    app(SettingsService::class)->set(ApprovalMode::settingKey(DocumentType::Item), $mode->value, test()->company->id);
}

// ---- changed after submission --------------------------------------------------------------------

it('refuses to approve a document that was edited after it was submitted', function () {
    $this->service->submit($this->doc, $this->maker);

    // An edit that slipped past the UI (a script, a console session, a bug elsewhere).
    DB::table('test_documents')->where('id', $this->doc->id)->update(['name' => 'Widget (cheaper material)']);

    expect(fn () => $this->service->approve($this->doc, $this->accounts))
        ->toThrow(ApprovalStateException::class, 'changed after it was submitted');

    expect($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Pending)
        ->and(Approval::withoutGlobalScopes()->sole()->status)->toBe(ApprovalLevelStatus::Pending);
});

it('reports a stale submission so the UI can warn about it', function () {
    $this->service->submit($this->doc, $this->maker);
    expect($this->service->changedSinceSubmission($this->doc->fresh()))->toBeFalse();

    DB::table('test_documents')->where('id', $this->doc->id)->update(['remarks' => 'edited']);

    expect($this->service->changedSinceSubmission($this->doc->fresh()))->toBeTrue();
});

it('lets the approver reject a stale document, and approves the corrected resubmission', function () {
    $this->service->submit($this->doc, $this->maker);
    DB::table('test_documents')->where('id', $this->doc->id)->update(['name' => 'Widget v2']);

    $this->service->reject($this->doc->fresh(), $this->accounts, 'Edited after submission — please resubmit');

    expect($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Rejected);

    $this->service->submit($this->doc->fresh(), $this->maker);
    $this->service->approve($this->doc->fresh(), $this->accounts, 'Reviewed v2');

    expect($this->doc->fresh()->isApproved())->toBeTrue()
        ->and($this->doc->fresh()->name)->toBe('Widget v2');
});

it('does not treat the workflow’s own changes as an edit', function () {
    twoRoles();
    $this->service->submit($this->doc, $this->maker);
    $fingerprint = $this->doc->fresh()->approvalFingerprint();

    $this->service->approve($this->doc, $this->accounts);          // touches approval_status / updated_at only
    $this->service->approve($this->doc, $this->approver);

    expect($this->doc->fresh()->approvalFingerprint())->toBe($fingerprint);
});

it('fingerprints content, ignoring column order and bookkeeping columns', function () {
    $a = $this->doc->fresh();
    $before = $a->approvalFingerprint();

    DB::table('test_documents')->where('id', $a->id)->update(['updated_by' => $this->maker->id, 'updated_at' => now()->addDay()]);
    expect($a->fresh()->approvalFingerprint())->toBe($before);

    DB::table('test_documents')->where('id', $a->id)->update(['qty' => '5.500']);
    expect($a->fresh()->approvalFingerprint())->not->toBe($before);
});

it('shows a warning on the panel when the submitted document has changed', function () {
    $this->service->submit($this->doc, $this->maker);
    DB::table('test_documents')->where('id', $this->doc->id)->update(['name' => 'Tampered']);

    Livewire::actingAs($this->accounts)->test(ApprovalPanel::class, ['type' => 'test_document', 'id' => $this->doc->id])
        ->assertSee('changed after it was submitted')
        ->call('approve')->assertHasErrors(['approval']);
});

// ---- parallel levels -------------------------------------------------------------------------------

it('defaults to sequential levels', function () {
    expect(app(SettingsService::class)->get(ApprovalMode::settingKey(DocumentType::Item), $this->company->id))->toBe('sequential');
});

it('makes every level actionable at once in parallel mode, and approves after the last one', function () {
    twoRoles();
    setMode(ApprovalMode::Parallel);
    Event::fake([ApprovalRequested::class, DocumentApproved::class]);

    $rows = $this->service->submit($this->doc, $this->maker);

    expect($rows->pluck('is_parallel')->all())->toBe([true, true]);
    Event::assertDispatched(ApprovalRequested::class, 2);        // both levels asked at once

    // The second-level approver may go first.
    $this->service->approve($this->doc, $this->approver);
    expect($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Pending);
    Event::assertNotDispatched(DocumentApproved::class);

    $this->service->approve($this->doc, $this->accounts);
    expect($this->doc->fresh()->isApproved())->toBeTrue();
    Event::assertDispatched(DocumentApproved::class, 1);
});

it('lists a parallel document in every designated approver’s inbox until they have decided', function () {
    twoRoles();
    setMode(ApprovalMode::Parallel);
    $this->service->submit($this->doc, $this->maker);

    expect($this->service->pendingFor($this->accounts))->toHaveCount(1)
        ->and($this->service->pendingFor($this->approver))->toHaveCount(1);

    $this->service->approve($this->doc, $this->accounts);

    expect($this->service->pendingFor($this->accounts))->toHaveCount(0)
        ->and($this->service->pendingFor($this->approver))->toHaveCount(1);
});

it('rejects the whole document when any parallel level rejects', function () {
    twoRoles();
    setMode(ApprovalMode::Parallel);
    $this->service->submit($this->doc, $this->maker);

    $this->service->reject($this->doc, $this->approver, 'Over budget');

    expect($this->doc->fresh()->approval_status)->toBe(ApprovalStatus::Rejected)
        ->and(Approval::withoutGlobalScopes()->orderBy('level')->pluck('status')->map->value->all())->toBe(['cancelled', 'rejected']);
});

it('does not let one person decide a parallel level that is not theirs', function () {
    twoRoles();
    setMode(ApprovalMode::Parallel);
    $this->service->submit($this->doc, $this->maker);
    $outsider = makeUser(['Tele-caller'], [$this->company]);

    expect(fn () => $this->service->approve($this->doc, $outsider))->toThrow(AuthorizationException::class);
});

it('keeps the mode a document was submitted under even if the setting changes afterwards', function () {
    twoRoles();
    setMode(ApprovalMode::Parallel);
    $this->service->submit($this->doc, $this->maker);

    setMode(ApprovalMode::Sequential);

    // Still parallel for this submission: the level-2 approver can decide first.
    $this->service->approve($this->doc, $this->approver);

    expect(Approval::withoutGlobalScopes()->where('level', 2)->sole()->status)->toBe(ApprovalLevelStatus::Approved);

    // ...but the next submission follows the new setting.
    $other = makeDocument($this->company, $this->ahd, ['name' => 'Next']);
    $rows = $this->service->submit($other, $this->maker);
    expect($rows->pluck('is_parallel')->unique()->all())->toBe([false]);
});

it('keeps sequential levels in order, with only the first one actionable', function () {
    twoRoles();
    $this->service->submit($this->doc, $this->maker);

    expect(fn () => $this->service->approve($this->doc, $this->approver))->toThrow(AuthorizationException::class);
});

// ---- due dates -------------------------------------------------------------------------------------

it('starts the clock only on the level that is actionable', function () {
    $this->freezeTime();
    twoRoles(accountsDue: 24, approverDue: 48);

    $rows = $this->service->submit($this->doc, $this->maker);

    expect($rows[0]->due_at->toDateTimeString())->toBe(now()->addHours(24)->toDateTimeString())
        ->and($rows[1]->due_at)->toBeNull();                 // level 2 is not waiting yet

    $this->travel(5)->hours();
    $this->service->approve($this->doc, $this->accounts);

    expect(Approval::withoutGlobalScopes()->where('level', 2)->sole()->due_at->toDateTimeString())
        ->toBe(now()->addHours(48)->toDateTimeString());
});

it('starts every clock at once in parallel mode', function () {
    $this->freezeTime();
    twoRoles(accountsDue: 24, approverDue: 48);
    setMode(ApprovalMode::Parallel);

    $rows = $this->service->submit($this->doc, $this->maker);

    expect($rows[0]->due_at->toDateTimeString())->toBe(now()->addHours(24)->toDateTimeString())
        ->and($rows[1]->due_at->toDateTimeString())->toBe(now()->addHours(48)->toDateTimeString());
});

it('leaves due_at empty when no deadline is configured', function () {
    $rows = $this->service->submit($this->doc, $this->maker);

    expect($rows->first()->due_at)->toBeNull()->and($rows->first()->due_hours)->toBeNull();
});

// ---- configuring it from the screen --------------------------------------------------------------

it('saves the mode and deadlines from the Approval Levels screen', function () {
    $admin = makeUser(['Company Admin'], [$this->company]);

    Livewire::actingAs($admin)->test(ApprovalLevels::class)
        ->set('documentType', 'item')
        ->call('addLevel')->call('addLevel')
        ->set('levels.0.approver_role', 'Accounts User')->set('levels.0.due_hours', '24')
        ->set('levels.1.approver_role', 'Approver')
        ->set('mode', 'parallel')
        ->call('save')->assertHasNoErrors();

    $rows = ApprovalLevel::withoutGlobalScopes()->where('document_type', 'item')->orderBy('level')->get();

    expect($rows->pluck('due_hours')->all())->toBe([24, null])
        ->and(app(SettingsService::class)->get('approvals.mode.item', $this->company->id))->toBe('parallel');

    // ...and it is what the engine then uses.
    expect($this->service->submit($this->doc, $this->maker)->pluck('is_parallel')->all())->toBe([true, true]);
});

it('reloads the saved mode when the document type is switched', function () {
    setMode(ApprovalMode::Parallel);
    $admin = makeUser(['Company Admin'], [$this->company]);

    Livewire::actingAs($admin)->test(ApprovalLevels::class)
        ->set('documentType', 'item')->assertSet('mode', 'parallel')
        ->set('documentType', 'deal')->assertSet('mode', 'sequential');
});

it('validates the mode and the deadline', function () {
    $admin = makeUser(['Company Admin'], [$this->company]);

    Livewire::actingAs($admin)->test(ApprovalLevels::class)
        ->set('documentType', 'item')->call('addLevel')
        ->set('levels.0.approver_role', 'Approver')
        ->set('levels.0.due_hours', '0')->call('save')->assertHasErrors(['levels.0.due_hours'])
        ->set('levels.0.due_hours', '99999')->call('save')->assertHasErrors(['levels.0.due_hours'])
        ->set('levels.0.due_hours', '')->set('mode', 'chaotic')->call('save')->assertHasErrors(['mode']);
});

it('needs the configure permission to change approval levels, not just the edit permission', function () {
    Role::create(['name' => 'Settings editor'])->givePermissionTo(['setting.view', 'setting.edit']);
    $editor = makeUser(['Settings editor'], [$this->company]);

    Livewire::actingAs($editor)->test(ApprovalLevels::class)->call('addLevel')->assertForbidden();
});

it('refuses an unknown approval mode setting for a document type that does not exist', function () {
    expect(fn () => app(SettingsService::class)->get('approvals.mode.spaceship', $this->company->id))
        ->toThrow(InvalidArgumentException::class, 'Unknown setting');
});

it('uses the TestDocument model the mode tests rely on', function () {
    expect($this->doc)->toBeInstanceOf(TestDocument::class);
});
