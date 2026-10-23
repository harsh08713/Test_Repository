<?php

namespace App\Modules\Integrations\Models;

use App\Modules\CRM\Models\Lead;
use App\Modules\Integrations\Enums\InboundStatus;
use App\Modules\Integrations\Policies\LeadApiLogPolicy;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One lead a provider handed over, kept exactly as received (minus anything secret) together with what became of it.
 *
 * It is written **before** anything else happens to the delivery, so a delivery is never lost; a row that failed is the
 * dead-letter queue — the payload is still here, and it can be processed again by hand. Unique per company, provider and
 * the provider's own lead id, so one enquiry delivered twice (a retry, or by pull and by push) is one row; `received_count`
 * says how often it came.
 *
 * @property int $integration_connection_id
 * @property string $provider
 * @property string $provider_lead_id
 * @property string $via
 * @property InboundStatus $status
 * @property int|null $lead_id
 * @property int $attempts
 * @property int $received_count
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property string|null $error
 * @property Carbon|null $processed_at
 */
#[UsePolicy(LeadApiLogPolicy::class)]
class LeadApiLog extends Model
{
    use BelongsToCompany;

    // nothing is mass-assignable: LeadApiLogStore writes it with forceFill(), from values the server worked out

    protected function casts(): array
    {
        return [
            'status' => InboundStatus::class,
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<IntegrationConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
