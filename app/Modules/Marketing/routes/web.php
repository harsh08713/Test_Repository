<?php

use App\Modules\Marketing\Http\Controllers\UnsubscribeController;
use App\Modules\Marketing\Http\Livewire\CampaignForm;
use App\Modules\Marketing\Http\Livewire\CampaignList;
use App\Modules\Marketing\Http\Livewire\CampaignShow;
use App\Modules\Marketing\Http\Livewire\ChannelStatus;
use App\Modules\Marketing\Http\Livewire\CommunicationList;
use App\Modules\Marketing\Http\Livewire\ConsentList;
use App\Modules\Marketing\Http\Livewire\TemplateForm;
use App\Modules\Marketing\Http\Livewire\TemplateList;
use App\Modules\Marketing\Http\Livewire\WorkflowForm;
use App\Modules\Marketing\Http\Livewire\WorkflowList;
use App\Modules\Marketing\Http\Livewire\WorkflowShow;
use Illuminate\Support\Facades\Route;

// Public, for someone who is not signed in: the link at the bottom of a marketing message. Signed (it cannot be altered), throttled, and opening it changes nothing.
Route::middleware(['signed', 'throttle:30,1'])->prefix('unsubscribe')->name('marketing.')->group(function () {
    Route::get('/{company}/{channel}', [UnsubscribeController::class, 'show'])->whereNumber('company')->name('unsubscribe');
    Route::post('/{company}/{channel}', [UnsubscribeController::class, 'confirm'])->whereNumber('company')->name('unsubscribe.confirm');
});

Route::middleware('auth')->prefix('marketing')->name('marketing.')->group(function () {
    // Fixed paths ("create") come before {campaign}; an edit parameter is not named like a form property.
    Route::livewire('/templates', TemplateList::class)->name('templates.index');
    Route::livewire('/templates/create', TemplateForm::class)->name('templates.create');
    Route::livewire('/templates/{editing}/edit', TemplateForm::class)->whereNumber('editing')->name('templates.edit');

    Route::livewire('/campaigns', CampaignList::class)->name('campaigns.index');
    Route::livewire('/campaigns/create', CampaignForm::class)->name('campaigns.create');
    Route::livewire('/campaigns/{campaign}', CampaignShow::class)->whereNumber('campaign')->name('campaigns.show');
    Route::livewire('/campaigns/{editing}/edit', CampaignForm::class)->whereNumber('editing')->name('campaigns.edit');

    Route::livewire('/workflows', WorkflowList::class)->name('workflows.index');
    Route::livewire('/workflows/create', WorkflowForm::class)->name('workflows.create');
    Route::livewire('/workflows/{workflow}', WorkflowShow::class)->whereNumber('workflow')->name('workflows.show');
    Route::livewire('/workflows/{editing}/edit', WorkflowForm::class)->whereNumber('editing')->name('workflows.edit');

    Route::livewire('/consent', ConsentList::class)->name('consent');
    Route::livewire('/messages', CommunicationList::class)->name('messages');
    Route::livewire('/channels', ChannelStatus::class)->name('channels');
});
