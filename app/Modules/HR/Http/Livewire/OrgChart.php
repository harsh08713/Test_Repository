<?php

namespace App\Modules\HR\Http\Livewire;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\EmployeeAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Who reports to whom, for the people the viewer may see. Someone whose manager the viewer cannot see (or who has none) starts a branch of the chart.
 * People who have left are left out.
 */
#[Title('Org chart')]
class OrgChart extends Component
{
    public function mount(): void
    {
        Gate::authorize('viewAny', Employee::class);
    }

    public function render(EmployeeAccess $access): View
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $people = $access->restrict(Employee::query(), $user)->whereIn('employment_status', ['active', 'notice'])->with(['designation:id,name', 'department:id,name'])
            ->orderBy('name')->get(['id', 'name', 'code', 'reports_to_id', 'designation_id', 'department_id', 'employment_status']);

        $ids = $people->pluck('id')->flip();
        $children = $people->groupBy(fn (Employee $e) => $e->reports_to_id !== null && $ids->has($e->reports_to_id) ? $e->reports_to_id : 0);

        return view('hr.org-chart', ['children' => $children, 'total' => $people->count()]);
    }
}
