<?php

namespace App\Modules\Shared\Support;

use Closure;
use Spatie\Activitylog\Contracts\LoggablePipe;
use Spatie\Activitylog\EventLogBag;

/**
 * Keeps audit diffs honest.
 *
 * A model instance that was created (or selected with a column subset) in the same request does
 * not know the database defaults of columns it never loaded, so the logger would report e.g.
 * `total: null -> 0.00` on an unrelated update. This pipe drops an attribute from an "updated"
 * entry when the instance never had it AND it was not part of this save, leaving real changes intact.
 */
final class PruneUnloadedAttributes implements LoggablePipe
{
    /** Register once per model hierarchy (the pipe list is a static property shared with subclasses). */
    public static function register(string $modelClass): void
    {
        foreach ($modelClass::$changesPipes as $pipe) {
            if ($pipe instanceof self) {
                return;
            }
        }

        $modelClass::addLogChange(new self);
    }

    public function handle(EventLogBag $event, Closure $next): EventLogBag
    {
        if ($event->event === 'updated' && isset($event->changes['attributes'], $event->changes['old'])) {
            $original = $event->model->getRawOriginal();
            $saved = $event->model->getChanges();

            foreach (array_keys($event->changes['attributes']) as $key) {
                if (! array_key_exists($key, $original) && ! array_key_exists($key, $saved)) {
                    unset($event->changes['attributes'][$key], $event->changes['old'][$key]);
                }
            }
        }

        return $next($event);
    }
}
