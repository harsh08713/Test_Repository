<?php

namespace App\Modules\Integrations\Services;

use App\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Services\LeadService;
use App\Modules\CRM\Services\SalesTeamService;
use App\Modules\Integrations\Enums\InboundStatus;
use App\Modules\Integrations\Exceptions\IntakeProblem;
use App\Modules\Integrations\Models\IntegrationConnection;
use App\Modules\Integrations\Models\LeadApiLog;
use App\Modules\Integrations\Notifications\MarketplaceEnquiryNotification;
use App\Modules\Integrations\Notifications\PoolLeadNotification;
use App\Modules\Integrations\Providers\InboundLead;
use App\Modules\Integrations\Providers\LeadProvider;
use App\Modules\Integrations\Providers\ProviderRegistry;
use App\Modules\Integrations\Support\PayloadRedactor;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Services\CampaignAttribution;
use App\Modules\Marketing\Services\ConsentService;
use App\Modules\Marketing\Services\ContactNormalizer;
use App\Modules\Shared\Models\Comment;
use App\Modules\Shared\Rules\IndianMobile;
use App\Modules\Shared\Rules\IndianPinCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Turns one stored delivery into what it should become. The same path for every provider, for a push, a pull and a retry by hand.
 *
 *  1. **Claim** the row (received or failed → processing) with one conditional UPDATE, so two workers never take the same
 *     delivery; a row stuck in `processing` for ten minutes (a worker died) can be claimed again.
 *  2. **Same mobile number as an open lead?** Then there is no second lead: a note is left on that lead, its owner is told,
 *     and the delivery is `linked`. (The check and the creation happen under a lock on the number, so two enquiries from one
 *     buyer arriving together make one lead.)
 *  3. Otherwise **create the lead** through LeadService — the same door as every other channel, so every lead rule holds — in
 *     the connection's branch, filed under the provider's standard source, owned by the connection's chosen person or left in
 *     the pool, and entered as the person who set the connection up.
 *
 * A marketplace lead may arrive without a mobile number, with only the placeholder name "IndiaMART Buyer", or with a foreign
 * number: it is kept, marked **incomplete**, and cannot be qualified until someone completes it. Things that are not valid (a
 * mobile number, an e-mail address, a PIN code) are left out of the lead and noted in its remarks, never allowed to sink it.
 *
 * Anything that goes wrong leaves the delivery `failed` with a redacted reason and its payload intact (the dead-letter queue).
 */
final class LeadIntake
{
    /** A delivery claimed this long ago and still "processing" belongs to a worker that is gone. */
    public const STALE_MINUTES = 10;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly LeadService $leads,
        private readonly SalesTeamService $teams,
        private readonly ConsentService $consents,
        private readonly CampaignAttribution $attribution,
    ) {}

    /**
     * @throws Throwable what went wrong, after the delivery has been marked failed (an IntakeProblem when only a person can fix it)
     */
    public function process(LeadApiLog $log): InboundStatus
    {
        if (! $this->claim($log)) {
            return $log->fresh()->status ?? $log->status;
        }

        $log->refresh();

        try {
            return $this->run($log);
        } catch (Throwable $e) {
            $this->markFailed($log, $e);

            throw $e;
        }
    }

    private function claim(LeadApiLog $log): bool
    {
        $claimed = LeadApiLog::query()->whereKey($log->getKey())
            ->where(fn ($q) => $q->whereIn('status', ['received', 'failed'])
                ->orWhere(fn ($stale) => $stale->where('status', 'processing')->where('updated_at', '<=', now()->subMinutes(self::STALE_MINUTES))))
            ->update(['status' => 'processing', 'attempts' => DB::raw('attempts + 1'), 'error' => null]);

        return $claimed === 1;
    }

    private function run(LeadApiLog $log): InboundStatus
    {
        $connection = IntegrationConnection::query()->find($log->integration_connection_id)
            ?? throw new IntakeProblem('The connection this lead came through no longer exists.');
        $provider = $this->providers->get($log->provider);
        $actor = $this->actor($connection);
        $inbound = $provider->normalise($log->payload);

        $mobile = $inbound->mobile === null ? null : IndianMobile::normalise($inbound->mobile);

        $work = function () use ($log, $connection, $provider, $actor, $inbound, $mobile): InboundStatus {
            $existing = $mobile === null ? null : $this->openLeadWith($mobile);
            $consent = $this->consentPlan($inbound, $mobile, $this->email($inbound->email), (int) $connection->company_id);

            $status = $existing === null
                ? $this->create($log, $connection, $provider, $actor, $inbound, $mobile, $consent['lines'])
                : $this->link($log, $provider, $actor, $inbound, $existing, $consent['lines']);

            // only once the lead (or the note) is safely made: a failure above leaves no consent behind, and a retry works the same plan out again
            $this->applyConsent($consent['grants'], $log, $inbound->consentText, (int) $connection->company_id);

            return $status;
        };

        // two enquiries from one buyer arriving together must make one lead, not two
        return $mobile === null ? $work() : Cache::lock("lead-intake:{$connection->company_id}:{$mobile}", 60)->block(20, $work);
    }

    /** @param  list<string>  $consentLines */
    private function create(LeadApiLog $log, IntegrationConnection $connection, LeadProvider $provider, User $actor, InboundLead $inbound, ?string $mobile, array $consentLines = []): InboundStatus
    {
        $sourceId = LeadSource::query()->where('code', $provider->sourceCode())->where('status', 'active')->value('id')
            ?? throw new IntakeProblem("The standard “{$provider->label()}” lead source is missing or switched off. Switch it on under CRM → Lead masters.");

        $incomplete = $mobile === null || $inbound->namePlaceholder;
        $email = $this->email($inbound->email);
        $pin = $inbound->pinCode !== null && IndianPinCode::isValid($inbound->pinCode) ? $inbound->pinCode : null;
        $name = $inbound->name ?? "{$provider->label()} enquiry".($mobile === null ? '' : " {$mobile}");

        $input = [
            'lead' => [
                'location_id' => (string) $connection->default_location_id,
                'lead_source_id' => (string) $sourceId,
                'lead_date' => $this->leadDate($inbound),
                'name' => Str::limit($name, 200, ''),
                'contact_person' => $inbound->contactPerson === null ? null : Str::limit($inbound->contactPerson, 150, ''),
                'mobile' => $mobile,
                'email' => $email,
                'city' => $inbound->city === null ? null : Str::limit($inbound->city, 100, ''),
                'address_line1' => $inbound->address === null ? null : Str::limit($inbound->address, 200, ''),
                'pin_code' => $pin,
                'requirement' => Str::limit($inbound->message ?? $inbound->product ?? '', 2000, ''),
                'remarks' => Str::limit($this->remarks($log, $provider, $inbound, $mobile, $email, $pin, $consentLines), 1000, ''),
                'assigned_to' => $connection->assigned_to === null ? '' : (string) $connection->assigned_to,
                'acknowledge_duplicate' => true,   // this intake has just looked, under a lock
            ],
            'interests' => $inbound->product === null ? [] : [['description' => Str::limit($inbound->product, 200, '')]],
        ];

        try {
            $lead = $this->leads->create($input, $actor, $provider->channel(), null, null, $incomplete);
        } catch (ValidationException $e) {
            $problem = collect($e->errors())->map(fn (array $messages, string $field) => preg_replace('/^lead\./', '', $field).': '.$messages[0])->first();

            throw new IntakeProblem("The lead was refused ({$problem}). Check the connection's branch and owner.");
        }

        $log->forceFill(['status' => 'created', 'lead_id' => $lead->getKey(), 'processed_at' => now(), 'error' => null])->save();

        $this->attribute($lead, $inbound);

        activity('integrations')->performedOn($lead)->event('received')
            ->withProperties(['provider' => $provider->key(), 'provider_lead_id' => $log->provider_lead_id, 'via' => $log->via, 'incomplete' => $incomplete])
            ->log('received');

        if ($lead->assigned_to === null) {
            $this->tellThePool($provider, $lead, $incomplete);
        }

        return InboundStatus::Created;
    }

    /**
     * Leave a note on the lead that already has this number, and tell its owner.
     *
     * @param  list<string>  $consentLines
     */
    private function link(LeadApiLog $log, LeadProvider $provider, User $actor, InboundLead $inbound, Lead $existing, array $consentLines = []): InboundStatus
    {
        $summary = Str::limit(trim(($inbound->kind === null ? '' : "{$inbound->kind}: ").($inbound->message ?? $inbound->product ?? 'no message')), 300, '…');

        $note = new Comment(['user_id' => $actor->getKey(), 'body' => trim("Another {$provider->label()} enquiry from this number (id {$log->provider_lead_id}). {$summary} ".implode(' ', $consentLines))]);
        $note->forceFill([
            'visibility' => 'internal', 'company_id' => (int) $existing->company_id,
            'commentable_type' => $existing->getMorphClass(), 'commentable_id' => $existing->getKey(),
        ])->save();

        // the lead has changed (a new note): a syncing app should see it
        Lead::query()->whereKey($existing->getKey())->update(['updated_at' => now()]);

        $log->forceFill(['status' => 'linked', 'lead_id' => $existing->getKey(), 'processed_at' => now(), 'error' => null])->save();

        $owner = $existing->assigned_to === null ? null : User::query()->where('is_active', true)->find($existing->assigned_to);
        $owner?->notify(new MarketplaceEnquiryNotification($provider->label(), $existing->title(), $summary, route('crm.leads.show', $existing, false)));

        return InboundStatus::Linked;
    }

    private function actor(IntegrationConnection $connection): User
    {
        if ($connection->default_location_id === null || $connection->actor_user_id === null) {
            throw new IntakeProblem('The connection has no branch or no person to enter leads as. Finish setting it up under Administration → Integrations.');
        }

        $actor = User::query()->find($connection->actor_user_id);

        if ($actor === null || ! $actor->is_active) {
            throw new IntakeProblem('The person these leads are entered as is no longer an active user. Open the connection and save it again as someone who is.');
        }

        if (! $actor->can('lead.create')) {
            throw new IntakeProblem("{$actor->name} may no longer create leads, so none can be entered as them. Save the connection again as someone who may.");
        }

        return $actor;
    }

    /** An open lead of the company with this mobile number — whichever branch, whoever owns it. */
    private function openLeadWith(string $mobile): ?Lead
    {
        $found = $this->leads->duplicates($mobile)->first(fn (Lead $lead) => $lead->isOpen());

        return $found === null ? null : Lead::query()->find($found->getKey());
    }

    private function email(?string $email): ?string
    {
        return $email !== null && mb_strlen($email) <= 150 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? Str::lower($email) : null;
    }

    /** The day the enquiry was made — never in the future, whatever the provider's clock says. */
    private function leadDate(InboundLead $inbound): string
    {
        $when = $inbound->receivedAt;

        return ($when === null || $when->isFuture() ? now() : $when)->toDateString();
    }

    /** @param  list<string>  $consentLines */
    private function remarks(LeadApiLog $log, LeadProvider $provider, InboundLead $inbound, ?string $mobile, ?string $email, ?string $pin, array $consentLines = []): string
    {
        $lines = [trim("{$provider->label()} ".($inbound->kind === null ? 'enquiry' : strtolower($inbound->kind))." (id {$log->provider_lead_id})".($inbound->receivedAt === null ? '' : ', received '.$inbound->receivedAt->format('j M Y, H:i')).'.')];

        if ($inbound->mobile !== null && $mobile === null) {
            $lines[] = "Phone as given: {$inbound->mobile}".($inbound->countryIso !== null && strtoupper($inbound->countryIso) !== 'IN' ? " ({$inbound->countryIso})" : '').' — not an Indian mobile number, so left off the lead.';
        }

        if ($mobile === null && $inbound->mobile === null) {
            $lines[] = 'No mobile number was given: find it before this lead is qualified.';
        }

        if ($inbound->email !== null && $email === null) {
            $lines[] = "E-mail as given: {$inbound->email} — not a valid address, so left off.";
        }

        if ($inbound->pinCode !== null && $pin === null) {
            $lines[] = "PIN as given: {$inbound->pinCode}.";
        }

        if ($inbound->state !== null) {
            $lines[] = "State as given: {$inbound->state}.";
        }

        if ($inbound->namePlaceholder) {
            $lines[] = 'The buyer gave no name.';
        }

        if ($inbound->doNotCall) {
            $lines[] = 'On the do-not-call register: do not phone this number.';
        }

        if ($inbound->pageUrl !== null) {
            $lines[] = "Sent from the page {$inbound->pageUrl}.";
        }

        return implode("\n", [...$lines, ...$consentLines]);
    }

    /**
     * What the visitor ticked, read before anything is made and written only afterwards (`applyConsent`). A tick is taken as an opt-in for a channel only
     * when the form named the channel and gave the wording, there is a usable address for it, and that address has not opted **out**: an opt-out is
     * the person's own word, and an unverified form (nobody has proved the number is the visitor's) must not undo it. The lines go onto the lead so the
     * person who calls knows what was agreed.
     *
     * @return array{grants: list<array{0: Channel, 1: string}>, lines: list<string>}
     */
    private function consentPlan(InboundLead $inbound, ?string $mobile, ?string $email, int $companyId): array
    {
        $grants = [];
        $lines = [];

        foreach ($inbound->consentChannels as $name) {
            $channel = Channel::tryFrom($name);
            $address = $channel === Channel::Email ? $email : $mobile;

            if ($channel === null) {
                continue;
            }

            if ($address === null) {
                $lines[] = "Ticked the box for {$channel->label()} but gave no usable {$channel->addressLabel()}, so nothing was recorded.";

                continue;
            }

            $normal = ContactNormalizer::address($channel, $address);
            $latest = $normal === null ? null : $this->consents->latest($channel, $normal, $companyId);

            if ($latest !== null && $latest->status === 'opted_out') {
                $lines[] = "Ticked the box for {$channel->label()}, but this {$channel->addressLabel()} had opted out and the form cannot prove who filled it in: ask them first.";

                continue;
            }

            $grants[] = [$channel, $address];
            $lines[] = "Agreed on the form to be contacted by {$channel->label()}: “{$inbound->consentText}”.";
        }

        return ['grants' => $grants, 'lines' => $lines];
    }

    /**
     * The consent the plan allowed. A failure is reported and never undoes the lead: the person can still be asked again, and what is on the lead says what
     * was ticked.
     *
     * @param  list<array{0: Channel, 1: string}>  $grants
     */
    private function applyConsent(array $grants, LeadApiLog $log, ?string $text, int $companyId): void
    {
        foreach ($grants as [$channel, $address]) {
            try {
                $this->consents->record($channel, $address, 'opted_in', 'website', "Ticked on the website form (id {$log->provider_lead_id}): {$text}", null, $companyId);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** Credit the lead to the campaign and UTM values its page carried. Attribution must never stop a lead from being made. */
    private function attribute(Lead $lead, InboundLead $inbound): void
    {
        if ($inbound->utm === [] && $inbound->campaignCode === null) {
            return;
        }

        try {
            $campaignId = $inbound->campaignCode === null ? null : Campaign::query()->where('code', $inbound->campaignCode)->value('id');

            $this->attribution->tag($lead, $campaignId === null ? null : (int) $campaignId, $inbound->utm, 'link');
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** A lead nobody owns yet is the pool's: tell the people who hand leads out (in the app only, and no more than twenty of them). */
    private function tellThePool(LeadProvider $provider, Lead $lead, bool $incomplete): void
    {
        $recipients = User::query()->whereIn('id', array_keys($this->teams->staffInBranch((int) $lead->location_id)))->get()
            ->filter(fn (User $user) => $user->can('lead.assign'))->take(20);

        foreach ($recipients as $user) {
            $user->notify(new PoolLeadNotification($provider->label(), $lead->title(), $incomplete, route('crm.leads.show', $lead, false)));
        }
    }

    private function markFailed(LeadApiLog $log, Throwable $e): void
    {
        $message = $e instanceof IntakeProblem ? $e->getMessage() : class_basename($e).': '.$e->getMessage();

        LeadApiLog::query()->whereKey($log->getKey())->update([
            'status' => 'failed',
            'error' => mb_substr(PayloadRedactor::text($message), 0, 1000),
        ]);
    }
}
