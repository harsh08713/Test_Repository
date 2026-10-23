<?php

use App\Modules\Shared\Http\Controllers\ContextController;
use App\Modules\Shared\Http\Controllers\ImportDownloadController;
use App\Modules\Shared\Http\Controllers\LogoutController;
use App\Modules\Shared\Http\Livewire\ApprovalInbox;
use App\Modules\Shared\Http\Livewire\Audit;
use App\Modules\Shared\Http\Livewire\Auth\Login;
use App\Modules\Shared\Http\Livewire\Dashboard;
use App\Modules\Shared\Http\Livewire\Roles\PermissionMatrix;
use App\Modules\Shared\Http\Livewire\Settings;
use App\Modules\Shared\Http\Livewire\SystemHealth;
use App\Modules\Shared\Http\Livewire\Users;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::livewire('/login', Login::class)->name('login');
});

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/dashboard');

    Route::livewire('/dashboard', Dashboard::class)->name('dashboard');
    Route::livewire('/approvals', ApprovalInbox::class)->name('approvals.index');

    Route::post('/logout', LogoutController::class)->name('logout');
    Route::post('/context', ContextController::class)->name('context.switch');

    Route::get('/imports/{importer}/template', [ImportDownloadController::class, 'template'])->name('imports.template');
    Route::get('/imports/{batch}/errors', [ImportDownloadController::class, 'errors'])->whereNumber('batch')->name('imports.errors');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::livewire('/users', Users\UserList::class)->name('users.index');
        Route::livewire('/users/create', Users\Form::class)->name('users.create');
        Route::livewire('/users/{user}/edit', Users\Form::class)->name('users.edit');

        Route::livewire('/roles', PermissionMatrix::class)->name('roles');

        Route::livewire('/settings', Settings\General::class)->name('settings');
        Route::livewire('/settings/approval-levels', Settings\ApprovalLevels::class)->name('approval-levels');
        Route::livewire('/settings/number-series', Settings\NumberSeriesIndex::class)->name('number-series');

        Route::livewire('/audit-log', Audit\AuditLogList::class)->name('audit-log');
        Route::livewire('/health', SystemHealth::class)->name('health');
    });
});
