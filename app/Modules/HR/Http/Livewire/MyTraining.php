<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\TrainingEnrolment;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** "My training": the programs the person is enrolled in or has taken, with the outcome and score. */
#[Title('My training')]
class MyTraining extends Component
{
    use ActsAsStaff;

    public function render(): View
    {
        $employee = $this->ownEmployee();

        return view('hr.my-training', [
            'employee' => $employee, 'states' => TrainingEnrolment::STATES,
            'rows' => $employee === null ? collect() : TrainingEnrolment::query()->where('employee_id', $employee->id)->with('program:id,title,code,starts_on,ends_on,state,mode,venue')->orderByDesc('id')->get(),
        ]);
    }
}
