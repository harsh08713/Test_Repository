<?php

namespace App\Modules\Reports\Http\Livewire;

use App\Models\User;
use App\Modules\Reports\Models\ReportSchedule;
use App\Modules\Reports\Services\ReportCatalog;
use App\Modules\Reports\Services\ReportScheduler;
use App\Modules\Reports\Support\RelativeRange;
use App\Modules\Shared\Models\Location;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/** "Scheduled reports": the reports sent to the signed-in person on a schedule, and a form to add one. */
#[Title('Scheduled reports')]
class ScheduledReports extends Component
{
    public bool $adding = false;

    /** @var array<string, string> */
    public array $form = ['report' => '', 'format' => 'xlsx', 'frequency' => 'weekly', 'weekday' => '1', 'month_day' => '1', 'send_hour' => '7', 'date_range' => 'none', 'branch' => '', 'choice' => ''];

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->can('report.view'), 403);

        return $user;
    }

    public function mount(): void
    {
        $this->actor();
    }

    public function openForm(): void
    {
        $this->resetErrorBag();
        $this->adding = ! $this->adding;
    }

    public function save(ReportScheduler $scheduler): void
    {
        $this->resetErrorBag();

        try {
            $scheduler->create([...$this->form, 'filters' => ['branch' => $this->form['branch'], 'choice' => $this->form['choice']]], $this->actor());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        } catch (AuthorizationException $e) {
            $this->addError('report', $e->getMessage());

            return;
        }

        session()->flash('status', 'Scheduled. The first one will be sent at the next time it is due.');
        $this->adding = false;
    }

    public function pause(ReportScheduler $scheduler, int $id): void
    {
        $scheduler->setActive($this->mine($id), false, $this->actor());
    }

    public function resume(ReportScheduler $scheduler, int $id): void
    {
        $scheduler->setActive($this->mine($id), true, $this->actor());
    }

    public function sendNow(ReportScheduler $scheduler, int $id): void
    {
        $scheduler->sendNow($this->mine($id), $this->actor());
        session()->flash('status', 'Making it now. It will arrive by e-mail and appear under My exports.');
    }

    public function remove(ReportScheduler $scheduler, int $id): void
    {
        $scheduler->remove($this->mine($id), $this->actor());
        session()->flash('status', 'Removed.');
    }

    private function mine(int $id): ReportSchedule
    {
        return ReportSchedule::query()->where('user_id', $this->actor()->id)->findOrFail($id);
    }

    public function render(ReportCatalog $catalog): View
    {
        $user = $this->actor();
        $exportable = collect($catalog->all())->filter(fn ($r) => $r->canExport($user));
        $chosen = $exportable->get($this->form['report']);

        return view('reports.schedules', [
            'schedules' => ReportSchedule::query()->where('user_id', $user->id)->orderBy('id')->get(), 'reports' => $exportable->map(fn ($r) => $r->title())->all(), 'catalog' => $catalog,
            'frequencies' => ReportSchedule::FREQUENCIES, 'ranges' => RelativeRange::RANGES, 'chosen' => $chosen,
            'branches' => $chosen !== null && in_array('branch', $chosen->filters(), true) ? Location::query()->orderBy('name')->pluck('name', 'id')->all() : [],
            'choices' => $chosen !== null && in_array('choice', $chosen->filters(), true) ? $chosen->choices() : [], 'choiceLabel' => $chosen?->choiceLabel(),
            'weekdays' => [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'],
            'cap' => ReportSchedule::MAX_PER_PERSON,
        ]);
    }
}
