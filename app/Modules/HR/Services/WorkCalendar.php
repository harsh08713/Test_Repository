<?php

namespace App\Modules\HR\Services;

use App\Modules\Shared\Services\SettingsService;
use App\Modules\Workspace\Models\Holiday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Which days are working days: the company's holidays (the one `holidays` table the SLA clocks also use) and its weekly off (company setting `hr.weekly_off`).
 * Leave counts only working days; the attendance day-close marks the others as holiday or weekly off.
 */
final class WorkCalendar
{
    public const WEEKLY_OFFS = [
        'sunday' => 'Sunday',
        'saturday_sunday' => 'Saturday and Sunday',
        'alternate_saturday' => 'Sunday and the 2nd and 4th Saturday',
        'none' => 'No weekly off',
    ];

    public function __construct(private readonly SettingsService $settings) {}

    /** @return array<string, string> Y-m-d => holiday name, for the days from $from to $to inclusive */
    public function holidays(CarbonInterface $from, CarbonInterface $to): array
    {
        return Holiday::query()->whereBetween('holiday_date', [$from->toDateString(), $to->toDateString()])->orderBy('holiday_date')->get()
            ->mapWithKeys(fn (Holiday $h) => [$h->holiday_date->toDateString() => $h->name])->all();
    }

    public function isWeeklyOff(CarbonInterface $day): bool
    {
        $rule = (string) $this->settings->get('hr.weekly_off');
        $weekday = (int) $day->dayOfWeek;   // 0 = Sunday … 6 = Saturday

        return match ($rule) {
            'none' => false,
            'saturday_sunday' => in_array($weekday, [0, 6], true),
            'alternate_saturday' => $weekday === 0 || ($weekday === 6 && in_array(intdiv($day->day - 1, 7) + 1, [2, 4], true)),
            default => $weekday === 0,
        };
    }

    /**
     * The working days from $from to $to inclusive, as Y-m-d strings.
     *
     * @param  array<string, string>|null  $holidays  what `holidays()` gave for the same range, to save a query
     * @return list<string>
     */
    public function workingDays(CarbonInterface $from, CarbonInterface $to, ?array $holidays = null): array
    {
        $holidays ??= $this->holidays($from, $to);
        $days = [];

        for ($day = CarbonImmutable::instance($from)->startOfDay(); $day->lte($to); $day = $day->addDay()) {
            if (! isset($holidays[$day->toDateString()]) && ! $this->isWeeklyOff($day)) {
                $days[] = $day->toDateString();
            }
        }

        return $days;
    }
}
