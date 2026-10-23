<?php

namespace App\Modules\Workspace\Services;

use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Workspace\Models\Holiday;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Adds minutes of waiting to a moment. Plain elapsed minutes, or — for a policy counted in business hours — only the minutes inside the
 * company's working day (`sla.business_days`, `sla.business_start`, `sla.business_end`), skipping its holidays: a lead made at 6:00 pm on a
 * Saturday with a 4-hour policy falls due at 1:30 pm on Monday. Nothing is saved here.
 */
final class BusinessClock
{
    /** More days than this without an open one means the settings leave no working time at all. */
    private const HORIZON_DAYS = 800;

    public function __construct(private readonly SettingsService $settings) {}

    public function after(CarbonInterface $from, int $minutes, bool $businessHours, int $companyId): Carbon
    {
        $from = Carbon::instance($from);

        if (! $businessHours) {
            return $from->copy()->addMinutes($minutes);
        }

        [$startMinute, $endMinute] = [$this->minuteOfDay((string) $this->settings->get('sla.business_start', $companyId)), $this->minuteOfDay((string) $this->settings->get('sla.business_end', $companyId))];

        if ($endMinute <= $startMinute) {
            return $from->copy()->addMinutes($minutes);   // a working day that never opens: count plain minutes rather than loop for ever
        }

        $weekdays = match ((string) $this->settings->get('sla.business_days', $companyId)) {
            'mon_fri' => [1, 2, 3, 4, 5],
            'all' => [1, 2, 3, 4, 5, 6, 7],
            default => [1, 2, 3, 4, 5, 6],
        };

        $closed = $this->holidays($companyId, $from->copy()->startOfDay(), self::HORIZON_DAYS);
        $cursor = $from->copy();
        $left = max(0, $minutes);

        for ($i = 0; $i <= self::HORIZON_DAYS; $i++) {
            $day = $cursor->copy()->startOfDay();

            if (in_array($day->isoWeekday(), $weekdays, true) && ! isset($closed[$day->toDateString()])) {
                $opens = $day->copy()->addMinutes($startMinute);
                $closes = $day->copy()->addMinutes($endMinute);

                if ($cursor->lt($opens)) {
                    $cursor = $opens->copy();
                }

                if ($cursor->lt($closes)) {
                    $room = intdiv($closes->getTimestamp() - $cursor->getTimestamp(), 60);

                    if ($left <= $room) {
                        return $cursor->copy()->addMinutes($left);
                    }

                    $left -= $room;
                }
            }

            $cursor = $day->copy()->addDay();
        }

        return $from->copy()->addMinutes($minutes);
    }

    /** @return array<string, true> the closed days (Y-m-d) from $from for $days days */
    private function holidays(int $companyId, Carbon $from, int $days): array
    {
        $closed = [];

        foreach (Holiday::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)
            ->whereBetween('holiday_date', [$from->toDateString(), $from->copy()->addDays($days)->toDateString()])->pluck('holiday_date') as $day) {
            $closed[Carbon::parse($day)->toDateString()] = true;
        }

        return $closed;
    }

    private function minuteOfDay(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm.':0'));

        return $h * 60 + $m;
    }
}
