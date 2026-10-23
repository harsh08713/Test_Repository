<?php

use App\Modules\Masters\Http\Livewire\BrandList;
use App\Modules\Masters\Models\Brand;
use Livewire\Livewire;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
    $this->purchase = makeUser(['Purchase User'], [$this->acme]);
    $this->inventory = makeUser(['Inventory User'], [$this->acme]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
});

it('adds, edits and deactivates a brand inside the company', function () {
    Livewire::actingAs($this->admin)->test(BrandList::class)
        ->call('create')->set('values.code', ' ultra ')->set('values.name', 'UltraBolt')->set('values.description', 'Fasteners')->call('save')->assertHasNoErrors();

    $brand = Brand::query()->where('code', 'ULTRA')->firstOrFail();
    expect($brand)->name->toBe('UltraBolt')->company_id->toBe($this->acme->id)->status->toBe('active');

    Livewire::actingAs($this->admin)->test(BrandList::class)->call('edit', $brand->id)->set('values.name', 'UltraBolt Pro')->call('save')->assertHasNoErrors()
        ->call('toggleStatus', $brand->id);

    expect($brand->fresh())->name->toBe('UltraBolt Pro')->status->toBe('inactive');
    Livewire::actingAs($this->globexAdmin)->test(BrandList::class)->assertDontSee('UltraBolt');
});

it('keeps brand codes unique within a company but reusable in another', function () {
    Livewire::actingAs($this->admin)->test(BrandList::class)->call('create')->set('values.code', 'ACME1')->set('values.name', 'One')->call('save')->assertHasNoErrors();
    Livewire::actingAs($this->admin)->test(BrandList::class)->call('create')->set('values.code', 'acme1')->set('values.name', 'Two')->call('save')->assertHasErrors(['values.code']);
    Livewire::actingAs($this->globexAdmin)->test(BrandList::class)->call('create')->set('values.code', 'ACME1')->set('values.name', 'Other')->call('save')->assertHasNoErrors();
});

it('lets Purchase maintain brands, Inventory read them, and keeps everyone else out', function () {
    Livewire::actingAs($this->purchase)->test(BrandList::class)->assertOk()->call('create')->set('values.code', 'PUR')->set('values.name', 'Bought')->call('save')->assertHasNoErrors();
    Livewire::actingAs($this->inventory)->test(BrandList::class)->assertOk()->assertSee('Bought')->call('create')->assertForbidden();
    Livewire::actingAs($this->telecaller)->test(BrandList::class)->assertForbidden();
});
