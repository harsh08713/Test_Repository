<?php

use App\Modules\Procurement\Http\Livewire\GrnForm;
use App\Modules\Procurement\Http\Livewire\GrnList;
use App\Modules\Procurement\Http\Livewire\GrnShow;
use App\Modules\Procurement\Http\Livewire\PurchaseOrderForm;
use App\Modules\Procurement\Http\Livewire\PurchaseOrderList;
use App\Modules\Procurement\Http\Livewire\PurchaseOrderShow;
use App\Modules\Procurement\Http\Livewire\ReorderList;
use Illuminate\Support\Facades\Route;

// Fixed paths come before the {id} ones; an edit parameter is not named like a form property.
Route::middleware('auth')->prefix('procurement')->name('procurement.')->group(function () {
    Route::livewire('/orders', PurchaseOrderList::class)->name('orders.index');
    Route::livewire('/orders/create', PurchaseOrderForm::class)->name('orders.create');
    Route::livewire('/orders/{purchaseOrder}', PurchaseOrderShow::class)->whereNumber('purchaseOrder')->name('orders.show');
    Route::livewire('/orders/{editing}/edit', PurchaseOrderForm::class)->whereNumber('editing')->name('orders.edit');

    Route::livewire('/receipts', GrnList::class)->name('grns.index');
    Route::livewire('/receipts/receive/{order}', GrnForm::class)->whereNumber('order')->name('grns.create');
    Route::livewire('/receipts/{grn}', GrnShow::class)->whereNumber('grn')->name('grns.show');
    Route::livewire('/receipts/{editing}/edit', GrnForm::class)->whereNumber('editing')->name('grns.edit');

    Route::livewire('/reorder', ReorderList::class)->name('reorder');
});
