<?php

namespace App\Modules\Integrations\Providers;

use App\Modules\CRM\Enums\LeadChannel;
use App\Modules\Integrations\Exceptions\ProviderException;
use App\Modules\Integrations\Providers\Concerns\ReadsRecords;
use Carbon\CarbonInterface;

/**
 * Just Dial's lead feed — **push only**.
 *
 * What is known: Just Dial publishes no self-service developer API. A business gets its leads pushed to a web address of its
 * own when its Just Dial account manager switches that on (the integrations other CRMs offer all work this way: they give you
 * an address, you give it to Just Dial's team), or gets credentials for a pull API from the account manager. The record layout
 * below is the one such integrations commonly receive; it is tolerant — every field is optional, the lead id is the only thing
 * required — and it is **to be confirmed against the payload Just Dial actually sends your account**.
 *
 * Fields read: leadid, leadtype, prefix, name, mobile, phone, email, date, time, category, area, city, brancharea, pincode,
 * company, parentid, dncmobile / dncphone (the number is on the do-not-call register).
 *
 * There is nothing to pull, so `fetch()` says so; a pull adapter is added to this class if an account is given one.
 * Live verification is **pending** until Just Dial supplies the address configuration and a sample payload.
 */
final class JustDialProvider implements LeadProvider
{
    use ReadsRecords;

    public function key(): string
    {
        return 'justdial';
    }

    public function label(): string
    {
        return 'Just Dial';
    }

    public function sourceCode(): string
    {
        return 'JUSTDIAL';
    }

    public function channel(): LeadChannel
    {
        return LeadChannel::JustDial;
    }

    public function canPull(): bool
    {
        return false;
    }

    public function canPush(): bool
    {
        return true;
    }

    public function minPullGapMinutes(): int
    {
        return 0;
    }

    public function maxWindowDays(): int
    {
        return 0;
    }

    public function fetch(string $apiKey, CarbonInterface $from, CarbonInterface $to): array
    {
        throw ProviderException::invalid('Just Dial sends its leads to us; there is nothing to pull.');
    }

    public function extractRecords(array $body): array
    {
        foreach (['leads', 'data', 'response'] as $wrapper) {
            if (isset($body[$wrapper]) && is_array($body[$wrapper])) {
                $inner = $body[$wrapper];

                return $this->onlyRecords(array_is_list($inner) ? $inner : [$inner]);
            }
        }

        return isset($body['leadid']) ? [$body] : ($this->onlyRecords(array_is_list($body) ? $body : []));
    }

    public function providerLeadId(array $record): ?string
    {
        return $this->text($record, 'leadid', 'lead_id', 'LeadId');
    }

    public function normalise(array $record): InboundLead
    {
        $id = $this->providerLeadId($record) ?? '';

        $person = trim(($this->text($record, 'prefix') ?? '').' '.($this->text($record, 'name') ?? ''));
        $person = $person === '' ? null : $person;
        $company = $this->text($record, 'company');
        $category = $this->text($record, 'category');
        $area = $this->text($record, 'area');
        $branch = $this->text($record, 'brancharea');

        $received = $this->moment(trim(($this->text($record, 'date') ?? '').' '.($this->text($record, 'time') ?? '')) ?: null);

        return new InboundLead(
            providerLeadId: $id,
            name: $company ?? $person,
            contactPerson: $company === null ? null : $person,
            mobile: $this->text($record, 'mobile', 'phone'),
            email: $this->text($record, 'email'),
            city: $this->text($record, 'city') ?? $area,
            pinCode: $this->text($record, 'pincode'),
            address: $branch,
            product: $category,
            message: $this->describe($category, $area, $branch),
            receivedAt: $received,
            kind: $this->text($record, 'leadtype'),
            namePlaceholder: $company === null && $person === null,
            doNotCall: $this->truthy($record['dncmobile'] ?? null) || $this->truthy($record['dncphone'] ?? null),
        );
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private function onlyRecords(array $rows): array
    {
        return array_values(array_filter($rows, fn ($row) => is_array($row)));
    }

    private function describe(?string $category, ?string $area, ?string $branch): ?string
    {
        $parts = array_filter([
            $category === null ? null : "Looked for: {$category}",
            $area === null ? null : "Area: {$area}",
            $branch === null ? null : "Branch area: {$branch}",
        ]);

        return $parts === [] ? null : implode('. ', $parts).'.';
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'y', 'yes', 'true'], true);
    }
}
