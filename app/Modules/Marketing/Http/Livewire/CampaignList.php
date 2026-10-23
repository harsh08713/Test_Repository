<?php

namespace App\Modules\Marketing\Http\Livewire;

use App\Modules\Marketing\Models\Campaign;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The campaigns, with how far each has got: people in it, messages made, responses, leads and approved sales attributed to it. */
#[Title('Campaigns')]
class CampaignList extends Component
{
    use WithPagination;

    #[Url(as: 'state')]
    public string $state = '';

    #[Url(as: 'approval')]
    public string $approval = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Campaign::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['state', 'approval', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $campaigns = Campaign::query()->with('owner:id,name')
            ->withCount([
                'members', 'members as responded_count' => fn (Builder $q) => $q->whereNotNull('responded_at'),
            ])
            ->addSelect(['campaigns.*'])
            ->selectSub(fn ($q) => $q->from('communications')->selectRaw('count(*)')->whereColumn('communications.campaign_id', 'campaigns.id')->whereIn('communications.status', ['sent', 'queued', 'sending']), 'messages_count')
            ->selectSub(fn ($q) => $q->from('leads')->selectRaw('count(*)')->whereColumn('leads.campaign_id', 'campaigns.id')->whereNull('leads.deleted_at'), 'leads_count')
            ->when(in_array($this->state, [Campaign::DRAFT, Campaign::RUNNING, Campaign::PAUSED, Campaign::COMPLETED, Campaign::CANCELLED], true), fn (Builder $q) => $q->where('campaigns.state', $this->state))
            ->when(in_array($this->approval, ['draft', 'pending', 'approved', 'rejected'], true), fn (Builder $q) => $q->where('campaigns.approval_status', $this->approval))
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('campaigns.name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%')->orWhere('campaigns.code', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%')))
            ->orderByDesc('campaigns.id')->paginate(20);

        return view('marketing.campaigns.list', ['campaigns' => $campaigns, 'canCreate' => Gate::allows('create', Campaign::class)]);
    }
}
