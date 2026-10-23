<?php

namespace App\Modules\Marketing\Http\Livewire;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignMember;
use App\Modules\Marketing\Models\Communication;
use App\Modules\Marketing\Services\CampaignService;
use App\Modules\Marketing\Services\CampaignStats;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** One campaign: where it stands, what it has done, who is in it, what was sent — and, for those who may, launch, pause, call off and re-open. */
#[Title('Campaign')]
class CampaignShow extends Component
{
    use WithPagination;

    #[Locked]
    public int $campaignId = 0;

    /** '' | cancel | reopen | respond */
    public string $dialog = '';

    public string $reason = '';

    public int $responding = 0;

    #[Url(as: 'people')]
    public string $people = '';

    public function mount(Campaign $campaign): void
    {
        Gate::authorize('view', $campaign);

        $this->campaignId = $campaign->getKey();
    }

    public function updating(string $property): void
    {
        $property === 'people' && $this->resetPage();
    }

    public function launch(CampaignService $service): void
    {
        $campaign = $this->campaign();
        Gate::authorize('launch', $campaign);

        $this->run(fn () => $service->launch($campaign, $this->actor()), 'The campaign is launched. The first messages go out within a few minutes (the scheduler sends them).');
    }

    public function pause(CampaignService $service): void
    {
        $campaign = $this->campaign();
        Gate::authorize('control', $campaign);

        $this->run(fn () => $service->pause($campaign, $this->actor()), 'The campaign is paused: nothing more is sent until it is resumed.');
    }

    public function resume(CampaignService $service): void
    {
        $campaign = $this->campaign();
        Gate::authorize('control', $campaign);

        $this->run(fn () => $service->resume($campaign, $this->actor()), 'The campaign is running again.');
    }

    public function openDialog(string $which, int $memberId = 0): void
    {
        abort_unless(in_array($which, ['cancel', 'reopen', 'respond'], true), 404);

        $campaign = $this->campaign();
        Gate::authorize($which === 'reopen' ? 'reopen' : 'control', $campaign);
        $which === 'respond' && CampaignMember::query()->where('campaign_id', $campaign->getKey())->findOrFail($memberId);

        $this->dialog = $which;
        $this->responding = $memberId;
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function closeDialog(): void
    {
        $this->dialog = '';
        $this->resetErrorBag();
    }

    public function cancelCampaign(CampaignService $service): void
    {
        $campaign = $this->campaign();
        Gate::authorize($campaign->isDraft() ? 'update' : 'control', $campaign);

        $this->run(fn () => $service->cancel($campaign, $this->reason, $this->actor()), 'The campaign is called off.');
    }

    public function reopenCampaign(CampaignService $service): void
    {
        $campaign = $this->campaign();
        Gate::authorize('reopen', $campaign);

        $this->run(fn () => $service->reopen($campaign, $this->reason, $this->actor()), 'The campaign is a draft again. It has to be approved again before it is launched.');
    }

    public function markResponded(CampaignService $service): void
    {
        $campaign = $this->campaign();
        Gate::authorize('control', $campaign);
        $member = CampaignMember::query()->where('campaign_id', $campaign->getKey())->findOrFail($this->responding);

        $this->run(fn () => $service->markResponded($member, $this->reason, $this->actor()), 'Marked as having responded. Their sequence has ended.');
    }

    public function render(CampaignStats $stats): View
    {
        $campaign = $this->campaign();
        $campaign->load(['owner:id,name', 'source:id,name', 'steps.template:id,name,channel,purpose,subject']);

        $members = CampaignMember::query()->where('campaign_id', $campaign->getKey())
            ->when(in_array($this->people, ['active', 'completed', 'exited', 'suppressed', 'responded'], true), fn (Builder $q) => $this->people === 'responded' ? $q->whereNotNull('responded_at') : $q->where('status', $this->people))
            ->orderBy('id')->paginate(15);

        return view('marketing.campaigns.show', [
            'campaign' => $campaign,
            'stats' => $stats->for([$campaign->getKey()])[$campaign->getKey()],
            'members' => $members,
            'messages' => Communication::query()->where('campaign_id', $campaign->getKey())->orderByDesc('id')->limit(10)->get(),
            'can' => [
                'edit' => Gate::allows('update', $campaign), 'launch' => Gate::allows('launch', $campaign), 'control' => Gate::allows('control', $campaign),
                'reopen' => Gate::allows('reopen', $campaign), 'cancelDraft' => $campaign->isDraft() && Gate::allows('update', $campaign),
            ],
        ]);
    }

    private function campaign(): Campaign
    {
        return Campaign::query()->findOrFail($this->campaignId);
    }

    private function run(\Closure $step, string $said): void
    {
        $this->resetErrorBag();

        try {
            $step();
        } catch (FieldRuleException|ApprovalStateException $e) {
            $this->addError($this->dialog === '' ? 'workflow' : 'reason', $e->getMessage());

            return;
        }

        $this->dialog = '';
        session()->flash('status', $said);
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
