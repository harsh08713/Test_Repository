<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderAccess;
use Illuminate\Database\Eloquent\Builder;

/** Orders that are not finished: still being made, waiting for an approver, or approved with no invoice yet — and how long they have waited. */
final class PendingSalesOrders extends Report
{
    public function key(): string
    {
        return 'pending-sales-orders';
    }

    public function title(): string
    {
        return 'Pending sales orders';
    }

    public function description(): string
    {
        return 'Orders still open: being drafted, waiting for approval, or approved but not invoiced — with the days waiting and any invoice error.';
    }

    public function group(): string
    {
        return 'Sales';
    }

    public function permission(): string
    {
        return 'sales_order.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'person', 'team', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Order date';
    }

    public function choiceLabel(): string
    {
        return 'Waiting for';
    }

    public function choices(): array
    {
        return ['draft' => 'Being drafted or rejected', 'approval' => 'An approver', 'invoice' => 'Its invoice (approved)', 'late' => 'Past the delivery date'];
    }

    public function startsOnThisMonth(): bool
    {
        return false;
    }

    public function note(): string
    {
        return 'Nothing here is a result yet: an order counts as sales only once it is approved (see the Sales order register).';
    }

    public function columns(): array
    {
        return [
            new C('so_number', 'Order'), new C('order_date', 'Date', C::DATE), new C('party_name', 'Customer'), new C('owner', 'Owner'), new C('branch', 'Branch'),
            new C('waiting_for', 'Waiting for'), new C('days_open', 'Days open', C::NUMBER), new C('grand_total', 'Total', C::MONEY),
            new C('delivery_date', 'Delivery', C::DATE), new C('days_late', 'Days past delivery', C::NUMBER), new C('invoice_error', 'Invoice problem'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = app(SalesOrderAccess::class)->restrict(SalesOrder::query(), $user)
            ->leftJoin('users', 'users.id', '=', 'sales_orders.assigned_to')
            ->leftJoin('locations', 'locations.id', '=', 'sales_orders.location_id')
            ->leftJoin('sales_invoices', fn ($j) => $j->on('sales_invoices.sales_order_id', '=', 'sales_orders.id')->where('sales_invoices.status', '=', 'issued'))
            ->where('sales_orders.state', '<>', 'cancelled')
            ->where(fn ($q) => $q->whereIn('sales_orders.approval_status', ['draft', 'pending', 'rejected'])
                ->orWhere(fn ($a) => $a->where('sales_orders.approval_status', 'approved')->whereNull('sales_invoices.id')))
            ->select([
                'sales_orders.id', 'sales_orders.so_number', 'sales_orders.order_date', 'sales_orders.party_name', 'sales_orders.grand_total', 'sales_orders.delivery_date',
                'sales_orders.approval_status', 'sales_orders.invoice_error', 'users.name as owner', 'locations.name as branch',
            ])
            ->selectRaw('datediff(curdate(), sales_orders.order_date) as days_open')
            ->selectRaw('case when sales_orders.delivery_date < curdate() then datediff(curdate(), sales_orders.delivery_date) end as days_late');

        $this->between($query, 'sales_orders.order_date', $input);
        $input->branch === null || $query->where('sales_orders.location_id', $input->branch);
        $input->peopleIds === null || $query->whereIn('sales_orders.assigned_to', $input->peopleIds);

        match ($input->choice) {
            'draft' => $query->whereIn('sales_orders.approval_status', ['draft', 'rejected']),
            'approval' => $query->where('sales_orders.approval_status', 'pending'),
            'invoice' => $query->where('sales_orders.approval_status', 'approved'),
            'late' => $query->where('sales_orders.delivery_date', '<', now()->toDateString()),
            default => null,
        };

        return $query->orderBy('sales_orders.order_date')->orderBy('sales_orders.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['waiting_for'] = match ((string) $row->getRawOriginal('approval_status')) {
            'draft' => 'Being drafted', 'rejected' => 'Rejected — to be corrected', 'pending' => 'An approver', default => 'Its invoice',
        };

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), 'count(*) as total, coalesce(sum(sales_orders.grand_total), 0) as value, coalesce(sum(sales_orders.delivery_date < curdate()), 0) as late');

        return ['Orders' => number_format((int) ($t->total ?? 0)), 'Total, with tax' => '₹ '.number_format((float) ($t->value ?? 0), 2), 'Past the delivery date' => number_format((int) ($t->late ?? 0))];
    }
}
