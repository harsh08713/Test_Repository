<?php

use App\Modules\Integrations\Http\Livewire\IntegrationList;
use App\Modules\Integrations\Http\Livewire\LeadLogList;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('/integrations', IntegrationList::class)->name('integrations');
    Route::livewire('/integrations/leads', LeadLogList::class)->name('integrations.leads');
});
