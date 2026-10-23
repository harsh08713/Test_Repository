<?php

/*
 * Worker for tests/Concurrency: boots the application in its own PHP process and, at an agreed instant, makes one HR call — so several workers hit the same
 * rule at the same moment.
 *
 *   php tests/Support/hr_worker.php approve <companyId> <actorId> <leaveRequestId> <startAt>
 *   php tests/Support/hr_worker.php apply   <companyId> <actorId> <employeeId> <leaveTypeId> <from> <to> <startAt>
 *   php tests/Support/hr_worker.php punch   <companyId> <actorId> <employeeId> <direction> <startAt>
 *   php tests/Support/hr_worker.php hire    <companyId> <actorId> <applicationId> <startAt>
 *   php tests/Support/hr_worker.php enrol   <companyId> <actorId> <programId> <employeeId> <startAt>
 *   php tests/Support/hr_worker.php checklist <companyId> <actorId> <employeeId> <kind> <startAt>
 *   php tests/Support/hr_worker.php remind    <companyId> <actorId> <startAt>
 *
 * Prints one line: "OK", "REFUSED: <why>" when a rule said no, or "ERROR: <class>: <message>" for anything unexpected.
 */

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\TrainingProgram;
use App\Modules\HR\Services\AttendanceService;
use App\Modules\HR\Services\ChecklistReminders;
use App\Modules\HR\Services\ChecklistService;
use App\Modules\HR\Services\LeaveService;
use App\Modules\HR\Services\RecruitmentService;
use App\Modules\HR\Services\TrainingService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$args = array_slice($argv, 2);
$startAt = (float) array_pop($args);

try {
    // Connect first, so that what follows the wait is the work itself and not the handshake.
    DB::select('select 1');

    while (microtime(true) < $startAt) {
        usleep(200);
    }

    $companyId = (int) ($args[0] ?? 0);
    $actor = User::query()->findOrFail((int) ($args[1] ?? 0));

    app(TenantContext::class)->withCompany($companyId, function () use ($mode, $args, $actor) {
        match ($mode) {
            'approve' => app(LeaveService::class)->approve(LeaveRequest::query()->findOrFail((int) $args[2]), $actor),
            'apply' => app(LeaveService::class)->apply(Employee::query()->findOrFail((int) $args[2]), LeaveType::query()->findOrFail((int) $args[3]), $args[4], $args[5], null, 'Racing', $actor),
            'punch' => app(AttendanceService::class)->punch(Employee::query()->findOrFail((int) $args[2]), $args[3], $actor),
            'hire' => app(RecruitmentService::class)->hire(JobApplication::query()->findOrFail((int) $args[2]), $actor),
            'checklist' => app(ChecklistService::class)->start(Employee::query()->findOrFail((int) $args[2]), $args[3]),
            'remind' => (function () {
                Carbon::setTestNow(now()->addSeconds(random_int(1, 40)));   // each worker has its own clock, so a claim that merely rewrites the same value cannot pass
                app(ChecklistReminders::class)->run();
            })(),
            'enrol' => app(TrainingService::class)->enrol(TrainingProgram::query()->findOrFail((int) $args[2]), Employee::query()->findOrFail((int) $args[3]), $actor),
            default => throw new InvalidArgumentException("Unknown mode [{$mode}]."),
        };
    });

    echo 'OK', PHP_EOL;
} catch (ValidationException $e) {
    echo 'REFUSED: '.collect($e->errors())->flatten()->first(), PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage(), PHP_EOL;
    exit(1);
}
