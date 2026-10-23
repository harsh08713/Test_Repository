<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Services\CampaignStats;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/**
 * Each campaign with its people, messages, responses, and the leads, deals and **approved** sales credited to it, against its budget. “Sent” is handed to
 * the mail server; delivery, opens and clicks are not shown because no provider reports them.
 */
final class CampaignPerformance extends Report
{
    public function key(): string
    {
        return 'campaign-performance';
    }

    public function title(): string
    {
        return 'Campaign performance';
    }

    public function description(): string
    {
        return 'For each campaign: people, messages, responses, leads, deals and approved sales credited to it, and what it cost.';
    }

    public function group(): string
    {
        return 'Marketing';
    }

    public function permission(): string
    {
        return 'campaign.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Starts';
    }

    public function choiceLabel(): string
    {
        return 'State';
    }

    public function choices(): array
    {
        return ['draft' => 'Draft', 'running' => 'Running', 'paused' => 'Paused', 'completed' => 'Finished', 'cancelled' => 'Called off'];
    }

    public function note(): string
    {
        return 'ACTUAL: sales are approved, uncancelled orders, net of tax, made from a lead the campaign is credited with (it wrote to them, or they came through its link). “Sales per ₹ of budget” is sales ÷ budget, not profit. “Sent” means handed to the mail server — no delivery, open or click figures exist without a provider.';
    }

    public function columns(): array
    {
        return [
            new C('code', 'Campaign'), new C('name', 'Name'), new C('state_label', 'State'), new C('owner', 'Owner'), new C('start_date', 'Starts', C::DATE), new C('budget', 'Budget', C::MONEY),
            new C('people', 'People', C::NUMBER), new C('sent', 'Sent', C::NUMBER), new C('held', 'Not sent', C::NUMBER), new C('failed', 'Failed', C::NUMBER), new C('responded', 'Responded', C::NUMBER),
            new C('leads', 'Leads', C::NUMBER), new C('deals', 'Deals', C::NUMBER), new C('orders', 'Orders', C::NUMBER), new C('sales', 'Approved sales (net)', C::MONEY),
            new C('cost_per_lead', 'Cost per lead', C::MONEY), new C('sales_per_rupee', 'Sales per ₹ of budget', C::NUMBER),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = Campaign::query()->leftJoin('users', 'users.id', '=', 'campaigns.owner_id')
            ->select(['campaigns.id', 'campaigns.code', 'campaigns.name', 'campaigns.state', 'campaigns.start_date', 'campaigns.budget', 'users.name as owner']);

        $this->between($query, 'campaigns.start_date', $input);
        $input->branch === null || $query->where('campaigns.location_id', $input->branch);
        $input->choice === null || $query->where('campaigns.state', $input->choice);

        return $query->orderByDesc('campaigns.start_date')->orderByDesc('campaigns.id');
    }

    public function present(object $row): array
    {
        $s = app(CampaignStats::class)->for([(int) $row->id])[(int) $row->id];
        $budget = $row->budget === null ? null : (string) $row->budget;
        $hasBudget = $budget !== null && bccomp($budget, '0', 2) > 0;

        return [
            'code' => $row->code, 'name' => $row->name, 'state_label' => ['draft' => 'Draft', 'running' => 'Running', 'paused' => 'Paused', 'completed' => 'Finished', 'cancelled' => 'Called off'][$row->getRawOriginal('state')] ?? null,
            'owner' => $row->owner, 'start_date' => $row->start_date, 'budget' => $budget,
            'people' => $s['people'], 'sent' => $s['sent'], 'held' => $s['held'], 'failed' => $s['failed'], 'responded' => $s['responded'],
            'leads' => $s['leads'], 'deals' => $s['deals'], 'orders' => $s['orders'], 'sales' => $s['sales'],
            'cost_per_lead' => $hasBudget && $s['leads'] > 0 ? bcdiv($budget, (string) $s['leads'], 2) : null,
            'sales_per_rupee' => $hasBudget ? bcdiv($s['sales'], $budget, 2) : null,
        ];
    }

    public function summary(ReportInput $input, User $user): array
    {
        $ids = $this->query($input, $user)->limit(500)->pluck('campaigns.id')->map(fn ($id) => (int) $id)->all();
        $all = app(CampaignStats::class)->for($ids);
        $sum = fn (string $k) => array_sum(array_column($all, $k));

        return [
            'Campaigns' => number_format(count($ids)), 'Leads credited' => number_format($sum('leads')), 'Orders credited' => number_format($sum('orders')),
            'Approved sales (net)' => '₹ '.number_format((float) array_reduce(array_column($all, 'sales'), fn ($c, $v) => bcadd((string) $c, (string) $v, 2), '0.00'), 2),
        ];
    }
}
