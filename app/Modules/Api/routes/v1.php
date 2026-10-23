<?php

use App\Modules\Api\Http\Controllers\AttendanceController;
use App\Modules\Api\Http\Controllers\AuthController;
use App\Modules\Api\Http\Controllers\DealController;
use App\Modules\Api\Http\Controllers\DeviceController;
use App\Modules\Api\Http\Controllers\FollowupController;
use App\Modules\Api\Http\Controllers\HealthController;
use App\Modules\Api\Http\Controllers\LeadController;
use App\Modules\Api\Http\Controllers\LookupController;
use App\Modules\Api\Http\Controllers\ProfileController;
use App\Modules\Api\Http\Controllers\QuotationController;
use App\Modules\Api\Http\Controllers\RoutePlanController;
use App\Modules\Api\Http\Middleware\AssignRequestId;
use App\Modules\Api\Http\Middleware\LogApiRequest;
use App\Modules\Api\Http\Middleware\RequireReadableJson;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| /api/v1 — the mobile / integrations API
|--------------------------------------------------------------------------
| Device tokens only (no session, no cookie). Every route has a request id and an access-log line; every route after sign-in
| is throttled, checks the account and the token's scope, and works in one company chosen on the server.
| `idempotent:required` = the call refuses to run without an Idempotency-Key (a create); plain `idempotent` honours a key when
| one is sent. The documented contract is docs/api/openapi.yaml, and a test keeps it equal to this file.
*/

Route::prefix('api/v1')->name('api.v1.')->middleware(['api', AssignRequestId::class, LogApiRequest::class, RequireReadableJson::class])->group(function () {
    Route::get('health', HealthController::class)->middleware('throttle:api-health')->name('health');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:api-login')->name('auth.login');

    // A read-only device must still be able to sign out and renew its token.
    Route::middleware(['auth:sanctum', 'api.context:self', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
    });

    Route::middleware(['auth:sanctum', 'api.context', 'throttle:api'])->group(function () {
        Route::get('me', ProfileController::class)->name('me');

        Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
        Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->whereNumber('device')->name('devices.destroy');

        Route::get('route-plan/today', [RoutePlanController::class, 'today'])->name('route-plan.today');

        Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
        Route::post('attendance', [AttendanceController::class, 'store'])->middleware('idempotent:required')->name('attendance.store');

        Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
        Route::post('leads', [LeadController::class, 'store'])->middleware('idempotent:required')->name('leads.store');
        Route::post('leads/transfer', [LeadController::class, 'transfer'])->middleware('idempotent')->name('leads.transfer');
        Route::get('leads/{lead}', [LeadController::class, 'show'])->whereNumber('lead')->name('leads.show');
        Route::patch('leads/{lead}', [LeadController::class, 'update'])->whereNumber('lead')->middleware('idempotent')->name('leads.update');
        Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->whereNumber('lead')->middleware('idempotent')->name('leads.destroy');
        Route::post('leads/{lead}/stage', [LeadController::class, 'stage'])->whereNumber('lead')->middleware('idempotent')->name('leads.stage');
        Route::post('leads/{lead}/followups', [FollowupController::class, 'plan'])->whereNumber('lead')->middleware('idempotent:required')->name('leads.followups.plan');
        Route::post('leads/{lead}/followups/log', [FollowupController::class, 'log'])->whereNumber('lead')->middleware('idempotent:required')->name('leads.followups.log');
        Route::post('leads/{lead}/convert', [DealController::class, 'convert'])->whereNumber('lead')->middleware('idempotent:required')->name('leads.convert');

        Route::get('deals', [DealController::class, 'index'])->name('deals.index');
        Route::get('deals/{deal}', [DealController::class, 'show'])->whereNumber('deal')->name('deals.show');
        Route::patch('deals/{deal}', [DealController::class, 'update'])->whereNumber('deal')->middleware('idempotent')->name('deals.update');
        Route::post('deals/{deal}/stage', [DealController::class, 'stage'])->whereNumber('deal')->middleware('idempotent')->name('deals.stage');
        Route::post('deals/{deal}/submit', [DealController::class, 'submit'])->whereNumber('deal')->middleware('idempotent')->name('deals.submit');
        Route::post('deals/{deal}/reassign', [DealController::class, 'reassign'])->whereNumber('deal')->middleware('idempotent')->name('deals.reassign');

        Route::get('quotations', [QuotationController::class, 'index'])->name('quotations.index');
        Route::get('quotations/{quotation}', [QuotationController::class, 'show'])->whereNumber('quotation')->name('quotations.show');

        Route::get('followups', [FollowupController::class, 'index'])->name('followups.index');
        Route::get('followups/calendar', [FollowupController::class, 'calendar'])->name('followups.calendar');
        Route::post('followups/{followup}/complete', [FollowupController::class, 'complete'])->whereNumber('followup')->middleware('idempotent')->name('followups.complete');
        Route::post('followups/{followup}/reschedule', [FollowupController::class, 'reschedule'])->whereNumber('followup')->middleware('idempotent')->name('followups.reschedule');
        Route::post('followups/{followup}/cancel', [FollowupController::class, 'cancel'])->whereNumber('followup')->middleware('idempotent')->name('followups.cancel');

        Route::prefix('lookups')->name('lookups.')->group(function () {
            Route::get('items', [LookupController::class, 'items'])->name('items');
            Route::get('customers', [LookupController::class, 'customers'])->name('customers');
            Route::get('units', [LookupController::class, 'units'])->name('units');
            Route::get('lead-sources', [LookupController::class, 'leadSources'])->name('lead-sources');
            Route::get('followup-outcomes', [LookupController::class, 'followupOutcomes'])->name('followup-outcomes');
            Route::get('lost-reasons', [LookupController::class, 'lostReasons'])->name('lost-reasons');
            Route::get('branches', [LookupController::class, 'branches'])->name('branches');
            Route::get('staff', [LookupController::class, 'staff'])->name('staff');
            Route::get('pin-codes/{pin}', [LookupController::class, 'pinCode'])->where('pin', '[0-9A-Za-z]{1,12}')->name('pin-codes');
        });
    });
});
