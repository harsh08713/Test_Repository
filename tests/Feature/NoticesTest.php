<?php

use App\Modules\CRM\Http\Livewire\Masters\LostReasonList;
use App\Modules\Masters\Http\Livewire\DepartmentList;
use App\Modules\Masters\Http\Livewire\ItemGroupTree;
use App\Modules\Masters\Http\Livewire\Locations\LocationList;
use App\Modules\Shared\Http\Livewire\Settings\General;
use Livewire\Livewire;

/*
 * "Saved.", "Branch deleted.", "Lead transferred." — what an action has just done is said once, where the person is
 * looking: inside the page when only part of it was refreshed, and not a second time by the layout when the whole page
 * was loaded again.
 */

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->spare = makeLocation($this->acme, 'SPR');
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
});

it('says it once on a page that prints the message itself, and once on a page that leaves it to the layout', function () {
    $count = fn (string $route, string $message) => substr_count(
        $this->actingAs($this->admin)->withSession(['status' => $message])->get(route($route))->assertOk()->getContent(),
        e($message),
    );

    // pages with their own notice: the layout's stays quiet
    expect($count('crm.leads.index', 'Lead LD/AHD/26-27/00001 deleted.'))->toBe(1)
        ->and($count('masters.departments', 'Added Departments record.'))->toBe(1)
        ->and($count('admin.settings', 'Settings saved.'))->toBe(1)
        ->and($count('masters.locations.index', 'Branch “North” saved & ready.'))->toBe(1)     // escaping does not fool it
        // pages without one: the layout says it
        ->and($count('dashboard', 'Signed in to another company.'))->toBe(1)
        ->and($count('admin.users.index', 'User added.'))->toBe(1);

    // and with nothing to say, nothing is said
    $this->flushSession();
    $this->actingAs($this->admin)->get(route('crm.leads.index'))->assertOk()->assertDontSee('role="status"', false);
});

it('says what was done when only part of the page is refreshed', function () {
    // a plain master list
    Livewire::actingAs($this->admin)->test(DepartmentList::class)
        ->call('create')->set('values.code', 'PUR')->set('values.name', 'Purchase')->call('save')
        ->assertHasNoErrors()->assertSee('Added Departments record.');

    // the lead masters share that list
    Livewire::actingAs($this->admin)->test(LostReasonList::class)
        ->call('create')->set('values.code', 'CREDIT')->set('values.name', 'Credit terms')->call('save')
        ->assertHasNoErrors()->assertSee('Added Lost reasons record.');

    // a tree of groups
    Livewire::actingAs($this->admin)->test(ItemGroupTree::class)
        ->call('create')->set('values.code', 'RM')->set('values.name', 'Raw material')->call('save')
        ->assertHasNoErrors()->assertSee('Group added.');

    // the branch list
    Livewire::actingAs($this->admin)->test(LocationList::class)->call('delete', $this->spare->id)->assertHasNoErrors()->assertSee('Branch deleted.');

    // company settings
    Livewire::actingAs($this->admin)->test(General::class)->call('save')->assertHasNoErrors()->assertSee('Settings saved.');
});
