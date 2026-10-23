<?php

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Modules\Reports\Reports\AssetDowntime;
use App\Modules\Reports\Reports\AuditActivity;
use App\Modules\Reports\Reports\BudgetAgainstActual;
use App\Modules\Reports\Reports\CampaignPerformance;
use App\Modules\Reports\Reports\CorrectiveActionRegister;
use App\Modules\Reports\Reports\DealPipeline;
use App\Modules\Reports\Reports\EmployeeRegister;
use App\Modules\Reports\Reports\ExpiringBatches;
use App\Modules\Reports\Reports\FollowupRegister;
use App\Modules\Reports\Reports\GeofenceLog;
use App\Modules\Reports\Reports\GoodsReceiptRegister;
use App\Modules\Reports\Reports\InspectionRegister;
use App\Modules\Reports\Reports\IntegrationSyncFailures;
use App\Modules\Reports\Reports\ItemList;
use App\Modules\Reports\Reports\LeadRegister;
use App\Modules\Reports\Reports\LeadSourceAnalysis;
use App\Modules\Reports\Reports\LeadTransferHistory;
use App\Modules\Reports\Reports\LeaveRegister;
use App\Modules\Reports\Reports\LossByIndustry;
use App\Modules\Reports\Reports\LossIncidentRegister;
use App\Modules\Reports\Reports\LossPareto;
use App\Modules\Reports\Reports\MaintenanceOrderRegister;
use App\Modules\Reports\Reports\NonConformanceRegister;
use App\Modules\Reports\Reports\OpenPurchaseOrderLines;
use App\Modules\Reports\Reports\OverdueTasks;
use App\Modules\Reports\Reports\PendingApprovals;
use App\Modules\Reports\Reports\PendingSalesOrders;
use App\Modules\Reports\Reports\ProductionOrderRegister;
use App\Modules\Reports\Reports\ProfitabilityBreakdown;
use App\Modules\Reports\Reports\QuotationRegister;
use App\Modules\Reports\Reports\RadarFindingRegister;
use App\Modules\Reports\Reports\RateList;
use App\Modules\Reports\Reports\ReorderList;
use App\Modules\Reports\Reports\Report;
use App\Modules\Reports\Reports\RouteAdherence;
use App\Modules\Reports\Reports\SalesOrderRegister;
use App\Modules\Reports\Reports\SalesReturnRegister;
use App\Modules\Reports\Reports\SavingsRegister;
use App\Modules\Reports\Reports\SlaCompliance;
use App\Modules\Reports\Reports\StockValuation;
use App\Modules\Reports\Reports\TargetVsAchievement;

/** The reports that exist, which of them a person may run, and the ones that wait for a later phase (said so, never faked). */
final class ReportCatalog
{
    /** @var array<string, Report>|null */
    private ?array $reports = null;

    /** @return array<string, Report> key => report */
    public function all(): array
    {
        if ($this->reports === null) {
            $this->reports = [];

            foreach ([
                new LeadRegister, new LeadSourceAnalysis, new FollowupRegister, new LeadTransferHistory,
                new DealPipeline, new QuotationRegister, new SalesOrderRegister, new PendingSalesOrders, new TargetVsAchievement,
                new RouteAdherence, new GeofenceLog,
                new ItemList, new RateList,
                new PendingApprovals, new IntegrationSyncFailures, new AuditActivity, new OverdueTasks, new SlaCompliance, new CampaignPerformance,
                new LossIncidentRegister, new LossPareto, new LossByIndustry, new CorrectiveActionRegister, new RadarFindingRegister, new SavingsRegister, new BudgetAgainstActual, new ProfitabilityBreakdown, new SalesReturnRegister,
                new EmployeeRegister, new LeaveRegister,
                new ExpiringBatches, new StockValuation, new OpenPurchaseOrderLines, new GoodsReceiptRegister, new ReorderList, new ProductionOrderRegister, new InspectionRegister, new NonConformanceRegister, new MaintenanceOrderRegister, new AssetDowntime,
            ] as $report) {
                $this->reports[$report->key()] = $report;
            }
        }

        return $this->reports;
    }

    public function find(string $key): ?Report
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, list<Report>> group => reports the person may run */
    public function groupedFor(User $user): array
    {
        $groups = [];

        foreach ($this->all() as $report) {
            if ($report->canRun($user)) {
                $groups[$report->group()][] = $report;
            }
        }

        return $groups;
    }

    /**
     * Baseline reports whose data does not exist yet.
     *
     * @return list<array{title: string, phase: int, why: string}>
     */
    public function planned(): array
    {
        return [
            ['title' => 'AI scoring and recommendation activity', 'phase' => 18, 'why' => 'AI assistance is optional and is built in Phase 18.'],
        ];
    }
}
