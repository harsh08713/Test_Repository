<?php

namespace App\Modules\HR;

use App\Modules\HR\Console\AccrueLeave;
use App\Modules\HR\Console\CloseAttendance;
use App\Modules\HR\Console\RemindChecklists;
use App\Modules\HR\Console\SyncExits;
use App\Modules\HR\Models\ApplicationEvent;
use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\AttendancePunch;
use App\Modules\HR\Models\AttendanceRegularisation;
use App\Modules\HR\Models\Candidate;
use App\Modules\HR\Models\ChecklistTemplateItem;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeChecklistItem;
use App\Modules\HR\Models\EmployeeContact;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\EmploymentEvent;
use App\Modules\HR\Models\Interview;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobOpening;
use App\Modules\HR\Models\LeaveLedgerEntry;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\Offer;
use App\Modules\HR\Models\PerformanceGoal;
use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Models\ReviewCycle;
use App\Modules\HR\Models\Shift;
use App\Modules\HR\Models\TrainingEnrolment;
use App\Modules\HR\Models\TrainingProgram;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** HR & workforce (H1): the employee file, org chart, notice and exit, papers, and the HR dashboard. */
class HRServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'employee' => Employee::class, 'employee_contact' => EmployeeContact::class, 'employee_document' => EmployeeDocument::class, 'employment_event' => EmploymentEvent::class,
            'leave_type' => LeaveType::class, 'leave_request' => LeaveRequest::class, 'leave_ledger_entry' => LeaveLedgerEntry::class, 'shift' => Shift::class,
            'attendance_punch' => AttendancePunch::class, 'attendance_day' => AttendanceDay::class, 'attendance_regularisation' => AttendanceRegularisation::class,
            'job_opening' => JobOpening::class, 'candidate' => Candidate::class, 'job_application' => JobApplication::class, 'application_event' => ApplicationEvent::class, 'interview' => Interview::class,
            'offer' => Offer::class, 'review_cycle' => ReviewCycle::class, 'performance_goal' => PerformanceGoal::class, 'performance_review' => PerformanceReview::class,
            'training_program' => TrainingProgram::class, 'training_enrolment' => TrainingEnrolment::class,
            'checklist_template_item' => ChecklistTemplateItem::class, 'employee_checklist_item' => EmployeeChecklistItem::class,
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([SyncExits::class, AccrueLeave::class, CloseAttendance::class, RemindChecklists::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('hr:sync-exits')->dailyAt('00:30')->withoutOverlapping();
            $schedule->command('hr:attendance-close')->dailyAt('00:45')->withoutOverlapping();
            $schedule->command('hr:leave-accrue')->dailyAt('01:00')->withoutOverlapping();
            $schedule->command('hr:checklist-reminders')->dailyAt('09:00')->withoutOverlapping();
        });

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
    }
}
