<?php

use App\Modules\Production\Http\Livewire\BomForm;
use App\Modules\Production\Http\Livewire\BomList;
use App\Modules\Production\Http\Livewire\BomShow;
use App\Modules\Production\Http\Livewire\ProductionOrderForm;
use App\Modules\Production\Http\Livewire\ProductionOrderList;
use App\Modules\Production\Http\Livewire\ProductionOrderShow;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('production')->name('production.')->group(function () {
    // Fixed paths ("create") come before {bom} / {order}; an edit parameter is not named like a form property.
    Route::livewire('/boms', BomList::class)->name('boms.index');
    Route::livewire('/boms/create', BomForm::class)->name('boms.create');
    Route::livewire('/boms/{bom}', BomShow::class)->whereNumber('bom')->name('boms.show');
    Route::livewire('/boms/{editing}/edit', BomForm::class)->whereNumber('editing')->name('boms.edit');

    Route::livewire('/orders', ProductionOrderList::class)->name('orders.index');
    Route::livewire('/orders/create', ProductionOrderForm::class)->name('orders.create');
    Route::livewire('/orders/{order}', ProductionOrderShow::class)->whereNumber('order')->name('orders.show');
    Route::livewire('/orders/{editing}/edit', ProductionOrderForm::class)->whereNumber('editing')->name('orders.edit');
});
