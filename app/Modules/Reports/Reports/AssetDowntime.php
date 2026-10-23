<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Services\MaintenanceFigures;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/**
 * Breakdowns, downtime and the mean time to repair for each asset, over the breakdowns **completed** in the period — the same figures as the asset page (`MaintenanceFigures::forAsset`),
 * which a test holds equal.
 */
final class AssetDowntime extends Report
{
    public function key(): string
    {
        return 'asset-downtime';
    }

    public function title(): string
    {
        return 'Asset downtime';
    }

    public function description(): string
    {
        return 'For each asset: the breakdowns completed in the period, the time it was down, the mean time to repair, and the hours worked on its work orders.';
    }

    public function group(): string
    {
        return 'Maintenance';
    }

    public function permission(): string
    {
        return 'asset.view';
    }

    public function filters(): array
    {
        return ['date', 'branch'];
    }

    public function dateLabel(): string
    {
        return 'Completed';
    }

    public function note(): string
    {
        return 'Only completed breakdowns are counted. Mean time to repair = total downtime ÷ number of breakdowns. Assets with no breakdown in the period show zero.';
    }

    public function columns(): array
    {
        return [
            new C('code', 'Asset'), new C('name', 'Name'), new C('branch', 'Branch'), new C('criticality_label', 'Criticality'), new C('breakdowns', 'Breakdowns', C::NUMBER),
            new C('downtime_text', 'Downtime'), new C('mttr_text', 'Mean time to repair'), new C('hours_text', 'Hours worked'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $when = ($input->from !== null ? ' and o.completed_at >= ?' : '').($input->to !== null ? ' and o.completed_at < date_add(?, interval 1 day)' : '');
        $bind = array_values(array_filter([$input->from, $input->to], fn ($v) => $v !== null));

        $query = Asset::query()->leftJoin('locations as l', 'l.id', '=', 'assets.location_id')
            ->select(['assets.id', 'assets.code', 'assets.name', 'assets.criticality', 'l.name as branch'])
            ->selectRaw("(select count(*) from maintenance_orders o where o.asset_id = assets.id and o.type = 'breakdown' and o.state = 'completed' and o.stopped_at is not null and o.restored_at is not null{$when}) as breakdowns", $bind)
            ->selectRaw("(select coalesce(sum(timestampdiff(minute, o.stopped_at, o.restored_at)), 0) from maintenance_orders o where o.asset_id = assets.id and o.type = 'breakdown' and o.state = 'completed' and o.stopped_at is not null and o.restored_at is not null{$when}) as downtime", $bind)
            ->selectRaw("(select coalesce(sum(h.hours), 0) from maintenance_labour h join maintenance_orders o on o.id = h.maintenance_order_id where o.asset_id = assets.id and o.state = 'completed'{$when}) as hours", $bind);

        $input->branch === null || $query->where('assets.location_id', $input->branch);

        return $query->orderByDesc('downtime')->orderBy('assets.code');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $breakdowns = (int) $row->getAttribute('breakdowns');
        $downtime = (int) $row->getAttribute('downtime');

        $out['criticality_label'] = Asset::CRITICALITY[(string) $row->getRawOriginal('criticality')] ?? (string) $row->getRawOriginal('criticality');
        $out['downtime_text'] = MaintenanceFigures::duration($downtime);
        $out['mttr_text'] = $breakdowns > 0 ? MaintenanceFigures::duration((int) round($downtime / $breakdowns)) : '—';
        $out['hours_text'] = rtrim(rtrim(number_format((float) $row->getAttribute('hours'), 2, '.', ''), '0'), '.') ?: '0';

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $rows = $this->query($input, $user)->get();

        return ['Assets' => number_format($rows->count()), 'Breakdowns' => number_format((int) $rows->sum(fn ($r) => (int) $r->getAttribute('breakdowns'))), 'Downtime' => MaintenanceFigures::duration((int) $rows->sum(fn ($r) => (int) $r->getAttribute('downtime')))];
    }
}
