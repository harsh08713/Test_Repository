<?php

namespace App\Modules\Integrations\Providers;

use App\Modules\CRM\Enums\LeadChannel;
use App\Modules\Integrations\Exceptions\ProviderException;
use App\Modules\Integrations\Providers\Concerns\ReadsRecords;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * IndiaMART's **Lead Manager** lead feed — the Pull API, and the push form of the same records.
 *
 * What the provider documents (https://help.indiamart.com/knowledge-base/lms-crm-integration-v2, read in October 2026):
 *  - `GET https://mapi.indiamart.com/wservce/crm/crmListing/v2/?glusr_crm_key=…&start_time=…&end_time=…`, times in IST;
 *  - the answer is JSON: `CODE`, `STATUS`, `MESSAGE`, `TOTAL_RECORDS`, `RESPONSE` (a list of leads);
 *  - at most **7 days** per call and 365 days back; one call per **5 minutes** (a second one sooner is refused with 429, and
 *    more than 5 in a minute blocks the key for 15 minutes); the key goes stale after 7 days unused or when a new one is made;
 *  - errors by `CODE`: 429 too often, 401 wrong or expired key, 204 no leads, 400 bad range, 500 provider trouble;
 *  - available only to a **paid seller** with the Leads API add-on, and the integration is the buyer's own work.
 *
 * Lead fields used: UNIQUE_QUERY_ID (the id to de-duplicate on), QUERY_TYPE, QUERY_TIME, SENDER_NAME ("IndiaMART Buyer" when
 * unknown), SENDER_MOBILE / SENDER_EMAIL (one is always there), SENDER_COUNTRY_ISO, QUERY_MESSAGE; and — from the commonly
 * published record layout, **to be confirmed on a live account** — SENDER_COMPANY, SENDER_ADDRESS, SENDER_CITY, SENDER_STATE,
 * SENDER_PINCODE, SUBJECT, QUERY_PRODUCT_NAME, QUERY_MCAT_NAME. Every one of them may be missing and none is required.
 *
 * Live verification against a real key is **pending**: this adapter is built and tested against the documented shapes.
 */
final class IndiaMartProvider implements LeadProvider
{
    use ReadsRecords;

    public const URL = 'https://mapi.indiamart.com/wservce/crm/crmListing/v2/';

    private const KINDS = [
        'W' => 'Direct enquiry', 'B' => 'Buy-lead', 'P' => 'Phone enquiry (PNS)', 'BIZ' => 'Catalogue view', 'WA' => 'WhatsApp enquiry',
    ];

    public function key(): string
    {
        return 'indiamart';
    }

    public function label(): string
    {
        return 'IndiaMART';
    }

    public function sourceCode(): string
    {
        return 'INDIAMART';
    }

    public function channel(): LeadChannel
    {
        return LeadChannel::IndiaMart;
    }

    public function canPull(): bool
    {
        return true;
    }

    public function canPush(): bool
    {
        return true;
    }

    public function minPullGapMinutes(): int
    {
        return 5;
    }

    public function maxWindowDays(): int
    {
        return 7;
    }

    public function fetch(string $apiKey, CarbonInterface $from, CarbonInterface $to): array
    {
        try {
            $response = Http::timeout(20)->acceptJson()->get(self::URL, [
                'glusr_crm_key' => $apiKey,
                'start_time' => $this->time($from),
                'end_time' => $this->time($to),
            ]);
        } catch (ConnectionException) {
            // never the underlying message: it names the address, and the address carries the key
            throw ProviderException::unavailable('IndiaMART could not be reached. It will be tried again.');
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $code = (int) ($body['CODE'] ?? $response->status());

        return match (true) {
            $code === 204 => [],
            $code === 429 => throw ProviderException::rateLimited('IndiaMART says it was asked too often (one call per 5 minutes; five in a minute blocks the key for 15). Waiting before the next try.', 16),
            $code === 401 => throw ProviderException::auth('IndiaMART did not accept the key — it is wrong, has expired (unused for 7 days) or a newer one was generated. Set the new key in Company settings.'),
            $code === 400 => throw ProviderException::invalid('IndiaMART refused the period that was asked for (longer than allowed, or missing).'),
            $code === 200 => $this->records($body),
            default => throw ProviderException::unavailable("IndiaMART answered with a problem (code {$code}). It will be tried again."),
        };
    }

    public function extractRecords(array $body): array
    {
        if (array_key_exists('RESPONSE', $body)) {
            $inner = $body['RESPONSE'];

            return is_array($inner) ? $this->records(['RESPONSE' => $inner]) : [];
        }

        if (isset($body['UNIQUE_QUERY_ID'])) {
            return [$body];
        }

        return array_is_list($body) ? $this->records(['RESPONSE' => $body]) : [];
    }

    public function providerLeadId(array $record): ?string
    {
        return $this->text($record, 'UNIQUE_QUERY_ID');
    }

    public function normalise(array $record): InboundLead
    {
        $id = $this->providerLeadId($record) ?? '';

        $person = $this->text($record, 'SENDER_NAME');
        $placeholder = $person === null || strcasecmp($person, 'IndiaMART Buyer') === 0;
        $company = $this->text($record, 'SENDER_COMPANY');
        $kind = $this->text($record, 'QUERY_TYPE');

        return new InboundLead(
            providerLeadId: $id,
            name: $company ?? ($placeholder ? null : $person),
            contactPerson: $placeholder ? null : $person,
            mobile: $this->text($record, 'SENDER_MOBILE'),
            email: $this->text($record, 'SENDER_EMAIL'),
            city: $this->text($record, 'SENDER_CITY'),
            state: $this->text($record, 'SENDER_STATE'),
            pinCode: $this->text($record, 'SENDER_PINCODE'),
            address: $this->text($record, 'SENDER_ADDRESS'),
            product: $this->text($record, 'QUERY_PRODUCT_NAME', 'QUERY_MCAT_NAME', 'SUBJECT'),
            message: $this->message($this->text($record, 'QUERY_MESSAGE')),
            receivedAt: $this->moment($this->text($record, 'QUERY_TIME')),
            kind: $kind === null ? null : (self::KINDS[strtoupper($kind)] ?? $kind),
            countryIso: $this->text($record, 'SENDER_COUNTRY_ISO'),
            namePlaceholder: $company === null && $placeholder,
        );
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function records(array $body): array
    {
        $rows = $body['RESPONSE'] ?? [];
        $rows = is_array($rows) ? (array_is_list($rows) ? $rows : [$rows]) : [];

        return array_values(array_filter($rows, fn ($row) => is_array($row)));
    }

    /** `01-Jan-202209:00:00`, the form the provider's own examples use, in IST. */
    private function time(CarbonInterface $moment): string
    {
        return $moment->copy()->setTimezone('Asia/Kolkata')->format('d-M-YH:i:s');
    }

    /** The message may carry literal escape sequences (\n, \t, \r); turn them into what they mean. */
    private function message(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return trim(str_replace(['\\r', '\\t', '\\n', '\\\\'], ['', ' ', "\n", '\\'], $text)) ?: null;
    }
}
