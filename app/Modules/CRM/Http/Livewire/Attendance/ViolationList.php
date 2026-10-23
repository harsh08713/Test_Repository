<?php

namespace App\Modules\CRM\Http\Livewire\Attendance;

use App\Modules\CRM\Enums\ViolationReason;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\GeofenceViolation;
use App\Modules\CRM\Services\AttendanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The geofence violation log: every attendance attempt that was refused, whatever the reason. A manager who may override can
 * accept an attempt of someone they manage; the original entry stays and a new attendance record points back at it.
 */
#[Title('Geofence violation log')]
class ViolationList extends Component
{
    use ScopesToPeople, WithPagination;

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    #[Url(as: 'person')]
    public string $person = '';

    #[Url(as: 'reason')]
    public string $reason = '';

    /** The attempt whose override dialog is open (0 = none). */
    public int $accepting = 0;

    public string $why = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', GeofenceViolation::class);

        if ($this->from === '') {
            $this->from = now()->subDays(7)->toDateString();
        }
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['from', 'to', 'person', 'reason'], true)) {
            $this->resetPage();
        }
    }

    public function openAccept(int $id): void
    {
        Gate::authorize('override', GeofenceViolation::query()->findOrFail($id));

        $this->accepting = $id;
        $this->why = '';
        $this->resetErrorBag();
    }

    public function closeAccept(): void
    {
        $this->accepting = 0;
        $this->why = '';
        $this->resetErrorBag();
    }

    public function accept(AttendanceService $service): void
    {
        $violation = GeofenceViolation::query()->findOrFail($this->accepting);
        Gate::authorize('override', $violation);

        try {
            $service->overrideViolation($violation, $this->actor(), $this->why);
        } catch (FieldRuleException|AuthorizationException $e) {
            $this->addError('why', $e->getMessage());

            return;
        } catch (ValidationException $e) {
            $this->addError('why', (string) $e->validator->errors()->first('reason'));

            return;
        }

        $this->closeAccept();
        session()->flash('status', 'Attendance accepted. The original attempt stays in the log.');
    }

    public function render(): View
    {
        $valid = fn (string $day) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && Carbon::hasFormat($day, 'Y-m-d');

        $violations = $this->limitToVisible(GeofenceViolation::query())
            ->with(['user:id,name', 'plan:id,plan_date', 'stop:id,label', 'acceptedAs.overrider:id,name'])
            ->when($valid($this->from), fn ($q) => $q->where('attempted_at', '>=', $this->from.' 00:00:00'))
            ->when($valid($this->to), fn ($q) => $q->where('attempted_at', '<=', $this->to.' 23:59:59'))
            ->when(ctype_digit($this->person), fn ($q) => $q->where('user_id', (int) $this->person))
            ->when(ViolationReason::tryFrom($this->reason) !== null, fn ($q) => $q->where('reason', $this->reason))
            ->orderByDesc('attempted_at')
            ->orderByDesc('id')
            ->paginate(20);

        return view('crm.attendance.violations', [
            'violations' => $violations,
            'people' => $this->visiblePeople(),
            'reasons' => ViolationReason::cases(),
        ]);
    }
}
