<?php

use App\Modules\Reports\Http\Controllers\ReportExportDownloadController;
use App\Modules\Reports\Http\Livewire\ReportCenter;
use App\Modules\Reports\Http\Livewire\ReportExportList;
use App\Modules\Reports\Http\Livewire\ReportRun;
use App\Modules\Reports\Http\Livewire\ScheduledReports;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('reports')->name('reports.')->group(function () {
    // Fixed paths come before {key}.
    Route::livewire('/', ReportCenter::class)->name('index');
    Route::livewire('/exports', ReportExportList::class)->name('exports');
    Route::livewire('/schedules', ScheduledReports::class)->name('schedules');
    Route::get('/exports/{export}/download', ReportExportDownloadController::class)->whereNumber('export')->name('exports.download');
    Route::livewire('/{key}', ReportRun::class)->where('key', '[a-z][a-z0-9-]*')->name('run');
});
