<?php

namespace App\Modules\Integrations\Providers;

use App\Modules\CRM\Enums\LeadChannel;
use App\Modules\Integrations\Exceptions\ProviderException;
use Carbon\CarbonInterface;

/**
 * A marketplace that hands us leads. An adapter knows only its provider's wire format and limits; it never touches the
 * database, a setting or a lead — the sync and intake services do that, the same way for every provider — so it can be tested
 * against recorded responses alone and a new provider is one new class.
 */
interface LeadProvider
{
    /** The short name used in addresses and the database: `indiamart`, `justdial`. */
    public function key(): string;

    public function label(): string;

    /** The code of the company's standard lead source this provider's leads are filed under. */
    public function sourceCode(): string;

    public function channel(): LeadChannel;

    /** Can we fetch leads from it (a pull API), or does it only push? */
    public function canPull(): bool;

    /** Can it push leads to us? */
    public function canPush(): bool;

    /** The least time the provider allows between two calls with the same key. */
    public function minPullGapMinutes(): int;

    /** The widest stretch of time one call may ask for. */
    public function maxWindowDays(): int;

    /**
     * Fetch the leads that arrived between two moments.
     *
     * @return list<array<string, mixed>> the provider's records, untouched
     *
     * @throws ProviderException with the kind of trouble: `auth`, `rate_limited`, `invalid` or `unavailable`
     */
    public function fetch(string $apiKey, CarbonInterface $from, CarbonInterface $to): array;

    /**
     * The records inside what the provider pushed (one lead, a list, or a wrapper around either).
     *
     * @param  array<array-key, mixed>  $body
     * @return list<array<string, mixed>>
     */
    public function extractRecords(array $body): array;

    /** @param  array<string, mixed>  $record */
    public function providerLeadId(array $record): ?string;

    /** @param  array<string, mixed>  $record */
    public function normalise(array $record): InboundLead;
}
