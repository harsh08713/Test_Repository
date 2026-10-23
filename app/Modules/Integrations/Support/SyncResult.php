<?php

namespace App\Modules\Integrations\Support;

/** What one pull (or one test call) came to, in words a person can be shown. */
final readonly class SyncResult
{
    private function __construct(
        public string $outcome,          // ok | failed | too_soon | skipped
        public string $message,
        public int $fetched = 0,
        public int $created = 0,         // deliveries not seen before
        public int $alreadyKnown = 0,
        public ?string $kind = null,     // for a failure: auth | rate_limited | invalid | unavailable
    ) {}

    public static function ok(int $fetched, int $new, int $alreadyKnown): self
    {
        $message = $fetched === 0
            ? 'Connected. No new leads in that period.'
            : "Connected. {$fetched} lead".($fetched === 1 ? '' : 's').' came back'.($new > 0 || $alreadyKnown > 0 ? " ({$new} new, {$alreadyKnown} already known)" : '').'.';

        return new self('ok', $message, $fetched, $new, $alreadyKnown);
    }

    public static function failed(string $kind, string $message): self
    {
        return new self('failed', $message, kind: $kind);
    }

    public static function tooSoon(string $message): self
    {
        return new self('too_soon', $message);
    }

    public static function skipped(string $message): self
    {
        return new self('skipped', $message);
    }

    public function succeeded(): bool
    {
        return $this->outcome === 'ok';
    }
}
