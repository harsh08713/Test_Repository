<?php

use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Http\Controllers\CompanyLogoController;
use App\Modules\Masters\Http\Controllers\ItemLookupController;
use App\Modules\Masters\Http\Controllers\LedgerLookupController;
use App\Modules\Masters\Http\Controllers\RateLookupController;
use App\Modules\Masters\Http\Livewire\AccountGroupTree;
use App\Modules\Masters\Http\Livewire\BrandList;
use App\Modules\Masters\Http\Livewire\Companies;
use App\Modules\Masters\Http\Livewire\DepartmentList;
use App\Modules\Masters\Http\Livewire\DesignationList;
use App\Modules\Masters\Http\Livewire\Geography;
use App\Modules\Masters\Http\Livewire\ItemGroupTree;
use App\Modules\Masters\Http\Livewire\Items;
use App\Modules\Masters\Http\Livewire\Ledgers;
use App\Modules\Masters\Http\Livewire\Locations;
use App\Modules\Masters\Http\Livewire\PaymentMethodList;
use App\Modules\Masters\Http\Livewire\PriceListList;
use App\Modules\Masters\Http\Livewire\Rates;
use App\Modules\Masters\Http\Livewire\UnitList;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('masters')->name('masters.')->group(function () {
    Route::livewire('/companies', Companies\CompanyList::class)->name('companies.index');
    Route::livewire('/companies/create', Companies\CompanyForm::class)->name('companies.create');
    Route::livewire('/companies/{company}/edit', Companies\CompanyForm::class)->name('companies.edit');
    Route::get('/companies/{company}/logo', CompanyLogoController::class)->name('companies.logo');

    Route::livewire('/locations', Locations\LocationList::class)->name('locations.index');
    Route::livewire('/locations/create', Locations\LocationForm::class)->name('locations.create');
    Route::livewire('/locations/{location}/edit', Locations\LocationForm::class)->name('locations.edit');

    Route::livewire('/account-groups', AccountGroupTree::class)->name('account-groups');
    Route::livewire('/item-groups', ItemGroupTree::class)->name('item-groups');
    Route::livewire('/departments', DepartmentList::class)->name('departments');
    Route::livewire('/designations', DesignationList::class)->name('designations');
    Route::livewire('/units', UnitList::class)->name('units');
    Route::livewire('/payment-methods', PaymentMethodList::class)->name('payment-methods');
    Route::livewire('/price-lists', PriceListList::class)->name('price-lists');
    Route::livewire('/brands', BrandList::class)->name('brands');

    // Rates. The lookup and create routes come before {rate}; the edit parameter is not called rate (the form has a public rate property).
    Route::get('/rates/lookup', RateLookupController::class)->middleware('throttle:60,1')->name('rates.lookup');
    Route::livewire('/rates', Rates\RateList::class)->name('rates.index');
    Route::livewire('/rates/create', Rates\RateForm::class)->name('rates.create');
    Route::livewire('/rates/{rate}', Rates\RateShow::class)->whereNumber('rate')->name('rates.show');
    Route::livewire('/rates/{editing}/edit', Rates\RateForm::class)->whereNumber('editing')->name('rates.edit');

    // Items. The lookup and create routes come before {item}; the edit parameter is not called item (the form has a public item property).
    Route::get('/items/lookup', ItemLookupController::class)->middleware('throttle:60,1')->name('items.lookup');
    Route::livewire('/items', Items\ItemList::class)->name('items.index');
    Route::livewire('/items/create', Items\ItemForm::class)->name('items.create');
    Route::livewire('/items/{item}', Items\ItemShow::class)->whereNumber('item')->name('items.show');
    Route::livewire('/items/{editing}/edit', Items\ItemForm::class)->whereNumber('editing')->name('items.edit');

    // Ledgers. The lookup and create routes come before {ledger} so they are not read as an id.
    Route::get('/ledgers/lookup', LedgerLookupController::class)->middleware('throttle:60,1')->name('ledgers.lookup');
    Route::livewire('/ledgers', Ledgers\LedgerList::class)->name('ledgers.index');
    Route::livewire('/ledgers/create/{type}', Ledgers\LedgerForm::class)->whereIn('type', LedgerType::values())->name('ledgers.create');
    Route::livewire('/ledgers/{ledger}', Ledgers\LedgerShow::class)->whereNumber('ledger')->name('ledgers.show');
    // The edit route's parameter is not called "ledger": the form has a public `ledger` property and Livewire would try to assign the model to it.
    Route::livewire('/ledgers/{editing}/edit', Ledgers\LedgerForm::class)->whereNumber('editing')->name('ledgers.edit');

    Route::prefix('geography')->name('geography.')->group(function () {
        Route::livewire('/states', Geography\StateList::class)->name('states');
        Route::livewire('/districts', Geography\DistrictList::class)->name('districts');
        Route::livewire('/blocks', Geography\BlockList::class)->name('blocks');
        Route::livewire('/pin-codes', Geography\PinCodeList::class)->name('pin-codes');
        Route::livewire('/import', Geography\GeographyImport::class)->name('import');
    });
});
