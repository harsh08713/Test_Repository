<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Industry\Models\CompanyIndustryTemplate;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\KpiDefinition;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Takes the day's snapshot of every industry KPI that can be measured, for every company, for every template it has switched on — at the version the
 * company is pinned to. Month-to-date for the ratios, the day itself for the point-in-time counts. **Idempotent**: running it twice for a day rewrites the
 * same rows (`unique(company, location, KPI, period type, period start)`), so the scheduler, a retry and a person pressing the button cannot double anything.
 * A KPI with no data source yet is skipped, not stored as zero.
 */
final class KpiSnapshotService
{
    public function __construct(private readonly KpiCalculator $calculator, private readonly TenantContext $tenant) {}

    /** @return int how many snapshots were written or refreshed */
    public function run(?CarbonInterface $day = null): int
    {
        $day = ($day ?? now())->copy()->startOfDay();
        $written = 0;

        foreach (Company::withoutGlobalScopes(Tenancy::SCOPES)->pluck('id') as $companyId) {
            $written += $this->tenant->withCompany((int) $companyId, fn () => $this->forCompany((int) $companyId, $day));
        }

        return $written;
    }

    private function forCompany(int $companyId, CarbonInterface $day): int
    {
        $written = 0;
        $month = $day->copy()->startOfMonth();

        foreach (CompanyIndustryTemplate::query()->get() as $enabled) {
            $template = IndustryTemplate::query()->find($enabled->industry_template_id);

            if ($template === null) {
                continue;
            }

            foreach (KpiDefinition::query()->where('industry_template_id', $template->id)->where('version', $enabled->version_pinned)->where('is_active', true)->get() as $kpi) {
                if (! $this->calculator->supports($kpi) || ($result = $this->calculator->compute($kpi, $template, $month, $day)) === null) {
                    continue;
                }

                DB::table('kpi_snapshots')->upsert([[
                    'company_id' => $companyId, 'location_key' => 0, 'industry_template_id' => $template->id, 'kpi_definition_id' => $kpi->id, 'kpi_version' => $kpi->version,
                    'period_type' => 'day', 'period_start' => $day->toDateString(), 'value' => $result['value'], 'numerator' => $result['numerator'], 'denominator' => $result['denominator'],
                    'source_freshness_at' => now(), 'computed_at' => now(),
                ]], ['company_id', 'location_key', 'kpi_definition_id', 'period_type', 'period_start'], ['kpi_version', 'value', 'numerator', 'denominator', 'source_freshness_at', 'computed_at']);

                $written++;
            }
        }

        return $written;
    }
}
