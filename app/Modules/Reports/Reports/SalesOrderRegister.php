<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Sales\Enums\CreditResult;
use App\Modules\Sales\Enums\SalesOrderState;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderAccess;
use Illuminate\Database\Eloquent\Builder;

/** Every sales order with its source, owner, approval, credit check and invoice. Only approved, uncancelled orders are results. */
final class SalesOrderRegister extends Report
{
    public function key(): string
    {
        return 'sales-order-register';
    }

    public function title(): string
    {
        return 'Sales order register';
    }

    public function description(): string
    {
        return 'Every sales order you may see: where it came from, who owns it, its approval, credit check, value and invoice.';
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
        return 'Show';
    }

    public function choices(): array
    {
        return ['approved' => 'Approved (counts as sales)', 'waiting' => 'Waiting for approval', 'draft' => 'Drafts and rejected', 'cancelled' => 'Cancelled'];
    }

    public function note(): string
    {
        return 'ACTUAL: the totals above the table count only approved orders that are not cancelled — the same orders that count towards targets, and net of tax. Drafts and orders waiting for approval are listed but never added in.';
    }

    public function columns(): array
    {
        return [
            new C('so_number', 'Order'), new C('order_date', 'Date', C::DATE), new C('party_name', 'Customer'), new C('source', 'Made from'), new C('owner', 'Owner'),
            new C('branch', 'Branch'), new C('approval', 'Approval'), new C('state', 'State'), new C('credit', 'Credit check'),
            new C('taxable_total', 'Net of tax', C::MONEY), new C('grand_total', 'Total', C::MONEY), new C('delivery_date', 'Delivery', C::DATE),
            new C('invoice_number', 'Invoice'), new C('customer_po_number', 'Customer’s order no.'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = app(SalesOrderAccess::class)->restrict(SalesOrder::query(), $user)
            ->leftJoin('users', 'users.id', '=', 'sales_orders.assigned_to')
            ->leftJoin('locations', 'locations.id', '=', 'sales_orders.location_id')
            ->leftJoin('sales_invoices', fn ($j) => $j->on('sales_invoices.sales_order_id', '=', 'sales_orders.id')->where('sales_invoices.status', '=', 'issued'))
            ->select([
                'sales_orders.id', 'sales_orders.so_number', 'sales_orders.order_date', 'sales_orders.party_name', 'sales_orders.source_type', 'sales_orders.approval_status',
                'sales_orders.state', 'sales_orders.credit_result', 'sales_orders.taxable_total', 'sales_orders.grand_total', 'sales_orders.delivery_date', 'sales_orders.customer_po_number',
                'users.name as owner', 'locations.name as branch', 'sales_invoices.invoice_number',
            ]);

        $this->between($query, 'sales_orders.order_date', $input);
        $input->branch === null || $query->where('sales_orders.location_id', $input->branch);
        $input->peopleIds === null || $query->whereIn('sales_orders.assigned_to', $input->peopleIds);

        match ($input->choice) {
            'approved' => $query->where('sales_orders.approval_status', 'approved')->where('sales_orders.state', '<>', 'cancelled'),
            'waiting' => $query->where('sales_orders.approval_status', 'pending')->where('sales_orders.state', '<>', 'cancelled'),
            'draft' => $query->whereIn('sales_orders.approval_status', ['draft', 'rejected'])->where('sales_orders.state', '<>', 'cancelled'),
            'cancelled' => $query->where('sales_orders.state', 'cancelled'),
            default => null,
        };

        return $query->orderByDesc('sales_orders.order_date')->orderByDesc('sales_orders.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['source'] = match ((string) $row->getRawOriginal('source_type')) {
            'quotation' => 'A quotation', 'deal' => 'A deal', default => 'Directly',
        };
        $out['approval'] = ucfirst((string) $row->getRawOriginal('approval_status'));
        $out['state'] = SalesOrderState::tryFrom((string) $row->getRawOriginal('state'))?->label();
        $out['credit'] = CreditResult::tryFrom((string) $row->getRawOriginal('credit_result'))?->label();

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals(
            $this->query($input, $user),
            "count(*) as total, coalesce(sum(sales_orders.approval_status = 'approved' and sales_orders.state <> 'cancelled'), 0) as approved, "
            ."coalesce(sum(case when sales_orders.approval_status = 'approved' and sales_orders.state <> 'cancelled' then sales_orders.taxable_total end), 0) as net, "
            ."coalesce(sum(case when sales_orders.approval_status = 'approved' and sales_orders.state <> 'cancelled' then sales_orders.grand_total end), 0) as gross, "
            ."coalesce(sum(sales_orders.approval_status in ('draft','pending','rejected') and sales_orders.state <> 'cancelled'), 0) as not_yet",
        );

        return [
            'Orders listed' => number_format((int) ($t->total ?? 0)), 'Approved (actual)' => number_format((int) ($t->approved ?? 0)),
            'Approved, net of tax' => '₹ '.number_format((float) ($t->net ?? 0), 2), 'Approved, with tax' => '₹ '.number_format((float) ($t->gross ?? 0), 2),
            'Not approved yet (not counted)' => number_format((int) ($t->not_yet ?? 0)),
        ];
    }
}
