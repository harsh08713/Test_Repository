<?php

namespace App\Modules\Shared\Http\Livewire;

use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Services\Heartbeat;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    #[Computed]
    public function pendingApprovals(): int
    {
        return app(ApprovalService::class)->pendingFor(Auth::user())->count();
    }

    /** @return Collection<int, Location> */
    #[Computed]
    public function locations(): Collection
    {
        return Location::query()->orderBy('name')->get(['id', 'code', 'name', 'city']);
    }

    /** Has the scheduler been seen, and then gone quiet? (Never seen is left to the health page: a fresh install has not run it yet.) */
    #[Computed]
    public function schedulerQuiet(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->can('setting.view') && app(Heartbeat::class)->isFresh(Heartbeat::SCHEDULER, 5) === false;
    }

    public function render(): View
    {
        return view('shared.dashboard');
    }
}
