<?php

namespace App\Modules\CRM\Http\Livewire\Attendance;

use App\Modules\CRM\Enums\AttendanceDecision;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\AttendanceRecord;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Who marked attendance, when and where, for the people the signed-in person manages (their own for a salesperson). */
#[Title('Attendance')]
class AttendanceList extends Component
{
    use ScopesToPeople, WithPagination;

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    #[Url(as: 'person')]
    public string $person = '';

    #[Url(as: 'branch')]
    public string $branch = '';

    #[Url(as: 'decision')]
    public string $decision = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', AttendanceRecord::class);

        if ($this->from === '') {
            $this->from = now()->toDateString();
        }
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['from', 'to', 'person', 'branch', 'decision'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $valid = fn (string $day) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && Carbon::hasFormat($day, 'Y-m-d');

        $records = $this->limitToVisible(AttendanceRecord::query())
            ->with(['user:id,name', 'plan.location:id,name', 'stop:id,label', 'overrider:id,name'])
            ->when($valid($this->from), fn ($q) => $q->where('attendance_date', '>=', $this->from))
            ->when($valid($this->to), fn ($q) => $q->where('attendance_date', '<=', $this->to))
            ->when(ctype_digit($this->person), fn ($q) => $q->where('user_id', (int) $this->person))
            ->when(ctype_digit($this->branch), fn ($q) => $q->where('location_id', (int) $this->branch))
            ->when(AttendanceDecision::tryFrom($this->decision) !== null, fn ($q) => $q->where('decision', $this->decision))
            ->orderByDesc('attendance_date')
            ->orderByDesc('marked_at')
            ->paginate(20);

        return view('crm.attendance.list', [
            'records' => $records,
            'people' => $this->visiblePeople(),
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'decisions' => AttendanceDecision::cases(),
        ]);
    }
}
