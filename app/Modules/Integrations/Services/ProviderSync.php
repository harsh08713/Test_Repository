<?php

namespace App\Modules\Integrations\Services;

use App\Modules\Integrations\Exceptions\ProviderException;
use App\Modules\Integrations\Models\IntegrationConnection;
use App\Modules\Integrations\Providers\LeadProvider;
use App\Modules\Integrations\Providers\ProviderRegistry;
use App\Modules\Integrations\Support\PayloadRedactor;
use App\Modules\Integrations\Support\SyncResult;
use App\Modules\Shared\Services\SettingsService;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Fetches leads from a provider that has a pull API, within the provider's rules, and hands each one to the log.
 *
 *  - **A watermark.** `cursor_at` is the end of the last period fetched in full; the next call starts a little *before* it
 *    (an overlap, so a lead the provider published late is not missed) and the duplicates that causes cost nothing: the log is
 *    unique per provider lead id. The cursor moves only after every record of the period has been written down.
 *  - **The provider's limits.** Never two calls closer than its minimum gap (claimed with one conditional UPDATE, so two workers
 *    cannot both go), never a period longer than it serves, never a request it has told us it will not take: a wrong or expired
 *    key waits for a person; "too often" waits the time it asked for; trouble on its side backs off, 15 minutes doubling to four hours.
 *  - **Nothing secret in what is stored.** The error kept on the connection is scrubbed of the key, which is in the address of
 *    the call.
 *
 * It decides nothing about leads — that is LeadIntake's, for every provider alike.
 */
final class ProviderSync
{
    /** Each pull starts this far before the last one ended, to catch what the provider published late. */
    public const OVERLAP_MINUTES = 10;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly SettingsService $settings,
        private readonly LeadApiLogStore $store,
    ) {}

    public function pull(IntegrationConnection $connection): SyncResult
    {
        $provider = $this->providers->get($connection->provider);

        if (! $connection->enabled || ! $connection->pulls() || ! $provider->canPull()) {
            return SyncResult::skipped('This connection does not pull.');
        }

        $now = CarbonImmutable::now();

        if (! $this->claimAttempt($connection, $provider, $now)) {
            return SyncResult::tooSoon("{$provider->label()} allows one call every {$provider->minPullGapMinutes()} minutes. Try again shortly.");
        }

        try {
            $key = $this->key($connection);
            [$from, $to] = $this->window($connection, $provider, $now);

            $records = $provider->fetch($key, $from, $to);

            $new = 0;
            $again = 0;
            foreach ($records as $record) {
                [$log, $first] = $this->store->record($connection, $provider, $record, 'pull');

                if ($first) {
                    $new++;
                    $this->store->dispatch($log);
                } else {
                    $again++;
                }
            }
        } catch (ProviderException $e) {
            $this->fail($connection, $e);

            return SyncResult::failed($e->kind, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->fail($connection, ProviderException::unavailable('The leads could not be saved just now. It will be tried again.'));

            return SyncResult::failed('unavailable', 'The leads could not be saved just now. It will be tried again.');
        }

        $caughtUp = $to->gte($now);

        IntegrationConnection::query()->whereKey($connection->getKey())->update([
            'cursor_at' => $to, 'last_success_at' => $now, 'consecutive_failures' => 0, 'failure_kind' => null, 'last_error' => null,
            // still catching up on a long gap: come back as soon as the provider allows
            'next_pull_at' => $now->addMinutes($caughtUp ? max($connection->pull_interval_minutes, $provider->minPullGapMinutes()) : max(1, $provider->minPullGapMinutes())),
        ]);

        if ($new > 0) {
            activity('integrations')->performedOn($connection)->event('pulled')
                ->withProperties(['provider' => $provider->key(), 'fetched' => count($records), 'new' => $new, 'already_known' => $again, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String()])
                ->log('pulled');
        }

        return SyncResult::ok(count($records), $new, $again);
    }

    /**
     * Ask the provider for the last hour's leads and say what came back — saving nothing and moving nothing — to prove the key
     * and the address work. It is a real call, so the provider's minimum gap applies.
     */
    public function test(IntegrationConnection $connection): SyncResult
    {
        $provider = $this->providers->get($connection->provider);

        if (! $provider->canPull()) {
            return SyncResult::skipped("{$provider->label()} sends its leads to us; there is nothing to test by calling it. Send a test lead to the webhook address instead.");
        }

        $now = CarbonImmutable::now();

        if (! $this->claimAttempt($connection, $provider, $now)) {
            return SyncResult::tooSoon("{$provider->label()} allows one call every {$provider->minPullGapMinutes()} minutes. Try again shortly.");
        }

        try {
            $records = $provider->fetch($this->key($connection), $now->subHour(), $now);
        } catch (ProviderException $e) {
            return SyncResult::failed($e->kind, $e->getMessage());
        }

        return SyncResult::ok(count($records), 0, 0);
    }

    /** The first call is allowed whenever nothing was attempted within the provider's gap; one UPDATE decides, so two workers cannot both go. */
    private function claimAttempt(IntegrationConnection $connection, LeadProvider $provider, CarbonImmutable $now): bool
    {
        $cutoff = $now->subMinutes($provider->minPullGapMinutes());

        return IntegrationConnection::query()->whereKey($connection->getKey())
            ->where(fn ($q) => $q->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<=', $cutoff))
            ->update(['last_attempt_at' => $now]) === 1;
    }

    private function key(IntegrationConnection $connection): string
    {
        $key = $this->settings->get("integrations.{$connection->provider}_api_key", (int) $connection->company_id);

        if (! is_string($key) || $key === '') {
            throw ProviderException::auth('No API key is saved for this provider. Add it under Administration → Company settings → Integrations.');
        }

        return $key;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function window(IntegrationConnection $connection, LeadProvider $provider, CarbonImmutable $now): array
    {
        $from = $connection->cursor_at === null
            ? $now->subHours($connection->initial_lookback_hours)
            : CarbonImmutable::instance($connection->cursor_at)->subMinutes(self::OVERLAP_MINUTES);

        // a connection that was off for a very long time asks for no more than the provider keeps
        $from = $from->max($now->subDays(360));
        $to = $from->addDays($provider->maxWindowDays())->min($now);

        return [$from, $to];
    }

    private function fail(IntegrationConnection $connection, ProviderException $e): void
    {
        $failures = $connection->consecutive_failures + 1;
        $wait = match ($e->kind) {
            'unavailable' => min(15 * (2 ** ($failures - 1)), 240),
            default => $e->retryAfterMinutes,
        };

        IntegrationConnection::query()->whereKey($connection->getKey())->update([
            'consecutive_failures' => $failures,
            'failure_kind' => $e->kind,
            'last_error' => mb_substr(PayloadRedactor::text($e->getMessage()), 0, 1000),
            'next_pull_at' => CarbonImmutable::now()->addMinutes($wait),
        ]);
    }
}
