<?php

namespace App\Modules\Shared\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "I ran just now" notes left by the things that must run on their own — the scheduler itself and the jobs it starts — so the health page (and the
 * administrator's dashboard) can say when one has gone quiet. Kept in the application cache; production needs a cache every process shares
 * (`database` or `redis`), as the scheduler and the web server are different processes.
 */
final class Heartbeat
{
    public const SCHEDULER = 'scheduler';

    public function beat(string $name): void
    {
        try {
            Cache::forever("heartbeat:{$name}", now()->getTimestamp());
        } catch (Throwable) {
            // a cache that cannot be written must not stop the job that is reporting in
        }
    }

    public function last(string $name): ?Carbon
    {
        try {
            $at = Cache::get("heartbeat:{$name}");
        } catch (Throwable) {
            return null;
        }

        return is_numeric($at) ? Carbon::createFromTimestamp((int) $at) : null;
    }

    /** Has it reported in within the last $minutes? Null when it never has (we cannot say it stopped). */
    public function isFresh(string $name, int $minutes): ?bool
    {
        $at = $this->last($name);

        return $at === null ? null : $at->gte(now()->subMinutes($minutes));
    }
}
