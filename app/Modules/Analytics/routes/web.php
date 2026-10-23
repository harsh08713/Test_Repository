<?php

use App\Modules\Analytics\Http\Livewire\AnalyticsPage;
use App\Modules\Analytics\Http\Livewire\ExecutiveDashboard;
use App\Modules\Analytics\Http\Livewire\IndustryDashboard;
use App\Modules\Analytics\Http\Middleware\AnswerAnalyticsJson;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::livewire('/executive', ExecutiveDashboard::class)->name('analytics.dashboard');
    Route::livewire('/analytics/{page}', AnalyticsPage::class)->middleware(AnswerAnalyticsJson::class)->where('page', '[a-z-]+')->name('analytics.page');
    Route::livewire('/industries/{industry:code}/dashboard', IndustryDashboard::class)->name('industries.dashboard');
});
