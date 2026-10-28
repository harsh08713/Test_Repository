<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Support\DealForecast;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * An advisory chance of winning an open deal, from the company's own history (brief 6.2: "forecast deal probability only as an advisory estimate"). **No AI**: it counts.
 *
 * For each open stage — Discovery, Negotiation, Quotation — it looks at the company's *closed* deals (won or lost) that ever reached that stage, and how many of them were won:
 *
 *     chance = (won + weight × usual) ÷ (deals + weight)
 *
 * where *usual* is the stage's usual chance (the probability a deal starts with there) and *weight* is how many deals' worth of belief that usual figure is given (Company settings →
 * Deal forecast, default 10). With no history the estimate **is** the usual figure; each closed deal moves it towards what really happened, so a few lucky deals cannot swing it. It is kept
 * between 1 and 99 per cent, like a deal's own probability. `confidence` says how many deals it rests on (thin below the "fair" count, good from the "good" count) — the page shows it.
 *
 * It is advice: it reads, it never writes. The deal's own probability, the pipeline totals and the dashboards still use the figure a person set.
 */
final class DealForecaster
{
    /** The stages a deal can be in while it is still open. */
    private const OPEN = [DealStage::Discovery, DealStage::Negotiation, DealStage::Quotation];

    public function __construct(private readonly SettingsService $settings) {}

    public function enabled(int $companyId): bool
    {
        return (bool) $this->settings->get('forecast.enabled', $companyId);
    }

    /**
     * How the company's closed deals ended, by the open stage they ever reached.
     *
     * @return array<string, array{n: int, won: int}>
     */
    public function history(int $companyId): array
    {
        $rows = DB::table('deal_stage_history as entry')
            ->join('deals as deal', 'deal.id', '=', 'entry.deal_id')
            ->where('entry.company_id', $companyId)
            ->where('deal.company_id', $companyId)
            ->whereNull('deal.deleted_at')
            ->whereIn('deal.stage', [DealStage::Won->value, DealStage::Lost->value])
            ->whereIn('entry.to_stage', array_map(fn (DealStage $stage) => $stage->value, self::OPEN))
            ->groupBy('entry.to_stage')
            ->selectRaw("entry.to_stage as stage, count(distinct deal.id) as n, count(distinct case when deal.stage = 'won' then deal.id end) as won")
            ->get();

        $history = [];
        foreach ($rows as $row) {
            $history[(string) $row->stage] = ['n' => (int) $row->n, 'won' => (int) $row->won];
        }

        return $history;
    }

    /**
     * @param  array<string, array{n: int, won: int}>|null  $history  worked out once by the caller when several deals are forecast together
     */
    public function forecast(Deal $deal, ?array $history = null): ?DealForecast
    {
        $stage = $deal->stage;
        $companyId = (int) $deal->company_id;

        if (! in_array($stage, self::OPEN, true) || ! $this->enabled($companyId)) {
            return null;
        }

        $seen = ($history ?? $this->history($companyId))[$stage->value] ?? ['n' => 0, 'won' => 0];
        $weight = max(1, (int) $this->settings->get('forecast.prior_weight', $companyId));
        $prior = $stage->defaultProbability();
        $percent = self::estimate($seen['won'], $seen['n'], $prior, $weight);

        $good = max(1, (int) $this->settings->get('forecast.good_from', $companyId));
        $fair = min($good, max(1, (int) $this->settings->get('forecast.fair_from', $companyId)));
        $confidence = $seen['n'] >= $good ? DealForecast::GOOD : ($seen['n'] >= $fair ? DealForecast::FAIR : DealForecast::THIN);

        return new DealForecast(
            $stage, $percent, $seen['n'], $seen['won'], $prior, $confidence,
            bcdiv(bcmul((string) $deal->expected_value, (string) $percent, 4), '100', 2),
        );
    }

    /**
     * The estimate itself, in whole per cent between 1 and 99. Pure, so it can be checked by hand.
     */
    public static function estimate(int $won, int $deals, int $priorPercent, int $weight): int
    {
        $weight = max(1, $weight);
        $deals = max(0, $deals);
        $won = max(0, min($won, $deals));

        $chance = ($won + $weight * $priorPercent / 100) / ($deals + $weight);

        return max(1, min(99, (int) round(100 * $chance)));
    }
}
