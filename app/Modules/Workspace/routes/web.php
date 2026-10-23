<?php

use App\Modules\Workspace\Http\Controllers\TaskAttachmentController;
use App\Modules\Workspace\Http\Livewire\Customer360Page;
use App\Modules\Workspace\Http\Livewire\SlaBreachList;
use App\Modules\Workspace\Http\Livewire\SlaSettings;
use App\Modules\Workspace\Http\Livewire\TaskForm;
use App\Modules\Workspace\Http\Livewire\TaskList;
use App\Modules\Workspace\Http\Livewire\TaskShow;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Tasks: the fixed "create" path comes before {task}; an edit parameter is not named like a form property.
    Route::livewire('/tasks', TaskList::class)->name('workspace.tasks.index');
    Route::livewire('/tasks/create', TaskForm::class)->name('workspace.tasks.create');
    Route::livewire('/tasks/{task}', TaskShow::class)->whereNumber('task')->name('workspace.tasks.show');
    Route::livewire('/tasks/{editing}/edit', TaskForm::class)->whereNumber('editing')->name('workspace.tasks.edit');
    Route::get('/tasks/{task}/files/{attachment}', TaskAttachmentController::class)->whereNumber(['task', 'attachment'])->name('workspace.tasks.attachments.download');

    // SLA: the rules and holidays, and the log of what ran out.
    Route::livewire('/admin/sla', SlaSettings::class)->name('workspace.sla.policies');
    Route::livewire('/sla/breaches', SlaBreachList::class)->name('workspace.sla.breaches');

    // Customer 360.
    Route::livewire('/crm/customers/{customer}', Customer360Page::class)->whereNumber('customer')->name('crm.customers.show');
});
