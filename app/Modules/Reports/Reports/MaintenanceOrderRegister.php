<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Maintenance\Models\MaintenanceOrder;
use App\Modules\Maintenance\Services\MaintenanceFigures;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/** Maintenance work orders with the asset, type, who has them, when due, how long a breakdown kept the asset down, and the hours worked. Read from the orders and their entries. */
final class MaintenanceOrderRegister extends Report
{
    public function key(): string
    {
        return 'maintenance-order-register';
    }

    public function title(): string
    {
        return 'Maintenance work orders';
    }

    public function description(): string
    {
        return 'Every maintenance work order you may see: the asset, what kind, who has it, when it is due, the downtime of a breakdown, and the hours worked.';
    }

    public function group(): string
    {
        return 'Maintenance';
    }

    public function permission(): string
    {
        return 'maintenance_order.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Raised';
    }

    public function choiceLabel(): string
    {
        return 'State';
    }

    public function choices(): array
    {
        return MaintenanceOrder::STATES;
    }

    public function note(): string
    {
        return 'Downtime is from when a breakdown stopped the asset to when it was running again (for one still open, to now). Hours are those logged on the work order.';
    }

    public function columns(): array
    {
        return [
            new C('order_number', 'Order'), new C('created_at', 'Raised', C::DATE), new C('asset_name', 'Asset'), new C('branch', 'Branch'), new C('type_label', 'Type'), new C('priority_label', 'Priority'),
            new C('title', 'What'), new C('assignee', 'Assigned to'), new C('due_on', 'Due', C::DATE), new C('state_label', 'State'), new C('downtime_text', 'Downtime'), new C('hours_text', 'Hours'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = MaintenanceOrder::query()
            ->join('assets as a', 'a.id', '=', 'maintenance_orders.asset_id')
            ->leftJoin('locations as l', 'l.id', '=', 'maintenance_orders.location_id')
            ->leftJoin('users as u', 'u.id', '=', 'maintenance_orders.assigned_to')
            ->select(['maintenance_orders.id', 'maintenance_orders.order_number', 'maintenance_orders.created_at', 'maintenance_orders.title', 'maintenance_orders.type', 'maintenance_orders.priority', 'maintenance_orders.due_on',
                'maintenance_orders.state', 'maintenance_orders.stopped_at', 'maintenance_orders.restored_at', 'a.name as asset_name', 'l.name as branch', 'u.name as assignee'])
            ->selectRaw('(select coalesce(sum(h.hours), 0) from maintenance_labour h where h.maintenance_order_id = maintenance_orders.id) as hours');

        $this->between($query, 'maintenance_orders.created_at', $input);
        $input->branch === null || $query->where('maintenance_orders.location_id', $input->branch);
        $input->choice === null || $query->where('maintenance_orders.state', $input->choice);

        return $query->orderByDesc('maintenance_orders.created_at')->orderByDesc('maintenance_orders.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $stopped = $row->getAttribute('stopped_at');
        $restored = $row->getAttribute('restored_at');
        $end = $restored ?? ($row->getRawOriginal('state') === 'open' || $row->getRawOriginal('state') === 'in_progress' ? now() : null);

        $out['type_label'] = MaintenanceOrder::TYPES[(string) $row->getRawOriginal('type')] ?? (string) $row->getRawOriginal('type');
        $out['priority_label'] = MaintenanceOrder::PRIORITIES[(string) $row->getRawOriginal('priority')] ?? (string) $row->getRawOriginal('priority');
        $out['state_label'] = MaintenanceOrder::STATES[(string) $row->getRawOriginal('state')] ?? (string) $row->getRawOriginal('state');
        $out['downtime_text'] = $row->getRawOriginal('type') === 'breakdown' && $stopped !== null && $end !== null
            ? MaintenanceFigures::duration(max(0, (int) round((strtotime((string) $end) - strtotime((string) $stopped)) / 60))) : '—';
        $out['hours_text'] = rtrim(rtrim(number_format((float) $row->getAttribute('hours'), 2, '.', ''), '0'), '.') ?: '0';

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $today = now()->toDateString();   // the application's day, not the database server's
        $t = $this->totals($this->query($input, $user), "count(*) as total, coalesce(sum(maintenance_orders.state in ('open', 'in_progress')), 0) as open_orders, coalesce(sum(maintenance_orders.state in ('open', 'in_progress') and maintenance_orders.due_on < '{$today}'), 0) as overdue, coalesce(sum(maintenance_orders.type = 'breakdown'), 0) as breakdowns");

        return ['Work orders' => number_format((int) ($t->total ?? 0)), 'Not finished' => number_format((int) ($t->open_orders ?? 0)), 'Overdue' => number_format((int) ($t->overdue ?? 0)), 'Breakdowns' => number_format((int) ($t->breakdowns ?? 0))];
    }
}
