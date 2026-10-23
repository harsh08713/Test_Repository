<?php

namespace App\Modules\CRM\Http\Livewire\Field;

use App\Models\User;
use App\Modules\CRM\Enums\RoutePlanStatus;
use App\Modules\CRM\Exceptions\AttendanceRefused;
use App\Modules\CRM\Models\AttendanceRecord;
use App\Modules\CRM\Models\GeofenceViolation;
use App\Modules\CRM\Models\RoutePlan;
use App\Modules\CRM\Services\AttendanceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The salesperson's own day: today's plan, and the button that marks attendance from where they stand.
 *
 * The browser only reports a position and how accurate it says it is. Everything else — whether there is a plan, whether
 * the position is real and inside a stop's geofence, the time — is decided by AttendanceService on the server, and a
 * refused attempt is logged there. With no published plan the button is not even offered, but the rule does not depend on it.
 */
#[Title('My day')]
class MyDay extends Component
{
    /** What the last attempt on this screen said, for the person. */
    public string $outcome = '';

    public string $outcomeKind = '';

    public function mount(): void
    {
        Gate::authorize('create', AttendanceRecord::class);
    }

    /** Called by the page with the device's reading. Anything it sends is only a claim until the service accepts it. */
    public function markAttendance(mixed $latitude, mixed $longitude, mixed $accuracy, Request $request, AttendanceService $service): void
    {
        Gate::authorize('create', AttendanceRecord::class);

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            $record = $service->mark($user, $latitude, $longitude, $accuracy, 'web', $request->userAgent());
            $this->outcome = 'Attendance marked at '.$record->marked_at->format('H:i').'. You are '.number_format((float) $record->distance_m, 0).' m from '.($record->stop->label ?? 'your first stop').'.';
            $this->outcomeKind = 'success';
        } catch (AttendanceRefused $e) {
            $this->outcome = $e->getMessage();
            $this->outcomeKind = $e->reason === null ? 'info' : 'error';
        }
    }

    /** The browser could not read a position at all (permission refused, no signal): say so; nothing reaches the server. */
    public function locationUnavailable(string $message = ''): void
    {
        $this->outcome = 'Your location could not be read'.($message !== '' ? ' ('.mb_substr(strip_tags($message), 0, 120).')' : '').'. Allow location access for this site and try again.';
        $this->outcomeKind = 'error';
    }

    public function render(): View
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $today = now()->toDateString();
        $plan = RoutePlan::query()->with(['location:id,name', 'stops'])->where('user_id', $user->getKey())->where('plan_date', $today)->first();
        $attendance = AttendanceRecord::query()->with('stop:id,label')->where('user_id', $user->getKey())->where('attendance_date', $today)->first();

        return view('crm.field.my-day', [
            'today' => $today,
            'plan' => $plan,
            'attendance' => $attendance,
            'canMark' => $plan !== null && $plan->status === RoutePlanStatus::Published && $attendance === null,
            'attempts' => GeofenceViolation::query()->where('user_id', $user->getKey())->where('attempted_at', '>=', now()->startOfDay())->orderByDesc('id')->limit(5)->get(),
        ]);
    }
}
