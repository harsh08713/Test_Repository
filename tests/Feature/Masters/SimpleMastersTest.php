<?php

use App\Modules\Masters\Http\Livewire\DepartmentList;
use App\Modules\Masters\Http\Livewire\DesignationList;
use App\Modules\Masters\Http\Livewire\UnitList;
use App\Modules\Masters\Models\Department;
use App\Modules\Masters\Models\Designation;
use App\Modules\Masters\Models\Unit;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
 * Departments, designations and units share one list component, so the behaviour is tested once
 * and run against all three.
 */

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);

    // someone who may look but not touch
    foreach (['department', 'designation', 'unit'] as $form) {
        Role::findOrCreate('Viewer '.$form)->givePermissionTo("{$form}.view");
    }
});

function masterCases(): array
{
    return [
        'department' => [DepartmentList::class, Department::class, 'department'],
        'designation' => [DesignationList::class, Designation::class, 'designation'],
        'unit' => [UnitList::class, Unit::class, 'unit'],
    ];
}

function addMaster($component, string $code, string $name, array $more = [])
{
    $component->call('create')->set('values.code', $code)->set('values.name', $name);
    foreach ($more as $field => $value) {
        $component->set("values.{$field}", $value);
    }

    return $component->call('save');
}

function createdBy(string $model, string $code)
{
    return $model::withoutGlobalScopes(Tenancy::SCOPES)->where('code', $code)->first();
}

it('adds a record, upper-casing the code and tidying the name', function (string $list, string $model) {
    addMaster(Livewire::actingAs($this->admin)->test($list), ' pur.1 ', '  Purchase   &   Stores ')
        ->assertHasNoErrors()->assertSet('formOpen', false);

    $record = createdBy($model, 'PUR.1');

    expect($record->name)->toBe('Purchase & Stores')
        ->and($record->company_id)->toBe($this->acme->id)
        ->and($record->status)->toBe('active')
        ->and($record->created_by)->toBe($this->admin->id);
})->with(masterCases());

it('rejects a missing or malformed code, or a missing name', function (string $list, string $model, string $form, string $code, string $name) {
    addMaster(Livewire::actingAs($this->admin)->test($list), $code, $name)->assertHasErrors();

    expect($model::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
})->with(function () {
    foreach (masterCases() as $label => [$list, $model, $form]) {
        yield "{$label}: empty code" => [$list, $model, $form, '', 'Name'];
        yield "{$label}: space in code" => [$list, $model, $form, 'A B', 'Name'];
        yield "{$label}: leading dash" => [$list, $model, $form, '-AB', 'Name'];
        yield "{$label}: symbol" => [$list, $model, $form, 'AB!', 'Name'];
        yield "{$label}: too long" => [$list, $model, $form, str_repeat('A', 31), 'Name'];
        yield "{$label}: no name" => [$list, $model, $form, 'AB', ''];
    }
});

it('keeps codes unique within a company, including deleted records', function (string $list, string $model) {
    addMaster(Livewire::actingAs($this->admin)->test($list), 'DUP', 'First')->assertHasNoErrors();

    addMaster(Livewire::actingAs($this->admin)->test($list), 'dup', 'Second')->assertHasErrors(['values.code']);

    // the database key counts soft-deleted rows, so the rule must too — no surprise 500 later
    createdBy($model, 'DUP')->delete();
    addMaster(Livewire::actingAs($this->admin)->test($list), 'DUP', 'Third')->assertHasErrors(['values.code']);
})->with(masterCases());

it('allows the same code in another company', function (string $list, string $model) {
    addMaster(Livewire::actingAs($this->admin)->test($list), 'SAME', 'Acme version')->assertHasNoErrors();
    addMaster(Livewire::actingAs($this->globexAdmin)->test($list), 'SAME', 'Globex version')->assertHasNoErrors();

    expect($model::withoutGlobalScopes(Tenancy::SCOPES)->where('code', 'SAME')->count())->toBe(2);
})->with(masterCases());

it('edits a record without tripping over its own code', function (string $list, string $model) {
    addMaster(Livewire::actingAs($this->admin)->test($list), 'ONE', 'One');
    addMaster(Livewire::actingAs($this->admin)->test($list), 'TWO', 'Two');
    $one = createdBy($model, 'ONE');

    $component = Livewire::actingAs($this->admin)->test($list)->call('edit', $one->id)
        ->assertSet('values.code', 'ONE')->assertSet('values.name', 'One');

    $component->set('values.name', 'One (renamed)')->call('save')->assertHasNoErrors();
    expect($one->fresh()->name)->toBe('One (renamed)');

    // ...but cannot take another record's code
    Livewire::actingAs($this->admin)->test($list)->call('edit', $one->id)->set('values.code', 'TWO')->call('save')->assertHasErrors(['values.code']);
})->with(masterCases());

it('deactivates, reactivates and soft-deletes', function (string $list, string $model) {
    addMaster(Livewire::actingAs($this->admin)->test($list), 'GONE', 'Gone');
    $record = createdBy($model, 'GONE');

    Livewire::actingAs($this->admin)->test($list)->call('toggleStatus', $record->id);
    expect($record->fresh()->status)->toBe('inactive');

    Livewire::actingAs($this->admin)->test($list)->call('toggleStatus', $record->id);
    expect($record->fresh()->status)->toBe('active');

    Livewire::actingAs($this->admin)->test($list)->call('delete', $record->id)->assertHasNoErrors();
    expect($model::query()->whereKey($record->id)->exists())->toBeFalse()
        ->and($model::withoutGlobalScopes()->whereKey($record->id)->first()->trashed())->toBeTrue();
})->with(masterCases());

it('keeps line breaks in remarks', function (string $list, string $model) {
    addMaster(Livewire::actingAs($this->admin)->test($list), 'NOTE', 'With note', ['remarks' => "line one\nline two"]);

    expect(createdBy($model, 'NOTE')->remarks)->toBe("line one\nline two");
})->with(masterCases());

it('refuses an unknown status', function (string $list) {
    addMaster(Livewire::actingAs($this->admin)->test($list), 'ST', 'Status', ['status' => 'archived'])->assertHasErrors(['values.status']);
})->with(collect(masterCases())->map(fn ($c) => [$c[0]])->all());

it('searches and filters by status', function (string $list, string $model) {
    addMaster(Livewire::actingAs($this->admin)->test($list), 'ALPHA', 'Alpha team');
    addMaster(Livewire::actingAs($this->admin)->test($list), 'BETA', 'Beta team');
    createdBy($model, 'BETA')->update(['status' => 'inactive']);

    Livewire::actingAs($this->admin)->test($list)
        ->assertSee('Alpha team')->assertSee('Beta team')
        ->set('search', 'alpha')->assertSee('Alpha team')->assertDontSee('Beta team')
        ->set('search', 'BET')->assertSee('Beta team')->assertDontSee('Alpha team')
        ->set('search', '')->set('status', 'inactive')->assertSee('Beta team')->assertDontSee('Alpha team');
})->with(masterCases());

// ---- permissions -------------------------------------------------------------------------------------

it('lets a view-only user see the list but not change anything', function (string $list, string $model, string $form) {
    addMaster(Livewire::actingAs($this->admin)->test($list), 'LOOK', 'Look only');
    $record = createdBy($model, 'LOOK');
    $viewer = makeUser(['Viewer '.$form], [$this->acme]);

    $component = Livewire::actingAs($viewer)->test($list)->assertSee('Look only')->assertDontSee('Add');

    $component->call('create')->assertForbidden();
    Livewire::actingAs($viewer)->test($list)->call('edit', $record->id)->assertForbidden();
    Livewire::actingAs($viewer)->test($list)->call('toggleStatus', $record->id)->assertForbidden();
    Livewire::actingAs($viewer)->test($list)->call('delete', $record->id)->assertForbidden();

    expect($record->fresh()->status)->toBe('active')->and($record->fresh()->trashed())->toBeFalse();
})->with(masterCases());

it('shows nothing to someone without the view permission', function (string $list) {
    Livewire::actingAs(makeUser(['Tele-caller'], [$this->acme]))->test($list)->assertForbidden();
})->with(collect(masterCases())->map(fn ($c) => [$c[0]])->all());

it('refuses a hand-crafted save from a user who cannot create', function (string $list, string $model, string $form) {
    $viewer = makeUser(['Viewer '.$form], [$this->acme]);

    Livewire::actingAs($viewer)->test($list)->set('values.code', 'EVIL')->set('values.name', 'Evil')->call('save')->assertForbidden();

    expect($model::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
})->with(masterCases());

// ---- tenant isolation --------------------------------------------------------------------------------

it('never shows or touches another company’s records', function (string $list, string $model) {
    addMaster(Livewire::actingAs($this->globexAdmin)->test($list), 'THEIRS', 'Globex secret');
    $theirs = createdBy($model, 'THEIRS');

    Livewire::actingAs($this->admin)->test($list)->assertDontSee('Globex secret');

    // guessing the id gets a 404, not their data
    Livewire::actingAs($this->admin)->test($list)->call('edit', $theirs->id)->assertNotFound();
    Livewire::actingAs($this->admin)->test($list)->call('toggleStatus', $theirs->id)->assertNotFound();
    Livewire::actingAs($this->admin)->test($list)->call('delete', $theirs->id)->assertNotFound();

    expect($theirs->fresh()->status)->toBe('active')->and($theirs->fresh()->trashed())->toBeFalse();
})->with(masterCases());

it('cannot be pushed into another company by editing the form payload', function (string $list, string $model) {
    // company_id is not a field, so a tampered value is ignored and the record still lands in the user's company
    $component = Livewire::actingAs($this->admin)->test($list)->call('create')
        ->set('values.code', 'TAMP')->set('values.name', 'Tampered')->set('values.company_id', $this->globex->id);
    $component->call('save');

    expect(createdBy($model, 'TAMP')->company_id)->toBe($this->acme->id);
})->with(masterCases());

// ---- audit ---------------------------------------------------------------------------------------------

it('audits create, update and delete with the acting user and company', function (string $list, string $model) {
    addMaster(Livewire::actingAs($this->admin)->test($list), 'AUD', 'Audited');
    $record = createdBy($model, 'AUD');
    Livewire::actingAs($this->admin)->test($list)->call('edit', $record->id)->set('values.name', 'Audited v2')->call('save');
    Livewire::actingAs($this->admin)->test($list)->call('delete', $record->id);

    $entries = Activity::query()->withoutGlobalScopes()->where('subject_id', $record->id)
        ->whereIn('subject_type', ['department', 'designation', 'unit'])->orderBy('id')->get();

    expect($entries->pluck('event')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($entries->pluck('causer_id')->unique()->all())->toBe([$this->admin->id])
        ->and($entries->pluck('company_id')->unique()->all())->toBe([$this->acme->id])
        ->and($entries[1]->properties['old']['name'])->toBe('Audited')
        ->and($entries[1]->properties['attributes']['name'])->toBe('Audited v2');
})->with(masterCases());

// ---- units only ------------------------------------------------------------------------------------------

it('records whether a unit allows decimal quantities', function () {
    addMaster(Livewire::actingAs($this->admin)->test(UnitList::class), 'KGS', 'Kilograms', ['decimal_allowed' => true]);
    addMaster(Livewire::actingAs($this->admin)->test(UnitList::class), 'CTN', 'Cartons');

    expect(createdBy(Unit::class, 'KGS')->decimal_allowed)->toBeTrue()
        ->and(createdBy(Unit::class, 'CTN')->decimal_allowed)->toBeFalse();

    Livewire::actingAs($this->admin)->test(UnitList::class)->assertSee('Decimals allowed')->assertSeeInOrder(['KGS', 'Yes'])->assertSeeInOrder(['CTN', 'No']);
});

it('shows a unit’s decimal flag when editing it', function () {
    addMaster(Livewire::actingAs($this->admin)->test(UnitList::class), 'MTRS', 'Metres', ['decimal_allowed' => true]);

    Livewire::actingAs($this->admin)->test(UnitList::class)->call('edit', createdBy(Unit::class, 'MTRS')->id)
        ->assertSet('values.decimal_allowed', true)
        ->set('values.decimal_allowed', false)->call('save');

    expect(createdBy(Unit::class, 'MTRS')->decimal_allowed)->toBeFalse();
});
