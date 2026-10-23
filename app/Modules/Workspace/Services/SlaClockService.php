<?php

namespace App\Modules\Workspace\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Workspace\Models\SlaClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Pausing and resuming a running clock — for the time a thing is waiting on the customer rather than on us. A paused clock cannot run out; when
 * it is resumed its deadline moves on by the time it was paused (plain elapsed time, even for a clock counted in business hours). Only a clock
 * that is running can be paused, and one that has already run out is past saving: it is answered, not paused. Every pause and resume is audited.
 */
final class SlaClockService
{
    /**
     * @throws FieldRuleException
     * @throws ValidationException
     */
    public function pause(SlaClock $clock, string $reason, User $actor): SlaClock
    {
        $reason = trim($reason);

        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:200']], ['reason.required' => 'Say what the clock is waiting for.'])->validate();

        return DB::transaction(function () use ($clock, $reason, $actor) {
            $fresh = SlaClock::query()->whereKey($clock->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->state !== 'running') {
                throw new FieldRuleException('This clock has already stopped.');
            }

            if ($fresh->paused_at !== null) {
                throw new FieldRuleException('This clock is already paused.');
            }

            if ($fresh->breached_at !== null) {
                throw new FieldRuleException('This clock has already run out; it cannot be paused.');
            }

            $fresh->forceFill(['paused_at' => now(), 'pause_reason' => $reason])->save();
            activity('sla')->causedBy($actor)->performedOn($fresh)->event('paused')->withProperties(['reason' => $reason])->log('SLA clock paused');

            return $fresh->refresh();
        });
    }

    /** @throws FieldRuleException */
    public function resume(SlaClock $clock, User $actor): SlaClock
    {
        return DB::transaction(function () use ($clock, $actor) {
            $fresh = SlaClock::query()->whereKey($clock->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->state !== 'running' || $fresh->paused_at === null) {
                throw new FieldRuleException('This clock is not paused.');
            }

            $minutes = (int) ceil(max(0, now()->getTimestamp() - $fresh->paused_at->getTimestamp()) / 60);

            $fresh->forceFill([
                'due_at' => $fresh->due_at->copy()->addMinutes($minutes), 'paused_minutes' => $fresh->paused_minutes + $minutes, 'paused_at' => null, 'pause_reason' => null,
            ])->save();

            activity('sla')->causedBy($actor)->performedOn($fresh)->event('resumed')->withProperties(['paused_minutes' => $minutes])->log('SLA clock resumed');

            return $fresh->refresh();
        });
    }
}
