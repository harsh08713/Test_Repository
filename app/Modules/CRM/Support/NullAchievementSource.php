<?php

namespace App\Modules\CRM\Support;

use App\Modules\CRM\Contracts\AchievementSource;

/** The placeholder until Sales Orders exist (Phase 12): nothing has been achieved, and it says so. */
final class NullAchievementSource implements AchievementSource
{
    public function achieved(int $userId, string $from, string $to, ?int $itemGroupId, ?int $locationId): array
    {
        return ['value' => '0.00', 'quantity' => '0.000'];
    }

    public function isLive(): bool
    {
        return false;
    }
}
