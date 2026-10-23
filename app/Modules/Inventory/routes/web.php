<?php

use App\Modules\Inventory\Http\Livewire\BatchList;
use App\Modules\Inventory\Http\Livewire\StockBalanceList;
use App\Modules\Inventory\Http\Livewire\StockEntryForm;
use App\Modules\Inventory\Http\Livewire\StockLedgerList;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('inventory')->name('inventory.')->group(function () {
    Route::livewire('/stock', StockBalanceList::class)->name('stock');
    Route::livewire('/movements', StockLedgerList::class)->name('movements');
    Route::livewire('/batches', BatchList::class)->name('batches');
    Route::livewire('/entry', StockEntryForm::class)->name('entry');
});
