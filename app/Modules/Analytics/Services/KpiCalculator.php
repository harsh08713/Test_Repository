<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Finance\Services\Profitability;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\KpiDefinition;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Inventory\Services\StockQuery;
use App\Modules\Loss\Models\CorrectiveAction;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Shared\Services\SettingsService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Works out an industry KPI (FORMULAS.md §5: value = numerator ÷ denominator × multiplier) **from data the system actually holds**. A KPI whose formula
 * key has no source yet — OEE, yield, first-pass yield and the like need production data this application does not capture — is **not measured**: `compute()`
 * says so (null) and the screen shows "Not measured yet", never a made-up number.
 *
 * Measured today:
 *   expiry_exposure   value of the template's stock expiring within the warning window (or already expired)
 *   capa_overdue      open corrective actions past their due date, on the template's incidents
 *   open_count        the template's loss incidents that are not in a final state
 *   gross_margin_pct  gross profit ÷ net revenue × 100 of the items assigned to the template's industry, for the period
 *   shrinkage_pct     validated actual *shrinkage* loss of the template's incidents ÷ the industry's net revenue × 100, for the period
 *
 * The value of a ratio whose denominator is 0 is null ("—"). Everything runs through the tenant and branch scopes of whoever asks; the scheduled
 * snapshot job asks with no signed-in person, so it sees the whole company.
 */
final class KpiCalculator
{
    public const SUPPORTED = ['expiry_exposure', 'capa_overdue', 'open_count', 'gross_margin_pct', 'shrinkage_pct'];

    public function __construct(
        private readonly Profitability $profit,
        private readonly StockQuery $stock,
        private readonly SettingsService $settings,
        private readonly IndustryTemplateService $templates,
    ) {}

    public function supports(KpiDefinition $kpi): bool
    {
        return in_array($kpi->formula_key, self::SUPPORTED, true);
    }

    /**
     * @return array{value: string|null, numerator: string|null, denominator: string|null}|null null = this KPI is not measured yet
     */
    public function compute(KpiDefinition $kpi, IndustryTemplate $template, CarbonInterface $from, CarbonInterface $to, ?int $locationId = null): ?array
    {
        return match ($kpi->formula_key) {
            'expiry_exposure' => $this->plain($this->stock->expiryExposure($this->warningDays(), $locationId, $to, $template->id)),
            'capa_overdue' => $this->plain((string) CorrectiveAction::query()->whereIn('state', ['open', 'in_progress'])->where('due_date', '<', $to->toDateString())
                ->whereIn('loss_incident_id', LossIncident::query()->where('industry_template_id', $template->id)->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))->select('id'))->count()),
            'open_count' => $this->plain((string) LossIncident::query()->where('industry_template_id', $template->id)->whereNull('primary_incident_id')
                ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))->whereNotIn('state', $this->finalStates($template, $kpi->version))->count()),
            'gross_margin_pct' => $this->margin($template, $from, $to, $locationId, false),
            'shrinkage_pct' => $this->margin($template, $from, $to, $locationId, true),
            default => null,
        };
    }

    /** @return array{value: string, numerator: null, denominator: null} */
    private function plain(string $value): array
    {
        return ['value' => bcadd($value, '0', 4), 'numerator' => null, 'denominator' => null];
    }

    /** @return array{value: string|null, numerator: string, denominator: string} */
    private function margin(IndustryTemplate $template, CarbonInterface $from, CarbonInterface $to, ?int $locationId, bool $shrinkage): array
    {
        $row = collect($this->profit->by('industry', $from, $to, $template->id, $locationId))->firstWhere('key', $template->id);
        $net = $row['net_revenue'] ?? '0.00';

        $numerator = $shrinkage
            ? bcadd((string) DB::table('loss_impact_lines as l')->join('loss_incidents as i', 'i.id', '=', 'l.loss_incident_id')->whereIn('l.loss_incident_id',
                LossIncident::query()->where('industry_template_id', $template->id)->whereNull('primary_incident_id')->where('state', '<>', 'rejected')
                    ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))->select('id'))
                ->where('l.impact_type', 'actual')->where('l.validation_state', 'validated')->whereNull('l.superseded_at')->where('l.formula_key', 'shrinkage')->sum('l.amount'), '0', 2)
            : ($row['gross_profit'] ?? '0.00');

        return [
            'value' => bccomp($net, '0', 2) === 0 ? null : bcadd(bcmul(bcdiv($numerator, $net, 10), '100', 8), '0.00005', 4),
            'numerator' => bcadd($numerator, '0', 4), 'denominator' => bcadd($net, '0', 4),
        ];
    }

    private function warningDays(): int
    {
        $days = (int) $this->settings->get('inventory.expiry_warning_days');

        return $days > 0 ? $days : 30;
    }

    /** @return list<string> the states of the template's workflow (at the KPI's version) that mean "finished" */
    private function finalStates(IndustryTemplate $template, int $version): array
    {
        return $this->templates->workflow($template, $version)['states']->where('is_final', true)->pluck('code')->all() ?: ['closed', 'rejected'];
    }
}
