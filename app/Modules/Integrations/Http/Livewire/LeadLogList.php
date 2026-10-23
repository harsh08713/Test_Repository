<?php

namespace App\Modules\Integrations\Http\Livewire;

use App\Models\User;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\Integrations\Enums\InboundStatus;
use App\Modules\Integrations\Models\LeadApiLog;
use App\Modules\Integrations\Providers\ProviderRegistry;
use App\Modules\Integrations\Services\LeadApiLogStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Everything the marketplaces handed over, and what became of each: the lead it made, the lead it was added to, or why it failed. A
 * failed delivery keeps its payload, so once the cause is put right it can be processed again from here (the dead-letter queue).
 */
#[Title('Inbound lead log')]
class LeadLogList extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'provider')]
    public string $provider = '';

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    /** The delivery whose payload is open (0 = none). */
    public int $viewing = 0;

    public string $note = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', LeadApiLog::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['provider', 'status', 'search', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function inspect(int $id): void
    {
        $log = LeadApiLog::query()->findOrFail($id);
        Gate::authorize('view', $log);

        $this->viewing = $log->getKey();
    }

    public function closeInspector(): void
    {
        $this->viewing = 0;
    }

    /** Process a failed delivery again, now that whatever was wrong has been put right. */
    public function retry(int $id): void
    {
        $log = LeadApiLog::query()->findOrFail($id);
        Gate::authorize('retry', $log);

        app(LeadApiLogStore::class)->dispatch($log);

        $now = $log->fresh()?->status;
        $this->note = match (true) {
            $now === InboundStatus::Failed => 'It failed again: '.($log->fresh()->error ?? 'see the log').'.',
            $now === null || ! $now->isFinal() => 'It has been queued for processing.',
            default => 'Processed: '.$now->label().'.',
        };
    }

    public function render(ProviderRegistry $providers): View
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $valid = fn (string $day) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && Carbon::hasFormat($day, 'Y-m-d');
        $term = trim($this->search);

        $logs = LeadApiLog::query()
            ->with(['lead:id,lead_number,name,assigned_to,created_by,location_id', 'connection:id,provider'])
            ->when($providers->has($this->provider), fn ($q) => $q->where('provider', $this->provider))
            ->when(InboundStatus::tryFrom($this->status) !== null, fn ($q) => $q->where('status', $this->status))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('provider_lead_id', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhereHas('lead', fn ($l) => $l->where('lead_number', 'like', '%'.addcslashes($term, '%_\\').'%')->orWhere('name', 'like', '%'.addcslashes($term, '%_\\').'%'))))
            ->when($valid($this->from), fn ($q) => $q->where('created_at', '>=', $this->from.' 00:00:00'))
            ->when($valid($this->to), fn ($q) => $q->where('created_at', '<=', $this->to.' 23:59:59'))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return view('integrations.leads', [
            'logs' => $logs,
            'providers' => $providers->all(),
            'statuses' => InboundStatus::cases(),
            'openable' => app(LeadAccess::class)->openable($user, $logs->getCollection()->pluck('lead')->filter()->all()),
            'open' => $this->viewing === 0 ? null : LeadApiLog::query()->with('lead:id,lead_number')->find($this->viewing),
            'canRetry' => $user->can('integration.configure'),
        ]);
    }
}
