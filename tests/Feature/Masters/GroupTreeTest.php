<?php

use App\Modules\Masters\Enums\AccountNature;
use App\Modules\Masters\Http\Livewire\AccountGroupTree;
use App\Modules\Masters\Http\Livewire\ItemGroupTree;
use App\Modules\Masters\Models\AccountGroup;
use App\Modules\Masters\Models\ItemGroup;
use App\Modules\Shared\Exceptions\TenantAccessException;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);

    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);

    foreach (['account_group', 'item_group'] as $form) {
        Role::findOrCreate('Viewer '.$form)->givePermissionTo("{$form}.view");
    }
});

function account(string $code, $company = null): AccountGroup
{
    return AccountGroup::withoutGlobalScopes(Tenancy::SCOPES)->where('code', $code)->where('company_id', ($company ?? test()->acme)->id)->firstOrFail();
}

/** Create a group through the screen: top-level when $parentId is null, else a child of it. */
function addGroup($component, ?int $parentId, string $code, string $name, array $more = [])
{
    $component->call('create', $parentId)->set('values.code', $code)->set('values.name', $name);
    foreach ($more as $field => $value) {
        $component->set("values.{$field}", $value);
    }

    return $component->call('save');
}

function itemGroup(string $code, $company = null): ItemGroup
{
    return ItemGroup::withoutGlobalScopes(Tenancy::SCOPES)->where('code', $code)->where('company_id', ($company ?? test()->acme)->id)->firstOrFail();
}

// ======================================================================================================
// Behaviour shared by both trees
// ======================================================================================================

dataset('trees', [
    'chart of accounts' => [AccountGroupTree::class, 'account_group'],
    'item groups' => [ItemGroupTree::class, 'item_group'],
]);

function newRoot(string $tree, $admin, string $code, string $name)
{
    $more = $tree === AccountGroupTree::class ? ['nature' => 'asset'] : [];

    return addGroup(Livewire::actingAs($admin)->test($tree), null, $code, $name, $more);
}

function find(string $tree, string $code, $company = null)
{
    return $tree === AccountGroupTree::class ? account($code, $company) : itemGroup($code, $company);
}

it('adds a top-level group and a child beneath it', function (string $tree) {
    newRoot($tree, $this->admin, 'RM', 'Raw material')->assertHasNoErrors();
    $root = find($tree, 'RM');

    addGroup(Livewire::actingAs($this->admin)->test($tree), $root->id, 'RM-STL', 'Steel')->assertHasNoErrors();

    $child = find($tree, 'RM-STL');
    expect($root->parent_id)->toBeNull()->and($child->parent_id)->toBe($root->id)
        ->and($child->company_id)->toBe($this->acme->id);

    Livewire::actingAs($this->admin)->test($tree)->assertSeeInOrder(['RM', 'Raw material', 'RM-STL', 'Steel']);
})->with('trees');

it('upper-cases codes and keeps them unique within the company', function (string $tree) {
    newRoot($tree, $this->admin, 'dup', 'First');
    expect(find($tree, 'DUP')->name)->toBe('First');

    addGroup(Livewire::actingAs($this->admin)->test($tree), null, 'DUP', 'Second', $tree === AccountGroupTree::class ? ['nature' => 'asset'] : [])
        ->assertHasErrors(['values.code']);

    // another company may use the same code
    $globexRoot = $tree === AccountGroupTree::class ? ['nature' => 'asset'] : [];
    addGroup(Livewire::actingAs($this->globexAdmin)->test($tree), null, 'DUP', 'Globex', $globexRoot)->assertHasNoErrors();
})->with('trees');

it('rejects malformed codes and missing names', function (string $tree, string $form, string $code, string $name) {
    addGroup(Livewire::actingAs($this->admin)->test($tree), null, $code, $name, $tree === AccountGroupTree::class ? ['nature' => 'asset'] : [])->assertHasErrors();
})->with([
    'account: space' => [AccountGroupTree::class, 'account_group', 'A B', 'Name'],
    'account: no name' => [AccountGroupTree::class, 'account_group', 'ABC', ''],
    'item: symbol' => [ItemGroupTree::class, 'item_group', 'A!', 'Name'],
    'item: empty code' => [ItemGroupTree::class, 'item_group', '', 'Name'],
]);

it('nests ten levels deep and renders every level', function (string $tree) {
    newRoot($tree, $this->admin, 'L0', 'Level 0');
    $parent = find($tree, 'L0');

    foreach (range(1, 9) as $level) {
        addGroup(Livewire::actingAs($this->admin)->test($tree), $parent->id, "L{$level}", "Level {$level}")->assertHasNoErrors();
        $parent = find($tree, "L{$level}");
    }

    expect($parent->code)->toBe('L9');
    Livewire::actingAs($this->admin)->test($tree)->assertSeeInOrder(['Level 0', 'Level 4', 'Level 9']);
})->with('trees');

it('moves a group to a new parent and to the top level', function (string $tree) {
    newRoot($tree, $this->admin, 'A', 'Group A');
    newRoot($tree, $this->admin, 'B', 'Group B');
    addGroup(Livewire::actingAs($this->admin)->test($tree), find($tree, 'A')->id, 'A1', 'Child of A');
    $child = find($tree, 'A1');

    Livewire::actingAs($this->admin)->test($tree)->call('startMove', $child->id)->assertSet('moveOpen', true)
        ->set('newParentId', (string) find($tree, 'B')->id)->call('move')->assertHasNoErrors()->assertSet('moveOpen', false);
    expect($child->fresh()->parent_id)->toBe(find($tree, 'B')->id);

    Livewire::actingAs($this->admin)->test($tree)->call('startMove', $child->id)->set('newParentId', '')->call('move')->assertHasNoErrors();
    expect($child->fresh()->parent_id)->toBeNull();
})->with('trees');

it('refuses to move a group under itself or under its own descendants', function (string $tree) {
    newRoot($tree, $this->admin, 'TOP', 'Top');
    addGroup(Livewire::actingAs($this->admin)->test($tree), find($tree, 'TOP')->id, 'MID', 'Middle');
    addGroup(Livewire::actingAs($this->admin)->test($tree), find($tree, 'MID')->id, 'LOW', 'Lowest');
    $top = find($tree, 'TOP');

    foreach (['TOP', 'MID', 'LOW'] as $target) {
        Livewire::actingAs($this->admin)->test($tree)->call('startMove', $top->id)
            ->set('newParentId', (string) find($tree, $target)->id)->call('move')->assertHasErrors(['newParentId']);
    }

    expect($top->fresh()->parent_id)->toBeNull()
        ->and(find($tree, 'MID')->parent_id)->toBe($top->id);
})->with('trees');

it('does not even offer the forbidden parents in the move dialog', function (string $tree) {
    newRoot($tree, $this->admin, 'TOP', 'Top');
    addGroup(Livewire::actingAs($this->admin)->test($tree), find($tree, 'TOP')->id, 'MID', 'Middle');
    newRoot($tree, $this->admin, 'OTHER', 'Another root');

    $targets = Livewire::actingAs($this->admin)->test($tree)->call('startMove', find($tree, 'TOP')->id)
        ->instance()->render()->getData()['moveTargets'];

    expect(array_values($targets))->toContain('Another root')
        ->not->toContain('Top')->not->toContain('Top › Middle');
})->with('trees');

it('rejects a move to a group of another company', function (string $tree) {
    newRoot($tree, $this->admin, 'MINE', 'Mine');
    newRoot($tree, $this->globexAdmin, 'THEIRS', 'Theirs');

    Livewire::actingAs($this->admin)->test($tree)->call('startMove', find($tree, 'MINE')->id)
        ->set('newParentId', (string) find($tree, 'THEIRS', $this->globex)->id)->call('move')->assertHasErrors(['newParentId']);

    expect(find($tree, 'MINE')->parent_id)->toBeNull();
})->with('trees');

it('deletes a leaf but not a group that still has sub-groups', function (string $tree) {
    newRoot($tree, $this->admin, 'PARENT', 'Parent');
    addGroup(Livewire::actingAs($this->admin)->test($tree), find($tree, 'PARENT')->id, 'LEAF', 'Leaf');

    Livewire::actingAs($this->admin)->test($tree)->call('delete', find($tree, 'PARENT')->id)->assertHasErrors(['tree']);
    expect(find($tree, 'PARENT'))->not->toBeNull();

    Livewire::actingAs($this->admin)->test($tree)->call('delete', find($tree, 'LEAF')->id)->assertHasNoErrors();
    Livewire::actingAs($this->admin)->test($tree)->call('delete', find($tree, 'PARENT')->id)->assertHasNoErrors();

    expect(Livewire::actingAs($this->admin)->test($tree)->assertDontSee('Leaf'))->not->toBeNull();
    $model = $tree === AccountGroupTree::class ? AccountGroup::class : ItemGroup::class;
    expect($model::withoutGlobalScopes()->where('code', 'PARENT')->first()->trashed())->toBeTrue();
})->with('trees');

it('deactivates and reactivates a group', function (string $tree) {
    newRoot($tree, $this->admin, 'TOGGLE', 'Toggle me');
    $group = find($tree, 'TOGGLE');

    Livewire::actingAs($this->admin)->test($tree)->call('toggleStatus', $group->id);
    expect($group->fresh()->status)->toBe('inactive');

    Livewire::actingAs($this->admin)->test($tree)->assertSee('Inactive');

    Livewire::actingAs($this->admin)->test($tree)->call('toggleStatus', $group->id);
    expect($group->fresh()->status)->toBe('active');
})->with('trees');

it('edits a group’s name, status and remarks but not where it sits', function (string $tree) {
    newRoot($tree, $this->admin, 'KEEP', 'Original');
    addGroup(Livewire::actingAs($this->admin)->test($tree), find($tree, 'KEEP')->id, 'KID', 'Kid');
    $kid = find($tree, 'KID');

    Livewire::actingAs($this->admin)->test($tree)->call('edit', $kid->id)
        ->assertSet('values.name', 'Kid')
        ->set('values.name', 'Kid renamed')->set('values.remarks', 'a note')
        ->set('values.parent_id', '')                       // tampering: edit must not re-parent
        ->call('save')->assertHasNoErrors();

    expect($kid->fresh())->name->toBe('Kid renamed')->remarks->toBe('a note')->parent_id->toBe(find($tree, 'KEEP')->id);
})->with('trees');

// ---- permissions & isolation ------------------------------------------------------------------------

it('is read-only for someone who can only view', function (string $tree, string $form) {
    newRoot($tree, $this->admin, 'LOOK', 'Look');
    $group = find($tree, 'LOOK');
    $viewer = makeUser(['Viewer '.$form], [$this->acme]);

    Livewire::actingAs($viewer)->test($tree)->assertSee('Look')->assertDontSee('Add top-level group');
    Livewire::actingAs($viewer)->test($tree)->call('create')->assertForbidden();
    Livewire::actingAs($viewer)->test($tree)->call('edit', $group->id)->assertForbidden();
    Livewire::actingAs($viewer)->test($tree)->call('startMove', $group->id)->assertForbidden();
    Livewire::actingAs($viewer)->test($tree)->call('toggleStatus', $group->id)->assertForbidden();
    Livewire::actingAs($viewer)->test($tree)->call('delete', $group->id)->assertForbidden();
})->with('trees');

it('shows nothing to someone without the view permission', function (string $tree) {
    Livewire::actingAs(makeUser(['Tele-caller'], [$this->acme]))->test($tree)->assertForbidden();
})->with('trees');

it('never shows or changes another company’s groups', function (string $tree) {
    newRoot($tree, $this->globexAdmin, 'SECRET', 'Globex secret group');
    $theirs = find($tree, 'SECRET', $this->globex);

    Livewire::actingAs($this->admin)->test($tree)->assertDontSee('Globex secret group');
    Livewire::actingAs($this->admin)->test($tree)->call('edit', $theirs->id)->assertNotFound();
    Livewire::actingAs($this->admin)->test($tree)->call('startMove', $theirs->id)->assertNotFound();
    Livewire::actingAs($this->admin)->test($tree)->call('toggleStatus', $theirs->id)->assertNotFound();
    Livewire::actingAs($this->admin)->test($tree)->call('delete', $theirs->id)->assertNotFound();
    Livewire::actingAs($this->admin)->test($tree)->call('create', $theirs->id)->assertNotFound();   // cannot add a child to it either
})->with('trees');

it('rejects a parent id from another company, even if the payload is tampered with', function (string $tree) {
    newRoot($tree, $this->globexAdmin, 'THEIRS', 'Theirs');
    $theirs = find($tree, 'THEIRS', $this->globex);

    $component = Livewire::actingAs($this->admin)->test($tree)->call('create')
        ->set('values.code', 'ROGUE')->set('values.name', 'Rogue child')->set('values.parent_id', (string) $theirs->id)
        ->set('values.nature', 'asset');
    $component->call('save')->assertHasErrors(['values.parent_id']);

    $model = $tree === AccountGroupTree::class ? AccountGroup::class : ItemGroup::class;
    expect($model::withoutGlobalScopes(Tenancy::SCOPES)->where('code', 'ROGUE')->exists())->toBeFalse();
})->with('trees');

it('has a model-level guard too, for code paths that skip the form', function () {
    $theirs = account('1000', $this->globex);

    $rogue = new AccountGroup(['code' => 'ROGUE', 'name' => 'Rogue', 'nature' => 'asset', 'parent_id' => $theirs->id]);
    $rogue->forceFill(['company_id' => $this->acme->id]);

    expect(fn () => $rogue->save())->toThrow(TenantAccessException::class, 'same company');
});

it('treats a deleted parent as missing', function () {
    $mid = AccountGroup::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('code', '1100')->firstOrFail();
    $mid->forceFill([])->delete();   // soft-delete "Current Assets"

    $orphan = new AccountGroup(['code' => 'X1', 'name' => 'Orphan', 'nature' => 'asset', 'parent_id' => $mid->id]);
    $orphan->forceFill(['company_id' => $this->acme->id]);

    expect(fn () => $orphan->save())->toThrow(TenantAccessException::class);
});

it('audits group changes against the company', function (string $tree) {
    newRoot($tree, $this->admin, 'AUD', 'Audited');
    $group = find($tree, 'AUD');
    Livewire::actingAs($this->admin)->test($tree)->call('toggleStatus', $group->id);
    Livewire::actingAs($this->admin)->test($tree)->call('delete', $group->id);

    $entries = Activity::query()->withoutGlobalScopes()->where('subject_id', $group->id)
        ->whereIn('subject_type', ['account_group', 'item_group'])->orderBy('id')->get();

    expect($entries->pluck('event')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($entries->pluck('company_id')->unique()->all())->toBe([$this->acme->id])
        ->and($entries[1]->properties['attributes']['status'])->toBe('inactive');
})->with('trees');

// ======================================================================================================
// Chart of accounts specifics
// ======================================================================================================

it('shows the standard Indian groups on the chart of accounts screen', function () {
    Livewire::actingAs($this->admin)->test(AccountGroupTree::class)
        ->assertSeeInOrder(['Assets', 'Current Assets', 'Bank Accounts', 'Cash-in-Hand', 'Sundry Debtors'])
        ->assertSeeInOrder(['Liabilities', 'Current Liabilities', 'Duties & Taxes', 'Sundry Creditors'])
        ->assertSee('Direct Expenses')->assertSee('Indirect Expenses')->assertSee('Standard');
});

it('makes a new group inherit its parent’s nature, whatever the browser sends', function () {
    addGroup(Livewire::actingAs($this->admin)->test(AccountGroupTree::class), account('1160')->id, 'DEBT-NORTH', 'North region debtors', ['nature' => 'income'])
        ->assertHasNoErrors();

    expect(account('DEBT-NORTH')->nature)->toBe(AccountNature::Asset);
});

it('requires a nature for a new top-level account group, and only accepts the four natures', function () {
    addGroup(Livewire::actingAs($this->admin)->test(AccountGroupTree::class), null, 'NEWROOT', 'New root')->assertHasErrors(['values.nature']);
    addGroup(Livewire::actingAs($this->admin)->test(AccountGroupTree::class), null, 'NEWROOT', 'New root', ['nature' => 'equity'])->assertHasErrors(['values.nature']);
    addGroup(Livewire::actingAs($this->admin)->test(AccountGroupTree::class), null, 'NEWROOT', 'New root', ['nature' => 'liability'])->assertHasNoErrors();

    expect(account('NEWROOT')->nature)->toBe(AccountNature::Liability);
});

it('lets a user group be created under a standard group, then edited and deleted', function () {
    addGroup(Livewire::actingAs($this->admin)->test(AccountGroupTree::class), account('1100')->id, 'CA-OTHER', 'Other current assets')->assertHasNoErrors();
    $mine = account('CA-OTHER');

    expect($mine->is_system)->toBeFalse()->and($mine->parent_id)->toBe(account('1100')->id);

    Livewire::actingAs($this->admin)->test(AccountGroupTree::class)->call('edit', $mine->id)->set('values.name', 'Other CA')->call('save')->assertHasNoErrors();
    Livewire::actingAs($this->admin)->test(AccountGroupTree::class)->call('delete', $mine->id)->assertHasNoErrors();

    expect(AccountGroup::query()->where('code', 'CA-OTHER')->exists())->toBeFalse();
});

it('fixes standard groups in place: only a remark can change', function () {
    $debtors = account('1160');

    Livewire::actingAs($this->admin)->test(AccountGroupTree::class)->call('edit', $debtors->id)
        ->assertSee('This is a standard group')
        ->set('values.name', 'Hacked')->set('values.code', 'HACK')->set('values.status', 'inactive')->set('values.remarks', 'Used for B2B customers')
        ->call('save')->assertHasNoErrors();

    expect($debtors->fresh())->name->toBe('Sundry Debtors')->code->toBe('1160')->status->toBe('active')->remarks->toBe('Used for B2B customers');
});

it('will not move, deactivate or delete a standard group', function () {
    $debtors = account('1160');

    Livewire::actingAs($this->admin)->test(AccountGroupTree::class)->call('startMove', $debtors->id)->assertHasErrors(['tree'])->assertSet('moveOpen', false);
    Livewire::actingAs($this->admin)->test(AccountGroupTree::class)->call('toggleStatus', $debtors->id)->assertHasErrors(['tree']);
    Livewire::actingAs($this->admin)->test(AccountGroupTree::class)->call('delete', $debtors->id)->assertHasErrors(['tree']);

    // even by calling move() directly with a crafted id
    Livewire::actingAs($this->admin)->test(AccountGroupTree::class)->set('movingId', $debtors->id)->set('newParentId', (string) account('1200')->id)
        ->call('move')->assertHasErrors(['newParentId']);

    expect($debtors->fresh())->parent_id->toBe(account('1100')->id)->status->toBe('active')->trashed()->toBeFalse();
});

it('will not move a group under a group of a different nature', function () {
    addGroup(Livewire::actingAs($this->admin)->test(AccountGroupTree::class), account('1100')->id, 'CA-X', 'Custom asset group');
    $custom = account('CA-X');

    Livewire::actingAs($this->admin)->test(AccountGroupTree::class)->call('startMove', $custom->id)
        ->set('newParentId', (string) account('4100')->id)->call('move')->assertHasErrors(['newParentId']);

    // within the same nature it is fine
    Livewire::actingAs($this->admin)->test(AccountGroupTree::class)->call('startMove', $custom->id)
        ->set('newParentId', (string) account('1200')->id)->call('move')->assertHasNoErrors();

    expect($custom->fresh()->parent_id)->toBe(account('1200')->id);
});

it('only lists same-nature groups in the move dialog', function () {
    addGroup(Livewire::actingAs($this->admin)->test(AccountGroupTree::class), account('1100')->id, 'CA-Y', 'Custom asset group');

    $targets = Livewire::actingAs($this->admin)->test(AccountGroupTree::class)->call('startMove', account('CA-Y')->id)
        ->instance()->render()->getData()['moveTargets'];

    expect(array_values($targets))->toContain('Assets › Fixed Assets')->not->toContain('Expenses › Direct Expenses');
});

it('does not let a root’s nature be changed by editing it', function () {
    addGroup(Livewire::actingAs($this->admin)->test(AccountGroupTree::class), null, 'MYROOT', 'My root', ['nature' => 'asset']);
    $root = account('MYROOT');

    Livewire::actingAs($this->admin)->test(AccountGroupTree::class)->call('edit', $root->id)->set('values.nature', 'expense')->call('save');

    expect($root->fresh()->nature)->toBe(AccountNature::Asset);
});

it('lets an Accounts user maintain the chart but not delete from it', function () {
    $accounts = makeUser(['Accounts User'], [$this->acme]);

    addGroup(Livewire::actingAs($accounts)->test(AccountGroupTree::class), account('1100')->id, 'ACC-1', 'By accounts')->assertHasNoErrors();
    Livewire::actingAs($accounts)->test(AccountGroupTree::class)->call('delete', account('ACC-1')->id)->assertForbidden();
});
