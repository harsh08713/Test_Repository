<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Marketing\Models\CampaignMember;
use App\Modules\Marketing\Models\Communication;
use Illuminate\Support\Facades\DB;

/**
 * What came of campaigns: people, messages, responses, and the leads, deals and **approved** sales credited to them. Only provider-confirmed signals
 * would count as delivered, opened or clicked — there are none yet, so there are no such figures here, and none are implied: "sent" is "handed to the
 * mail server".
 *
 * These are company figures about a campaign (counts and a total), not lists of people, so they are the same for everyone who may see the campaign.
 * Sales are approved, uncancelled orders, net of tax, made from a deal or lead that the campaign is credited with.
 */
final class CampaignStats
{
    /**
     * @param  list<int>  $ids
     * @return array<int, array{people: int, active: int, sent: int, failed: int, held: int, responded: int, leads: int, deals: int, orders: int, sales: string}>
     */
    public function for(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach ($ids as $id) {
            $out[$id] = ['people' => 0, 'active' => 0, 'sent' => 0, 'failed' => 0, 'held' => 0, 'responded' => 0, 'leads' => 0, 'deals' => 0, 'orders' => 0, 'sales' => '0.00'];
        }

        foreach (CampaignMember::query()->whereIn('campaign_id', $ids)->toBase()
            ->selectRaw("campaign_id, count(*) as people, sum(status = 'active') as active, sum(responded_at is not null) as responded")->groupBy('campaign_id')->get() as $row) {
            $out[$row->campaign_id]['people'] = (int) $row->people;
            $out[$row->campaign_id]['active'] = (int) $row->active;
            $out[$row->campaign_id]['responded'] = (int) $row->responded;
        }

        foreach (Communication::query()->whereIn('campaign_id', $ids)->toBase()
            ->selectRaw("campaign_id, sum(status = 'sent') as sent, sum(status = 'failed') as failed, sum(status in ('suppressed','not_connected')) as held")->groupBy('campaign_id')->get() as $row) {
            $out[$row->campaign_id]['sent'] = (int) $row->sent;
            $out[$row->campaign_id]['failed'] = (int) $row->failed;
            $out[$row->campaign_id]['held'] = (int) $row->held;
        }

        foreach (DB::table('leads')->whereIn('campaign_id', $ids)->whereNull('deleted_at')->selectRaw('campaign_id, count(*) as n')->groupBy('campaign_id')->get() as $row) {
            $out[$row->campaign_id]['leads'] = (int) $row->n;
        }

        foreach (DB::table('deals')->join('leads', 'leads.id', '=', 'deals.lead_id')->whereIn('leads.campaign_id', $ids)->whereNull('deals.deleted_at')
            ->selectRaw('leads.campaign_id as campaign_id, count(distinct deals.id) as n')->groupBy('leads.campaign_id')->get() as $row) {
            $out[$row->campaign_id]['deals'] = (int) $row->n;
        }

        // an order belongs to a campaign through its lead (a deal's order carries the lead as well)
        foreach (DB::table('sales_orders')->join('leads', 'leads.id', '=', 'sales_orders.lead_id')->whereIn('leads.campaign_id', $ids)
            ->whereNull('sales_orders.deleted_at')->where('sales_orders.approval_status', 'approved')->where('sales_orders.state', '<>', 'cancelled')
            ->selectRaw('leads.campaign_id as campaign_id, count(*) as n, coalesce(sum(sales_orders.taxable_total), 0) as net')->groupBy('leads.campaign_id')->get() as $row) {
            $out[$row->campaign_id]['orders'] = (int) $row->n;
            $out[$row->campaign_id]['sales'] = bcadd((string) $row->net, '0', 2);
        }

        return $out;
    }
}
