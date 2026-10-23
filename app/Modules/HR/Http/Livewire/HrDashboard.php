<?php

namespace App\Modules\HR\Http\Livewire;

use App\Models\User;
use App\Modules\HR\Services\HrFigures;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('HR dashboard')]
class HrDashboard extends Component
{
    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->can('hr_dashboard.view'), 403);
    }

    public function render(HrFigures $figures): View
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return view('hr.dashboard', ['s' => $figures->summary($user), 'window' => HrFigures::WINDOW_DAYS]);
    }
}
