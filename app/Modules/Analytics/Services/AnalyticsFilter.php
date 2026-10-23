<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Shared\Support\FinancialYear;
use Carbon\CarbonImmutable;

/**
 * What every dashboard and analytics page is looking at: a date range, and optionally one industry, one branch and (when drilling into losses) one loss
 * category. It lives in the query string, so it survives a drill-down, a reload and a shared link — and it is **always re-read and re-checked here**:
 * a date that is not a date, a range the wrong way round, or one longer than five years falls back to the financial year, and an id is only ever an integer.
 * Whether the person may see that industry or branch is not decided here: every query runs through the tenant and branch scopes.
 */
final class AnalyticsFilter
{
    public const PRESETS = [
        'this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'this_year' => 'This financial year',
        'last_year' => 'Last financial year', 'last_12_months' => 'Last 12 months', 'custom' => 'Custom range',
    ];

    private const MAX_DAYS = 1830;

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $range = 'this_year',
        public readonly ?int $industryId = null,
        public readonly ?int $locationId = null,
        public readonly ?int $categoryId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $query  range, from, to, industry, branch, category
     */
    public static function fromArray(array $query, ?CarbonImmutable $today = null): self
    {
        $today ??= CarbonImmutable::now()->startOfDay();
        $int = fn (string $k) => isset($query[$k]) && is_scalar($query[$k]) && ctype_digit((string) $query[$k]) && (int) $query[$k] > 0 ? (int) $query[$k] : null;
        $range = isset($query['range']) && is_string($query['range']) && array_key_exists($query['range'], self::PRESETS) ? $query['range'] : 'this_year';

        if ($range === 'custom') {
            $from = self::date($query['from'] ?? null);
            $to = self::date($query['to'] ?? null);

            if ($from === null || $to === null || $to->lt($from) || $from->diffInDays($to) > self::MAX_DAYS) {
                [$from, $to] = self::preset('this_year', $today);
                $range = 'this_year';
            }
        } else {
            [$from, $to] = self::preset($range, $today);
        }

        return new self($from, $to, $range, $int('industry'), $int('branch'), $int('category'));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function preset(string $range, CarbonImmutable $today): array
    {
        return match ($range) {
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'this_quarter' => [self::quarterStart($today), $today],
            'last_year' => self::lastYear($today),
            'last_12_months' => [$today->subMonthsNoOverflow(12)->addDay(), $today],
            default => [CarbonImmutable::instance(FinancialYear::range($today)[0])->startOfDay(), $today],
        };
    }

    /** The period of the same length that ends the day before this one starts — what a figure is compared with. */
    public function previous(): self
    {
        $days = (int) $this->from->diffInDays($this->to) + 1;
        $to = $this->from->subDay();

        return new self($to->subDays($days - 1), $to, 'custom', $this->industryId, $this->locationId, $this->categoryId);
    }

    /** day, week or month: fine enough to show a shape, coarse enough to read. */
    public function granularity(?string $chosen = null): string
    {
        if (in_array($chosen, ['day', 'week', 'month'], true)) {
            return $chosen;
        }

        $days = (int) $this->from->diffInDays($this->to) + 1;

        return $days <= 45 ? 'day' : ($days <= 183 ? 'week' : 'month');
    }

    /**
     * The query-string form: put it on every link so a drill-down keeps the filters.
     *
     * @param  array<string, string|int>  $extra
     * @return array<string, string|int>
     */
    public function toQuery(array $extra = []): array
    {
        $query = ['range' => $this->range];

        if ($this->range === 'custom') {
            $query['from'] = $this->from->toDateString();
            $query['to'] = $this->to->toDateString();
        }

        foreach (['industry' => $this->industryId, 'branch' => $this->locationId, 'category' => $this->categoryId] as $k => $v) {
            if ($v !== null) {
                $query[$k] = $v;
            }
        }

        return [...$query, ...$extra];
    }

    public function label(): string
    {
        return $this->from->format('d M Y').' – '.$this->to->format('d M Y');
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        $parsed = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? CarbonImmutable::instance($parsed) : null;
    }

    private static function quarterStart(CarbonImmutable $today): CarbonImmutable
    {
        $fy = CarbonImmutable::instance(FinancialYear::range($today)[0])->startOfDay();
        $quarters = (int) floor($fy->diffInMonths($today) / 3);

        return $fy->addMonthsNoOverflow($quarters * 3);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function lastYear(CarbonImmutable $today): array
    {
        $current = CarbonImmutable::instance(FinancialYear::range($today)[0])->startOfDay();

        return [$current->subYear(), $current->subDay()];
    }
}
