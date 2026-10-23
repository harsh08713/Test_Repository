<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Workspace\Models\Holiday;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The company's holiday calendar. It is the same `holidays` table the SLA clocks use (one calendar for the company): leave does not count these days, and the
 * attendance day-close marks them as holidays. A removed holiday is removed for good, so the day can be added again.
 */
#[Title('Holidays')]
class HolidayList extends Component
{
    use ActsAsStaff;

    #[Url(as: 'year')]
    public string $year = '';

    /** @var array<string, string> */
    public array $h = ['holiday_date' => '', 'name' => ''];

    public function mount(): void
    {
        abort_unless($this->actor()->can('holiday.view'), 403);

        if (preg_match('/^\d{4}$/', $this->year) !== 1) {
            $this->year = (string) now()->year;
        }
    }

    public function addHoliday(): void
    {
        abort_unless($this->actor()->can('holiday.create'), 403);
        $this->resetErrorBag();

        try {
            $data = Validator::make(['holiday_date' => trim($this->h['holiday_date']), 'name' => trim($this->h['name'])], [
                'holiday_date' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
                'name' => ['required', 'string', 'max:120'],
            ], ['holiday_date.required' => 'Choose the day.', 'name.required' => 'Say what the day is.'])->validate();

            if (Holiday::query()->whereDate('holiday_date', $data['holiday_date'])->exists()) {
                throw ValidationException::withMessages(['holiday_date' => 'That day is already a holiday.']);
            }
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        Holiday::query()->create($data);
        $this->h = ['holiday_date' => '', 'name' => ''];
        session()->flash('status', 'Holiday added.');
    }

    public function removeHoliday(int $id): void
    {
        abort_unless($this->actor()->can('holiday.delete'), 403);

        Holiday::query()->findOrFail($id)->forceDelete();
        session()->flash('status', 'Holiday removed.');
    }

    public function render(): View
    {
        $year = preg_match('/^\d{4}$/', $this->year) === 1 ? (int) $this->year : now()->year;
        $user = $this->actor();

        return view('hr.holidays', [
            'holidays' => Holiday::query()->whereYear('holiday_date', $year)->orderBy('holiday_date')->get(), 'yearNumber' => $year,
            'canCreate' => $user->can('holiday.create'), 'canDelete' => $user->can('holiday.delete'),
            'hasAny' => Holiday::withoutGlobalScopes(Tenancy::SCOPES)->exists(),
        ]);
    }
}
