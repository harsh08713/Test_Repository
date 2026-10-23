<?php

use App\Modules\CRM\Http\Livewire\Attendance;
use App\Modules\CRM\Http\Livewire\Deals;
use App\Modules\CRM\Http\Livewire\Field;
use App\Modules\CRM\Http\Livewire\Followups;
use App\Modules\CRM\Http\Livewire\Leads;
use App\Modules\CRM\Http\Livewire\Masters;
use App\Modules\CRM\Http\Livewire\RoutePlans;
use App\Modules\CRM\Http\Livewire\Targets;
use App\Modules\CRM\Http\Livewire\Teams;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('crm')->name('crm.')->group(function () {
    // Fixed paths (create, import) come before {lead}; the edit parameter is not named like the form's public property.
    Route::livewire('/leads', Leads\LeadList::class)->name('leads.index');
    Route::livewire('/leads/create', Leads\LeadForm::class)->name('leads.create');
    Route::livewire('/leads/create-inquiry', Leads\InquiryForm::class)->name('leads.create-inquiry');
    Route::livewire('/leads/import', Leads\LeadImport::class)->name('leads.import');
    Route::livewire('/leads/{lead}', Leads\LeadShow::class)->whereNumber('lead')->name('leads.show');
    Route::livewire('/leads/{editing}/edit', Leads\LeadForm::class)->whereNumber('editing')->name('leads.edit');
    Route::livewire('/leads/{editing}/edit-inquiry', Leads\InquiryForm::class)->whereNumber('editing')->name('leads.edit-inquiry');

    // Deals are made only by converting a lead: the conversion form comes before {deal}; an edit parameter is not named like a form property.
    Route::livewire('/deals', Deals\DealList::class)->name('deals.index');
    Route::livewire('/deals/convert/{source}', Deals\DealForm::class)->whereNumber('source')->name('deals.convert');
    Route::livewire('/deals/{deal}', Deals\DealShow::class)->whereNumber('deal')->name('deals.show');
    Route::livewire('/deals/{editing}/edit', Deals\DealForm::class)->whereNumber('editing')->name('deals.edit');

    Route::livewire('/follow-ups', Followups\FollowupList::class)->name('followups.index');
    Route::livewire('/follow-ups/calendar', Followups\FollowupCalendar::class)->name('followups.calendar');

    Route::livewire('/masters/lead-sources', Masters\LeadSourceList::class)->name('masters.sources');
    Route::livewire('/masters/follow-up-outcomes', Masters\FollowupOutcomeList::class)->name('masters.outcomes');
    Route::livewire('/masters/lost-reasons', Masters\LostReasonList::class)->name('masters.lost-reasons');
});

Route::middleware('auth')->prefix('field')->name('field.')->group(function () {
    Route::livewire('/today', Field\MyDay::class)->name('today');

    // Create routes come before {id} ones; an edit parameter is not named like the form's public property.
    Route::livewire('/teams', Teams\SalesTeamList::class)->name('teams.index');
    Route::livewire('/teams/create', Teams\SalesTeamForm::class)->name('teams.create');
    Route::livewire('/teams/{team}', Teams\SalesTeamShow::class)->whereNumber('team')->name('teams.show');
    Route::livewire('/teams/{editing}/edit', Teams\SalesTeamForm::class)->whereNumber('editing')->name('teams.edit');

    Route::livewire('/targets', Targets\TargetList::class)->name('targets.index');
    Route::livewire('/targets/create', Targets\TargetForm::class)->name('targets.create');
    Route::livewire('/targets/{editing}/edit', Targets\TargetForm::class)->whereNumber('editing')->name('targets.edit');

    Route::livewire('/plans', RoutePlans\RoutePlanList::class)->name('plans.index');
    Route::livewire('/plans/create', RoutePlans\RoutePlanForm::class)->name('plans.create');
    Route::livewire('/plans/{plan}', RoutePlans\RoutePlanShow::class)->whereNumber('plan')->name('plans.show');
    Route::livewire('/plans/{editing}/edit', RoutePlans\RoutePlanForm::class)->whereNumber('editing')->name('plans.edit');

    Route::livewire('/attendance', Attendance\AttendanceList::class)->name('attendance.index');
    Route::livewire('/violations', Attendance\ViolationList::class)->name('violations.index');
});
