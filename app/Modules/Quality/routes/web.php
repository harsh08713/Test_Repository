<?php

use App\Modules\Quality\Http\Livewire\InspectionForm;
use App\Modules\Quality\Http\Livewire\InspectionList;
use App\Modules\Quality\Http\Livewire\InspectionShow;
use App\Modules\Quality\Http\Livewire\NcrForm;
use App\Modules\Quality\Http\Livewire\NcrList;
use App\Modules\Quality\Http\Livewire\NcrShow;
use App\Modules\Quality\Http\Livewire\PlanForm;
use App\Modules\Quality\Http\Livewire\PlanList;
use App\Modules\Quality\Http\Livewire\PlanShow;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('quality')->name('quality.')->group(function () {
    // Fixed paths ("create") come before {plan} / {inspection} / {ncr}; an edit parameter is not named like a form property.
    Route::livewire('/plans', PlanList::class)->name('plans.index');
    Route::livewire('/plans/create', PlanForm::class)->name('plans.create');
    Route::livewire('/plans/{plan}', PlanShow::class)->whereNumber('plan')->name('plans.show');
    Route::livewire('/plans/{editing}/edit', PlanForm::class)->whereNumber('editing')->name('plans.edit');

    Route::livewire('/inspections', InspectionList::class)->name('inspections.index');
    Route::livewire('/inspections/create', InspectionForm::class)->name('inspections.create');
    Route::livewire('/inspections/{inspection}', InspectionShow::class)->whereNumber('inspection')->name('inspections.show');

    Route::livewire('/non-conformances', NcrList::class)->name('ncrs.index');
    Route::livewire('/non-conformances/create', NcrForm::class)->name('ncrs.create');
    Route::livewire('/non-conformances/{ncr}', NcrShow::class)->whereNumber('ncr')->name('ncrs.show');
});
