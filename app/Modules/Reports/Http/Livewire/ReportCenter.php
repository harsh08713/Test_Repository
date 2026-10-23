<?php

namespace App\Modules\Reports\Http\Livewire;

use App\Models\User;
use App\Modules\Reports\Services\ReportCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The list of reports a person may run, by heading, and the ones that wait for a later phase. */
#[Title('Reports')]
class ReportCenter extends Component
{
    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->can('report.view'), 403);
    }

    public function render(ReportCatalog $catalog): View
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return view('reports.index', [
            'groups' => $catalog->groupedFor($user),
            'planned' => $catalog->planned(),
            'canExport' => $user->can('report.export'),
        ]);
    }
}
