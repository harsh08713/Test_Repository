<?php

namespace App\Modules\Marketing\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignMember;
use App\Modules\Marketing\Models\CampaignStep;
use App\Modules\Marketing\Models\MessageTemplate;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Services\NumberSeriesService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Campaign data has one door. The form sends it what was typed; the service checks it (owner in reach, branch the person works in, dates, budget, the
 * audience definition, steps that use real templates), takes the CP number first, and saves the campaign and its steps together.
 *
 *  - **Make / change** (`create`, `update`): only while it is a draft; once it is sent for approval it is fixed — in the model as well.
 *  - **Launch** (`launch`): an approved draft only, once. The audience is worked out **now, with the reach of the campaign's owner**, and frozen into
 *    members (the name and the contact details are copied: the campaign writes to what it was approved to write to).
 *  - **Pause, resume, call off**; **mark a member as having responded** (which ends their sequence).
 *
 * Sending is not here: `CampaignRunner` does it, step by step, on a schedule.
 */
final class CampaignService
{
    public const TYPES = ['email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'multichannel' => 'More than one channel', 'event' => 'Event or exhibition', 'referral' => 'Referral', 'social' => 'Social media', 'other' => 'Other'];

    public const MAX_STEPS = 10;

    public function __construct(
        private readonly NumberSeriesService $numbers,
        private readonly AudienceBuilder $audiences,
        private readonly ApprovalService $approvals,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input, User $actor): Campaign
    {
        $data = $this->validated($input, null, $actor);

        return DB::transaction(function () use ($data, $actor) {
            $campaign = new Campaign;
            $campaign->fill($data['fillable']);
            $campaign->forceFill([
                ...$data['fixed'],
                'company_id' => (int) $this->tenant->activeCompanyId(), 'location_id' => $data['location_id'],
                'code' => $this->numbers->next(DocumentType::Campaign, (int) $data['location_id'], Carbon::parse($data['fillable']['start_date'])),
                'state' => Campaign::DRAFT, 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ])->save();

            $this->syncSteps($campaign, $data['steps']);

            return $campaign->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function update(Campaign $campaign, array $input, User $actor): Campaign
    {
        $data = $this->validated($input, $campaign, $actor);

        return DB::transaction(function () use ($campaign, $data, $actor) {
            $fresh = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isDraft() || ! $fresh->approval_status->isEditable()) {
                throw new FieldRuleException('This campaign has been sent for approval, so it no longer changes. Re-open it first.');
            }

            $fresh->fill($data['fillable']);
            $fresh->forceFill([...$data['fixed'], 'updated_by' => $actor->getKey()])->save();
            $this->syncSteps($fresh, $data['steps']);

            return $fresh->refresh();
        });
    }

    /**
     * Start the campaign: freeze its audience into members and set it running.
     *
     * @throws FieldRuleException
     */
    public function launch(Campaign $campaign, User $actor): Campaign
    {
        return DB::transaction(function () use ($campaign, $actor) {
            $fresh = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isDraft()) {
                throw new FieldRuleException('This campaign has already been launched.');
            }

            if (! $fresh->isApproved()) {
                throw new FieldRuleException('A campaign has to be approved before it is launched.');
            }

            $first = $fresh->steps()->with('template')->first();

            if ($first === null || $first->template === null) {
                throw new FieldRuleException('This campaign has no messages to send.');
            }

            $owner = User::query()->where('is_active', true)->find($fresh->owner_id);

            if ($owner === null) {
                throw new FieldRuleException('The campaign’s owner is no longer active; make someone else the owner (re-open it) before launching.');
            }

            $channel = $first->template->channel;
            $begin = $fresh->start_date->isFuture() ? $fresh->start_date->copy()->startOfDay() : now();
            $count = 0;
            $rows = [];

            foreach ($this->audiences->people($fresh->audience_kind, $fresh->audience ?? [], $owner, AudienceBuilder::MAX + 1) as $person) {
                if (++$count > AudienceBuilder::MAX) {
                    throw new FieldRuleException('The audience has more than '.number_format(AudienceBuilder::MAX).' people. Narrow it and try again.');
                }

                $address = ContactNormalizer::address($channel, $channel->value === 'email' ? $person['email'] : $person['mobile']);
                $rows[] = [
                    'company_id' => $fresh->company_id, 'campaign_id' => $fresh->getKey(), 'subject_type' => $person['subject_type'], 'subject_id' => $person['subject_id'],
                    'name' => mb_substr($person['name'], 0, 200), 'email' => ContactNormalizer::email($person['email']), 'mobile' => ContactNormalizer::mobile($person['mobile']),
                    'status' => $address === null ? 'suppressed' : 'active', 'exit_reason' => $address === null ? 'no_address' : null, 'next_step' => 1,
                    'next_due_at' => $address === null ? null : $begin->copy()->addHours($first->delay_hours), 'lead_id' => $person['lead_id'], 'created_at' => now(), 'updated_at' => now(),
                ];

                if (count($rows) >= 500) {
                    CampaignMember::query()->insertOrIgnore($rows);
                    $rows = [];
                }
            }

            $rows === [] || CampaignMember::query()->insertOrIgnore($rows);

            if ($count === 0) {
                throw new FieldRuleException('The audience matches nobody, so there is nothing to launch.');
            }

            $fresh->forceFill(['state' => Campaign::RUNNING, 'launched_at' => now(), 'updated_by' => $actor->getKey()])->save();
            activity('campaigns')->causedBy($actor)->performedOn($fresh)->event('launched')->withProperties(['members' => $count])->log('Campaign launched');

            return $fresh->refresh();
        });
    }

    /** @throws FieldRuleException */
    public function pause(Campaign $campaign, User $actor): Campaign
    {
        return $this->move($campaign, $actor, [Campaign::RUNNING], Campaign::PAUSED, 'paused', 'Only a running campaign can be paused.');
    }

    /** @throws FieldRuleException */
    public function resume(Campaign $campaign, User $actor): Campaign
    {
        return $this->move($campaign, $actor, [Campaign::PAUSED], Campaign::RUNNING, 'resumed', 'Only a paused campaign can be resumed.');
    }

    /**
     * Call the campaign off. Everyone still in it leaves its sequence; what was already sent stays on record.
     *
     * @throws FieldRuleException
     * @throws ValidationException
     */
    public function cancel(Campaign $campaign, string $reason, User $actor): Campaign
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Say why the campaign is being called off.'])->validate();

        return DB::transaction(function () use ($campaign, $reason, $actor) {
            $fresh = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($fresh->state, [Campaign::DRAFT, Campaign::RUNNING, Campaign::PAUSED], true)) {
                throw new FieldRuleException('This campaign is already '.$fresh->state.'.');
            }

            $fresh->forceFill(['state' => Campaign::CANCELLED, 'cancel_reason' => $reason, 'completed_at' => now(), 'updated_by' => $actor->getKey()])->save();
            CampaignMember::query()->where('campaign_id', $fresh->getKey())->where('status', 'active')->update(['status' => 'exited', 'exit_reason' => 'campaign_cancelled', 'next_due_at' => null, 'updated_at' => now()]);
            activity('campaigns')->causedBy($actor)->performedOn($fresh)->event('cancelled')->withProperties(['reason' => $reason])->log('Campaign called off');

            return $fresh->refresh();
        });
    }

    /**
     * Take an approved campaign that has not been launched back to a draft.
     *
     * @throws FieldRuleException
     */
    public function reopen(Campaign $campaign, string $reason, User $actor): Campaign
    {
        $fresh = Campaign::query()->whereKey($campaign->getKey())->firstOrFail();

        if (! $fresh->isDraft()) {
            throw new FieldRuleException('A campaign that has been launched cannot be re-opened; call it off and make a new one.');
        }

        $this->approvals->reopen($fresh, $actor, $reason);

        return $fresh->refresh();
    }

    /**
     * The person answered (they rang back, replied, came in): their sequence ends and the campaign is credited with the response.
     *
     * @throws FieldRuleException
     */
    public function markResponded(CampaignMember $member, ?string $note, User $actor): CampaignMember
    {
        $won = CampaignMember::query()->whereKey($member->getKey())->whereNull('responded_at')
            ->update([
                'responded_at' => now(), 'exit_reason' => DB::raw("case when status = 'active' then 'responded' else exit_reason end"),   // before `status`: later assignments see earlier ones
                'status' => DB::raw("case when status = 'active' then 'exited' else status end"), 'next_due_at' => null, 'updated_at' => now(),
            ]);

        if ($won !== 1) {
            throw new FieldRuleException('This person is already marked as having responded.');
        }

        activity('campaigns')->causedBy($actor)->performedOn($member->campaign)->event('responded')->withProperties(['member' => $member->name, 'note' => $note === null ? null : mb_substr(trim($note), 0, 300)])->log('A campaign contact responded');

        return $member->refresh();
    }

    /**
     * Things that must be true before a campaign goes for approval (the model asks).
     *
     * @return list<string>
     */
    public function missingForApproval(Campaign $campaign): array
    {
        $missing = [];
        $steps = $campaign->steps()->with('template')->get();

        if ($steps->isEmpty()) {
            $missing[] = 'Add at least one message step.';
        }

        foreach ($steps as $step) {
            $t = $step->template;

            if ($t === null || ! $t->isApproved() || $t->status !== 'active') {
                $missing[] = 'Step '.$step->position.' uses a template that is not approved and active'.($t === null ? '.' : ': “'.$t->name.'”.');
            }
        }

        $campaign->audience === [] ? $missing[] = 'Say who the campaign is for (the audience is empty).' : null;

        if ($campaign->end_date !== null && $campaign->end_date->isPast()) {
            $missing[] = 'The end date has passed.';
        }

        return $missing;
    }

    // ------------------------------------------------------------------------------------------------------------------------------

    /** @param list<string> $from */
    private function move(Campaign $campaign, User $actor, array $from, string $to, string $event, string $message): Campaign
    {
        return DB::transaction(function () use ($campaign, $actor, $from, $to, $event, $message) {
            $fresh = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($fresh->state, $from, true)) {
                throw new FieldRuleException($message);
            }

            $fresh->forceFill(['state' => $to, 'updated_by' => $actor->getKey()])->save();
            activity('campaigns')->causedBy($actor)->performedOn($fresh)->event($event)->log('Campaign '.$event);

            return $fresh->refresh();
        });
    }

    /** @param list<array{delay_hours: int, message_template_id: int}> $steps */
    private function syncSteps(Campaign $campaign, array $steps): void
    {
        CampaignStep::query()->where('campaign_id', $campaign->getKey())->get()->each->delete();

        foreach ($steps as $i => $step) {
            (new CampaignStep)->forceFill([
                'company_id' => $campaign->company_id, 'campaign_id' => $campaign->getKey(), 'position' => $i + 1,
                'delay_hours' => $step['delay_hours'], 'message_template_id' => $step['message_template_id'],
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{fillable: array<string, mixed>, fixed: array<string, mixed>, location_id: int, steps: list<array{delay_hours: int, message_template_id: int}>}
     *
     * @throws ValidationException
     */
    private function validated(array $input, ?Campaign $editing, User $actor): array
    {
        $text = fn (string $k): ?string => is_scalar($input[$k] ?? null) && trim((string) $input[$k]) !== '' ? trim((string) $input[$k]) : null;
        $today = now()->toDateString();

        $v = Validator::make([
            'name' => $text('name'), 'type' => $text('type'), 'objective' => $text('objective'), 'start_date' => $text('start_date'), 'end_date' => $text('end_date'),
            'budget' => $text('budget'), 'audience_kind' => $text('audience_kind'), 'utm_source' => $text('utm_source'), 'utm_medium' => $text('utm_medium'), 'utm_campaign' => $text('utm_campaign'),
        ], [
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(array_keys(self::TYPES))],
            'objective' => ['nullable', 'string', 'max:2000'],
            'start_date' => ['required', 'date_format:Y-m-d', $editing === null ? 'after_or_equal:'.$today : 'after_or_equal:2000-01-01'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'budget' => ['nullable', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'audience_kind' => ['required', Rule::in(['leads', 'customers'])],
            'utm_source' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.\- ]+$/'],
            'utm_medium' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.\- ]+$/'],
            'utm_campaign' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.\- ]+$/'],
        ], [
            'name.required' => 'Give the campaign a name.', 'start_date.after_or_equal' => 'The campaign cannot start in the past.', 'end_date.after_or_equal' => 'The end date is before the start date.',
            'budget.regex' => 'The budget is an amount in rupees, with up to two decimals.', 'audience_kind.required' => 'Choose who the campaign is for.',
            'utm_source.regex' => 'Use letters, numbers, spaces, dots, dashes and underscores only.', 'utm_medium.regex' => 'Use letters, numbers, spaces, dots, dashes and underscores only.',
            'utm_campaign.regex' => 'Use letters, numbers, spaces, dots, dashes and underscores only.',
        ])->validate();

        // the owner: the person themselves or someone in their reach
        $ownerId = $text('owner_id') === null ? (int) $actor->getKey() : (ctype_digit((string) $text('owner_id')) ? (int) $text('owner_id') : 0);

        if (! array_key_exists($ownerId, ReportInput::people($actor))) {
            throw ValidationException::withMessages(['owner_id' => 'Choose yourself or someone on a team you manage.']);
        }

        // the branch: not changed once the campaign has a number
        $locationId = $editing !== null ? (int) $editing->location_id : (ctype_digit((string) $text('location_id')) ? (int) $text('location_id') : 0);

        if ($editing === null && ! Location::query()->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['location_id' => 'Choose a branch you work in.']);
        }

        $sourceId = $text('lead_source_id');

        if ($sourceId !== null && (! ctype_digit($sourceId) || ! LeadSource::query()->whereKey((int) $sourceId)->exists())) {
            throw ValidationException::withMessages(['lead_source_id' => 'Choose one of the company’s lead sources.']);
        }

        $stages = array_values(array_intersect(array_map('strval', (array) ($input['exit_stages'] ?? [])), ['contacted', 'qualified', 'deal', 'quotation', 'won', 'lost']));

        $steps = [];
        $rawSteps = array_values(array_filter((array) ($input['steps'] ?? []), 'is_array'));

        if (count($rawSteps) > self::MAX_STEPS) {
            throw ValidationException::withMessages(['steps' => 'A campaign has at most '.self::MAX_STEPS.' steps.']);
        }

        foreach ($rawSteps as $i => $step) {
            $delay = trim((string) ($step['delay_hours'] ?? '0'));
            $template = trim((string) ($step['message_template_id'] ?? ''));

            if ($delay === '' || ! ctype_digit($delay) || (int) $delay > 8760) {
                throw ValidationException::withMessages(["steps.{$i}.delay_hours" => 'The wait is a whole number of hours (0 to 8760).']);
            }

            if (! ctype_digit($template) || ! MessageTemplate::query()->whereKey((int) $template)->exists()) {
                throw ValidationException::withMessages(["steps.{$i}.message_template_id" => 'Choose a template for this step.']);
            }

            $steps[] = ['delay_hours' => (int) $delay, 'message_template_id' => (int) $template];
        }

        return [
            'fillable' => [
                'name' => $v['name'], 'type' => $v['type'], 'objective' => $v['objective'], 'start_date' => $v['start_date'], 'end_date' => $v['end_date'],
                'budget' => $v['budget'], 'utm_source' => $v['utm_source'], 'utm_medium' => $v['utm_medium'], 'utm_campaign' => $v['utm_campaign'],
            ],
            'fixed' => [
                'owner_id' => $ownerId, 'audience_kind' => $v['audience_kind'], 'audience' => $this->audiences->clean($v['audience_kind'], (array) ($input['audience'] ?? [])),
                'exit_stages' => $stages, 'lead_source_id' => $sourceId === null ? null : (int) $sourceId,
            ],
            'location_id' => $locationId,
            'steps' => $steps,
        ];
    }
}
