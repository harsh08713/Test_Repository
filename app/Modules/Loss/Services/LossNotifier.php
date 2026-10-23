<?php

namespace App\Modules\Loss\Services;

use App\Models\User;
use App\Modules\Loss\Notifications\LossAlertNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the people of one company who hold a permission about something in the loss area. A notice is a courtesy: if it cannot be sent the work that
 * caused it stays done (the failure is logged), and nobody is told twice because every caller sends only at the moment of a claimed, one-time change.
 */
final class LossNotifier
{
    /**
     * @param  list<int>  $except  people who must not be told (the person who did it)
     * @return int how many people were told
     */
    public function tell(string $permission, int $companyId, LossAlertNotification $note, array $except = []): int
    {
        $told = 0;

        try {
            $people = User::query()->permission($permission)->where('is_active', true)->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId))
                ->when($except !== [], fn ($q) => $q->whereNotIn('users.id', $except))->get();

            foreach ($people as $user) {
                $user->notify($note);
                $told++;
            }
        } catch (Throwable $e) {
            Log::warning('A loss notice could not be sent.', ['kind' => $note->kind, 'permission' => $permission, 'error' => $e::class]);
        }

        return $told;
    }

    /**
     * @param  list<int|null>  $userIds
     * @return int how many people were told
     */
    public function tellPeople(array $userIds, LossAlertNotification $note): int
    {
        $told = 0;

        try {
            foreach (User::query()->whereIn('id', array_unique(array_filter($userIds)))->where('is_active', true)->get() as $user) {
                $user->notify($note);
                $told++;
            }
        } catch (Throwable $e) {
            Log::warning('A loss notice could not be sent.', ['kind' => $note->kind, 'error' => $e::class]);
        }

        return $told;
    }
}
