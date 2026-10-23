<?php

namespace App\Modules\Integrations\Models;

use App\Models\User;
use App\Modules\Integrations\Enums\ConnectionStatus;
use App\Modules\Integrations\Policies\IntegrationConnectionPolicy;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;

/**
 * How one company is wired to one lead provider (IndiaMART, Just Dial) and how that is going: pulling, receiving pushes or
 * both, which branch and owner the leads go to, who they are entered as, and the health of the last attempts.
 *
 * It holds **no secret** — the provider's API key and the webhook signing secret are company settings (encrypted, write-only).
 * The `public_key` is the unguessable part of the webhook address, so it is hidden from serialisation and kept out of the audit
 * log, like the sync bookkeeping that changes every few minutes.
 *
 * @property string $provider
 * @property bool $enabled
 * @property string $mode
 * @property string $public_key
 * @property int|null $default_location_id
 * @property int|null $assigned_to
 * @property int|null $actor_user_id
 * @property int $pull_interval_minutes
 * @property int $initial_lookback_hours
 * @property Carbon|null $cursor_at
 * @property Carbon|null $next_pull_at
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_received_at
 * @property int $consecutive_failures
 * @property string|null $failure_kind
 * @property string|null $last_error
 */
#[Hidden(['public_key'])]
#[UsePolicy(IntegrationConnectionPolicy::class)]
class IntegrationConnection extends TenantModel
{
    /** A connection has no active/inactive `status`: it is enabled or not, and its health is worked out. */
    protected $attributes = [
        'enabled' => false,
        'mode' => 'webhook',
        'pull_interval_minutes' => 15,
        'initial_lookback_hours' => 24,
        'consecutive_failures' => 0,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'cursor_at' => 'datetime',
            'next_pull_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_received_at' => 'datetime',
        ];
    }

    public static function newPublicKey(): string
    {
        return Str::random(40);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return parent::getActivitylogOptions()->logExcept([
            'updated_at', 'updated_by', 'public_key', 'cursor_at', 'next_pull_at', 'last_attempt_at', 'last_success_at',
            'last_received_at', 'consecutive_failures', 'failure_kind', 'last_error',
        ]);
    }

    protected function auditLogName(): string
    {
        return 'integrations';
    }

    public function pulls(): bool
    {
        return in_array($this->mode, ['pull', 'both'], true);
    }

    public function receivesPushes(): bool
    {
        return in_array($this->mode, ['webhook', 'both'], true);
    }

    /** Has someone told it where leads go and as whom? Without that nothing can be entered, so it cannot be switched on. */
    public function isSetUp(): bool
    {
        return $this->default_location_id !== null && $this->actor_user_id !== null;
    }

    public function status(): ConnectionStatus
    {
        if (! $this->enabled) {
            return ConnectionStatus::Disabled;
        }

        if (! $this->isSetUp()) {
            return ConnectionStatus::NeedsSetup;
        }

        if ($this->consecutive_failures > 0) {
            return ConnectionStatus::Degraded;
        }

        $heard = collect([$this->last_success_at, $this->last_received_at])->filter()->max();

        if ($heard === null) {
            return ConnectionStatus::Configured;
        }

        // a connection that only pulls and has not managed to for a good while is not "connected" any more
        if ($this->mode === 'pull' && $this->last_success_at !== null && $this->last_success_at->lt(now()->subMinutes(3 * $this->pull_interval_minutes + 5))) {
            return ConnectionStatus::Degraded;
        }

        return ConnectionStatus::Connected;
    }

    /** The address the provider is asked to send leads to. */
    public function webhookUrl(): string
    {
        return url("/api/webhooks/{$this->provider}/{$this->public_key}");
    }

    /** @return BelongsTo<Location, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'default_location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return HasMany<LeadApiLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(LeadApiLog::class);
    }
}
