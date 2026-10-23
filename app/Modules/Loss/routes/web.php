<?php

use App\Modules\Loss\Http\Livewire\CorrectiveActionList;
use App\Modules\Loss\Http\Livewire\LossIncidentForm;
use App\Modules\Loss\Http\Livewire\LossIncidentList;
use App\Modules\Loss\Http\Livewire\LossIncidentShow;
use App\Modules\Loss\Http\Livewire\RadarFindingList;
use App\Modules\Loss\Http\Livewire\SavingsList;
use App\Modules\Loss\Http\Livewire\SavingsShow;
use Illuminate\Support\Facades\Route;

// Fixed paths come before {incident}; the edit parameter is not named like a form property.
Route::middleware('auth')->prefix('losses')->name('loss.')->group(function () {
    Route::livewire('/', LossIncidentList::class)->name('incidents.index');
    Route::livewire('/report', LossIncidentForm::class)->name('incidents.create');
    Route::livewire('/actions', CorrectiveActionList::class)->name('actions.index');
    Route::livewire('/radar', RadarFindingList::class)->name('radar');
    Route::livewire('/savings', SavingsList::class)->name('savings');
    Route::livewire('/savings/{measurement}', SavingsShow::class)->whereNumber('measurement')->name('savings.show');
    Route::livewire('/{incident}', LossIncidentShow::class)->whereNumber('incident')->name('incidents.show');
    Route::livewire('/{editing}/edit', LossIncidentForm::class)->whereNumber('editing')->name('incidents.edit');
});
