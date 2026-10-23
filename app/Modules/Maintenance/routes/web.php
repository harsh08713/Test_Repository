<?php

use App\Modules\Maintenance\Http\Livewire\AssetForm;
use App\Modules\Maintenance\Http\Livewire\AssetList;
use App\Modules\Maintenance\Http\Livewire\AssetShow;
use App\Modules\Maintenance\Http\Livewire\ScheduleForm;
use App\Modules\Maintenance\Http\Livewire\ScheduleList;
use App\Modules\Maintenance\Http\Livewire\WorkOrderForm;
use App\Modules\Maintenance\Http\Livewire\WorkOrderList;
use App\Modules\Maintenance\Http\Livewire\WorkOrderShow;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('maintenance')->name('maintenance.')->group(function () {
    // Fixed paths ("create") come before {asset} / {order}; an edit parameter is not named like a form property.
    Route::livewire('/orders', WorkOrderList::class)->name('orders.index');
    Route::livewire('/orders/create', WorkOrderForm::class)->name('orders.create');
    Route::livewire('/orders/{order}', WorkOrderShow::class)->whereNumber('order')->name('orders.show');

    Route::livewire('/assets', AssetList::class)->name('assets.index');
    Route::livewire('/assets/create', AssetForm::class)->name('assets.create');
    Route::livewire('/assets/{asset}', AssetShow::class)->whereNumber('asset')->name('assets.show');
    Route::livewire('/assets/{editing}/edit', AssetForm::class)->whereNumber('editing')->name('assets.edit');

    Route::livewire('/schedules', ScheduleList::class)->name('schedules.index');
    Route::livewire('/schedules/create', ScheduleForm::class)->name('schedules.create');
    Route::livewire('/schedules/{editing}/edit', ScheduleForm::class)->whereNumber('editing')->name('schedules.edit');
});
