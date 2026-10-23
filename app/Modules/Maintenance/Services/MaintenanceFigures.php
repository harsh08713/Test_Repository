<?php

namespace App\Modules\Maintenance\Services;

use App\Modules\Maintenance\Models\MaintenanceOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * What happened on a work order or to an asset, **worked out** from the entries and never stored: downtime (from when a breakdown stopped the asset to when it was running again), hours
 * worked, the value of the spare parts (what the stock ledger says they were issued at), and for an asset the number of breakdowns, total downtime and the mean time to repair.
 * The screens and the reports read these and nothing else.
 */
final class MaintenanceFigures
{
    /**
     * @return array{downtime_minutes: int|null, labour_hours: string, parts_value: string, tasks_total: int, tasks_done: int}
     */
    public function forOrder(MaintenanceOrder $order): array
    {
        $end = $order->restored_at ?? ($order->isOpen() ? now() : null);

        return [
            'downtime_minutes' => $order->type === 'breakdown' && $order->stopped_at !== null && $end !== null ? max(0, (int) $order->stopped_at->diffInMinutes($end)) : null,
            'labour_hours' => $this->labourHours([(int) $order->getKey()]),
            'parts_value' => $this->partsValue([(int) $order->getKey()]),
            'tasks_total' => $order->tasks()->count(), 'tasks_done' => $order->tasks()->whereNotNull('done_at')->count(),
        ];
    }

    /**
     * Breakdowns that were completed since $since.
     *
     * @return array{breakdowns: int, downtime_minutes: int, mttr_minutes: int|null, labour_hours: string, parts_value: string}
     */
    public function forAsset(int $assetId, CarbonInterface $since): array
    {
        $rows = DB::table('maintenance_orders')->where('asset_id', $assetId)->where('type', 'breakdown')->where('state', 'completed')->where('completed_at', '>=', $since)
            ->whereNotNull('stopped_at')->whereNotNull('restored_at')->get(['id', 'stopped_at', 'restored_at']);
        $minutes = $rows->sum(fn ($r) => max(0, (int) round((strtotime((string) $r->restored_at) - strtotime((string) $r->stopped_at)) / 60)));
        $ids = DB::table('maintenance_orders')->where('asset_id', $assetId)->where('state', 'completed')->where('completed_at', '>=', $since)->pluck('id')->map(fn ($i) => (int) $i)->all();

        return [
            'breakdowns' => $rows->count(), 'downtime_minutes' => $minutes, 'mttr_minutes' => $rows->count() > 0 ? (int) round($minutes / $rows->count()) : null,
            'labour_hours' => $this->labourHours($ids), 'parts_value' => $this->partsValue($ids),
        ];
    }

    /** @param  list<int>  $orderIds */
    public function labourHours(array $orderIds): string
    {
        return $orderIds === [] ? '0.00' : bcadd((string) DB::table('maintenance_labour')->whereIn('maintenance_order_id', $orderIds)->sum('hours'), '0', 2);
    }

    /** @param  list<int>  $orderIds */
    public function partsValue(array $orderIds): string
    {
        if ($orderIds === []) {
            return '0.00';
        }

        $sum = DB::table('stock_movements')->where('source_type', 'maintenance_part')->where('movement_type', 'issue')
            ->whereIn('source_id', DB::table('maintenance_parts')->whereIn('maintenance_order_id', $orderIds)->select('id'))->sum('value');

        return bcadd(ltrim((string) $sum, '-'), '0', 2);
    }

    /** "2 h 15 min" for a number of minutes. */
    public static function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        return $minutes < 60 ? $minutes.' min' : intdiv($minutes, 60).' h'.($minutes % 60 > 0 ? ' '.($minutes % 60).' min' : '');
    }
}
