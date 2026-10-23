<?php

namespace App\Modules\Integrations\Services;

use App\Modules\Integrations\Jobs\ProcessLeadApiLog;
use App\Modules\Integrations\Models\IntegrationConnection;
use App\Modules\Integrations\Models\LeadApiLog;
use App\Modules\Integrations\Providers\LeadProvider;
use App\Modules\Integrations\Support\PayloadRedactor;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes a delivery down — the first thing that happens to anything a provider hands over, before a lead is considered.
 *
 * The record is stored with secrets removed (by field name, and by value for the company's own key and signing secret), and is
 * unique per company, provider and the provider's lead id: the second time the same enquiry arrives — a retry, the pull window's
 * overlap, a push that was also pulled — it is not stored again, only counted. If the record carries no id at all, a stable one
 * is made from its content, so even that cannot be taken twice.
 */
final class LeadApiLogStore
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  array<string, mixed>  $record
     * @return array{0: LeadApiLog, 1: bool} the row, and whether this delivery is the first of that lead
     */
    public function record(IntegrationConnection $connection, LeadProvider $provider, array $record, string $via): array
    {
        $payload = PayloadRedactor::payload($record, $this->secrets($connection));
        $encoded = (string) json_encode($payload);
        $leadId = mb_substr($provider->providerLeadId($record) ?? 'h-'.substr(hash('sha256', $encoded), 0, 32), 0, 100);

        $log = new LeadApiLog;
        $log->forceFill([
            'company_id' => (int) $connection->company_id,
            'integration_connection_id' => $connection->getKey(),
            'provider' => $provider->key(),
            'provider_lead_id' => $leadId,
            'via' => $via,
            'status' => 'received',
            'payload' => $payload,
            'payload_hash' => hash('sha256', $encoded),
        ]);

        try {
            $log->save();

            return [$log, true];
        } catch (UniqueConstraintViolationException) {
            $existing = LeadApiLog::query()->where('provider', $provider->key())->where('provider_lead_id', $leadId)->firstOrFail();
            LeadApiLog::query()->whereKey($existing->getKey())->update(['received_count' => DB::raw('received_count + 1')]);

            return [$existing, false];
        }
    }

    /**
     * Queue a stored delivery for processing. The delivery is already safe in the log, so a queue that cannot take it right now is
     * not an error: the retry command picks up what was never processed.
     */
    public function dispatch(LeadApiLog $log): void
    {
        try {
            ProcessLeadApiLog::dispatch((int) $log->company_id, (int) $log->getKey());
        } catch (Throwable) {
            // with a synchronous queue a failed delivery surfaces here; it is marked failed in the log already
        }
    }

    /** @return list<string> the connection's own secrets, to be scrubbed from anything stored */
    public function secrets(IntegrationConnection $connection): array
    {
        $companyId = (int) $connection->company_id;

        return array_values(array_filter([
            $this->secret("integrations.{$connection->provider}_api_key", $companyId),
            $this->secret("integrations.{$connection->provider}_webhook_secret", $companyId),
            $connection->public_key,
        ]));
    }

    private function secret(string $key, int $companyId): ?string
    {
        $value = $this->settings->get($key, $companyId);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
