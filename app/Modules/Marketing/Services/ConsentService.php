<?php

namespace App\Modules\Marketing\Services;

use App\Models\User;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Models\ContactConsent;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * What each person has agreed to or refused, channel by channel. Consent is a history of events (`contact_consents`, append-only); the newest event
 * for an address and channel is the answer, and an opt-out always wins until the person opts in again.
 *
 * The rules (`check()`), applied before every message and again just before it is handed over:
 *  - an address that has opted out is never written to — marketing or not;
 *  - a **marketing** message to WhatsApp or SMS needs an explicit opt-in; to e-mail it needs one too unless the company says it does not
 *    (`marketing.email_requires_optin`, on by default);
 *  - a **transactional** message (about something the person asked for) needs only that they have not opted out.
 */
final class ConsentService
{
    public function __construct(private readonly SettingsService $settings, private readonly TenantContext $tenant) {}

    /**
     * @throws ValidationException
     */
    public function record(Channel $channel, string $address, string $status, string $source, ?string $note = null, ?User $by = null, ?int $companyId = null): ContactConsent
    {
        $normal = ContactNormalizer::address($channel, $address);

        if ($normal === null) {
            throw ValidationException::withMessages(['address' => 'That is not a valid '.$channel->addressLabel().'.']);
        }

        if (! in_array($status, ['opted_in', 'opted_out'], true)) {
            throw ValidationException::withMessages(['status' => 'Choose opted in or opted out.']);
        }

        $companyId ??= (int) $this->tenant->activeCompanyId();

        // the same answer again, from the same place, adds nothing to the history
        $latest = $this->latest($channel, $normal, $companyId);

        if ($latest !== null && $latest->status === $status && $latest->source === $source) {
            return $latest;
        }

        $row = new ContactConsent;
        $row->forceFill([
            'company_id' => $companyId, 'channel' => $channel->value, 'address' => $normal, 'status' => $status, 'source' => mb_substr($source, 0, 20),
            'note' => $note === null ? null : mb_substr(trim($note), 0, 300), 'recorded_by' => $by?->getKey(),
        ])->save();

        return $row;
    }

    public function latest(Channel $channel, string $normalAddress, ?int $companyId = null): ?ContactConsent
    {
        $companyId ??= (int) $this->tenant->activeCompanyId();

        return ContactConsent::withoutGlobalScopes()->where('company_id', $companyId)->where('channel', $channel->value)->where('address', $normalAddress)
            ->orderByDesc('id')->first();
    }

    /**
     * May this address be written to on this channel for this purpose?
     *
     * @return array{ok: bool, reason: string|null}
     */
    public function check(Channel $channel, ?string $address, string $purpose, ?int $companyId = null): array
    {
        $normal = ContactNormalizer::address($channel, $address);

        if ($normal === null) {
            return ['ok' => false, 'reason' => 'No valid '.$channel->addressLabel().'.'];
        }

        $companyId ??= (int) $this->tenant->activeCompanyId();
        $latest = $this->latest($channel, $normal, $companyId);

        if ($latest?->status === 'opted_out') {
            return ['ok' => false, 'reason' => 'Opted out.'];
        }

        if ($purpose === 'marketing') {
            $needsOptIn = $channel !== Channel::Email || (bool) $this->settings->get('marketing.email_requires_optin', $companyId);

            if ($needsOptIn && $latest?->status !== 'opted_in') {
                return ['ok' => false, 'reason' => 'No consent to marketing on '.$channel->label().'.'];
            }
        }

        return ['ok' => true, 'reason' => null];
    }

    /** A link the person can follow to opt out, signed so that it cannot be altered; it carries the address encrypted. */
    public function unsubscribeUrl(Channel $channel, string $address, ?int $companyId = null): string
    {
        return URL::signedRoute('marketing.unsubscribe', [
            'company' => $companyId ?? (int) $this->tenant->activeCompanyId(), 'channel' => $channel->value, 'a' => Crypt::encryptString($address),
        ]);
    }
}
