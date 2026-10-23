<?php

use App\Modules\Industry\Http\Livewire\IndustryList;
use App\Modules\Industry\Http\Livewire\IndustryShow;
use Illuminate\Support\Facades\Route;

// The edit-free show route's parameter is not called "template": the component has a public property of its own name.
Route::middleware('auth')->prefix('industries')->name('industries.')->group(function () {
    Route::livewire('/', IndustryList::class)->name('index');
    Route::livewire('/{industry:code}', IndustryShow::class)->name('show');
});
