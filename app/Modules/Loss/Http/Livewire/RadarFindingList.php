<?php

namespace App\Modules\Loss\Http\Livewire;

use App\Models\User;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Loss\Models\RadarFinding;
use App\Modules\Loss\Radar\RadarService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Profit Leakage Radar: what the daily sweep noticed, largest first. A finding is a proposal — "Report as incident" opens the loss form with the
 * source filled in (a person checks and saves it); "Dismiss" sets it aside with a reason. Nothing here changes a loss figure.
 */
#[Title('Leakage radar')]
class RadarFindingList extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    /** open | reported | dismissed | resolved | all */
    #[Url(as: 'show')]
    public string $show = 'open';

    public ?int $dismissingId = null;

    public string $reason = '';

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->can('radar_finding.view'), 403);

        $company = app(TenantContext::class)->activeCompanyId();

        if ($company !== null) {
            app(RadarService::class)->syncReported((int) $company);
        }
    }

    public function updatingShow(): void
    {
        $this->resetPage();
        $this->cancelDismiss();
    }

    public function askDismiss(int $id): void
    {
        $this->resetErrorBag();
        $this->dismissingId = RadarFinding::query()->findOrFail($id)->id;
        $this->reason = '';
    }

    public function cancelDismiss(): void
    {
        $this->resetErrorBag();
        $this->dismissingId = null;
        $this->reason = '';
    }

    public function dismissFinding(RadarService $radar): void
    {
        $this->resetErrorBag();
        abort_if($this->dismissingId === null, 404);

        $radar->dismiss(RadarFinding::query()->findOrFail($this->dismissingId), $this->reason, Auth::user());
        session()->flash('status', 'The finding was set aside.');
        $this->cancelDismiss();
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();

        $findings = RadarFinding::query()
            ->when($this->show !== 'all', fn ($q) => $q->where('state', $this->show))
            ->with(['location:id,name', 'incident:id,incident_number', 'dismissedBy:id,name'])
            ->orderByRaw("field(severity, 'high', 'medium', 'low')")->orderByDesc('estimated_amount')->orderBy('id')->paginate(self::PER_PAGE);

        return view('loss.radar', [
            'findings' => $findings,
            'tabs' => ['open' => 'Open', 'reported' => 'Reported', 'dismissed' => 'Dismissed', 'resolved' => 'No longer applies', 'all' => 'All'],
            'canDismiss' => $user->can('radar_finding.edit'),
            'canReport' => Gate::allows('create', LossIncident::class),
            'canSeeIncidents' => Gate::allows('viewAny', LossIncident::class),
        ]);
    }
}
