<?php

namespace App\Modules\Marketing\Http\Livewire;

use App\Models\User;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\MessageTemplate;
use App\Modules\Marketing\Services\AudienceBuilder;
use App\Modules\Marketing\Services\CampaignService;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Make or change a campaign (while it is a draft): its details, who it is for, and its sequence of messages. The screen only holds what was typed;
 * the service checks all of it again. “Preview the audience” works out, on request, how many people the definition matches, how many have an address
 * for the first message and how many may be written to — with the owner’s reach, as the launch will.
 */
#[Title('Campaign')]
class CampaignForm extends Component
{
    /** @var array<string, mixed> */
    public array $c = [];

    /** @var array<string, mixed> */
    public array $a = [];

    /** @var list<string> */
    public array $exit = ['deal', 'quotation', 'won', 'lost'];

    /** @var list<array{delay_hours: string, message_template_id: string}> */
    public array $steps = [];

    #[Locked]
    public int $campaignId = 0;

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    public function mount(?int $editing = null): void
    {
        $user = $this->actor();

        if ($editing === null) {
            Gate::authorize('create', Campaign::class);

            $this->c = [
                'name' => '', 'type' => 'email', 'objective' => '', 'owner_id' => (string) $user->getKey(), 'start_date' => now()->toDateString(), 'end_date' => '', 'budget' => '',
                'audience_kind' => 'leads', 'location_id' => '', 'lead_source_id' => '', 'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '',
            ];
            $this->a = ['stages' => ['new'], 'sources' => [], 'owners' => [], 'branch' => '', 'from' => '', 'to' => '', 'text' => '', 'kinds' => [], 'ordered_within_days' => '', 'not_ordered_within_days' => ''];
            $this->steps = [['delay_hours' => '0', 'message_template_id' => '']];

            return;
        }

        $campaign = Campaign::query()->with('steps')->findOrFail($editing);
        Gate::authorize('update', $campaign);

        $this->campaignId = $campaign->getKey();
        $this->c = [
            'name' => $campaign->name, 'type' => $campaign->type, 'objective' => (string) $campaign->objective, 'owner_id' => (string) $campaign->owner_id,
            'start_date' => $campaign->start_date->toDateString(), 'end_date' => $campaign->end_date?->toDateString() ?? '', 'budget' => (string) $campaign->budget,
            'audience_kind' => $campaign->audience_kind, 'location_id' => (string) $campaign->location_id, 'lead_source_id' => (string) ($campaign->lead_source_id ?? ''),
            'utm_source' => (string) $campaign->utm_source, 'utm_medium' => (string) $campaign->utm_medium, 'utm_campaign' => (string) $campaign->utm_campaign,
        ];
        $audience = $campaign->audience ?? [];
        $this->a = [
            'stages' => $audience['stages'] ?? [], 'sources' => array_map('strval', $audience['sources'] ?? []), 'owners' => array_map('strval', $audience['owners'] ?? []),
            'branch' => (string) ($audience['branch'] ?? ''), 'from' => $audience['from'] ?? '', 'to' => $audience['to'] ?? '', 'text' => $audience['text'] ?? '',
            'kinds' => $audience['kinds'] ?? [], 'ordered_within_days' => (string) ($audience['ordered_within_days'] ?? ''), 'not_ordered_within_days' => (string) ($audience['not_ordered_within_days'] ?? ''),
        ];
        $this->exit = $campaign->exit_stages ?? [];
        $this->steps = $campaign->steps->map(fn ($s) => ['delay_hours' => (string) $s->delay_hours, 'message_template_id' => (string) $s->message_template_id])->all();
    }

    public function addStep(): void
    {
        count($this->steps) < CampaignService::MAX_STEPS && $this->steps[] = ['delay_hours' => '24', 'message_template_id' => ''];
    }

    public function removeStep(int $index): void
    {
        unset($this->steps[$index]);
        $this->steps = array_values($this->steps);
        $this->preview = null;
    }

    public function updatedA(): void
    {
        $this->preview = null;
    }

    public function updatedC(): void
    {
        $this->preview = null;
    }

    public function previewAudience(AudienceBuilder $audiences): void
    {
        $user = $this->actor();
        $this->resetErrorBag();
        $owner = ctype_digit((string) ($this->c['owner_id'] ?? '')) && array_key_exists((int) $this->c['owner_id'], ReportInput::people($user)) ? User::query()->find((int) $this->c['owner_id']) : null;

        if ($owner === null) {
            $this->addError('owner_id', 'Choose yourself or someone on a team you manage.');

            return;
        }

        $kind = $this->c['audience_kind'] === 'customers' ? 'customers' : 'leads';
        $first = ctype_digit((string) ($this->steps[0]['message_template_id'] ?? '')) ? MessageTemplate::query()->find((int) $this->steps[0]['message_template_id']) : null;
        $this->preview = $audiences->preview($kind, $audiences->clean($kind, $this->a), $owner, $first->channel ?? Channel::Email, $first->purpose ?? 'marketing')
            + ['channel' => ($first->channel ?? Channel::Email)->label(), 'owner' => $owner->name, 'has_template' => $first !== null];
    }

    public function save(CampaignService $service): mixed
    {
        $user = $this->actor();
        $campaign = $this->campaignId === 0 ? null : Campaign::query()->findOrFail($this->campaignId);
        Gate::authorize($campaign === null ? 'create' : 'update', $campaign ?? Campaign::class);
        $this->resetErrorBag();

        $input = [...$this->c, 'audience' => $this->a, 'exit_stages' => $this->exit, 'steps' => $this->steps];

        try {
            $saved = $campaign === null ? $service->create($input, $user) : $service->update($campaign, $input, $user);
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return null;
        }

        session()->flash('status', $campaign === null ? 'Campaign saved as a draft. Send it for approval when it is ready.' : 'Campaign saved.');

        return $this->redirectRoute('marketing.campaigns.show', $saved, navigate: true);
    }

    public function render(): View
    {
        $user = $this->actor();

        return view('marketing.campaigns.form', [
            'editing' => $this->campaignId !== 0,
            'types' => CampaignService::TYPES,
            'people' => ReportInput::people($user),
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'sources' => LeadSource::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'stages' => collect(LeadStage::cases())->mapWithKeys(fn (LeadStage $s) => [$s->value => $s->label()])->all(),
            'templates' => MessageTemplate::query()->orderBy('name')->get(['id', 'name', 'channel', 'purpose', 'approval_status', 'status']),
            'exitChoices' => ['contacted' => 'Contacted', 'qualified' => 'Qualified', 'deal' => 'Became a deal', 'quotation' => 'Quoted', 'won' => 'Won', 'lost' => 'Lost'],
        ]);
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
