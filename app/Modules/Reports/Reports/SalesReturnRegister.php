<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesOrderAccess;
use Illuminate\Database\Eloquent\Builder;

/** Sales returns and credit notes with their invoice, customer and value. Only approved ones reduce net revenue. */
final class SalesReturnRegister extends Report
{
    public function key(): string
    {
        return 'sales-return-register';
    }

    public function title(): string
    {
        return 'Returns and credit notes';
    }

    public function description(): string
    {
        return 'Every return and credit note you may see, with the invoice it is against, the customer, the reason and the value.';
    }

    public function group(): string
    {
        return 'Sales';
    }

    public function permission(): string
    {
        return 'sales_return.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Return date';
    }

    public function choiceLabel(): string
    {
        return 'Approval';
    }

    public function choices(): array
    {
        return ['draft' => 'Draft', 'pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Rejected'];
    }

    public function note(): string
    {
        return 'Only approved returns and credit notes are taken off net revenue; the others are listed so nothing is hidden.';
    }

    public function columns(): array
    {
        return [
            new C('return_number', 'Return'), new C('return_date', 'Date', C::DATE), new C('kind', 'Kind'), new C('invoice_number', 'Invoice'), new C('party_name', 'Customer'),
            new C('reason', 'Reason'), new C('taxable_total', 'Before tax', C::MONEY), new C('grand_total', 'Total', C::MONEY), new C('approval', 'Approval'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = app(SalesOrderAccess::class)->restrictReturns(SalesReturn::query(), $user)
            ->join('sales_invoices as inv', 'inv.id', '=', 'sales_returns.sales_invoice_id')
            ->select(['sales_returns.id', 'sales_returns.return_number', 'sales_returns.return_date', 'sales_returns.return_type', 'sales_returns.party_name', 'sales_returns.reason',
                'sales_returns.taxable_total', 'sales_returns.grand_total', 'sales_returns.approval_status', 'inv.invoice_number']);

        $this->between($query, 'sales_returns.return_date', $input);
        $input->branch === null || $query->where('sales_returns.location_id', $input->branch);
        $input->choice === null || $query->where('sales_returns.approval_status', $input->choice);

        return $query->orderByDesc('sales_returns.return_date')->orderByDesc('sales_returns.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['kind'] = (string) $row->getRawOriginal('return_type') === 'credit_note' ? 'Credit note' : 'Return';
        $out['approval'] = ['draft' => 'Draft', 'pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected'][(string) $row->getRawOriginal('approval_status')] ?? (string) $row->getRawOriginal('approval_status');

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), "count(*) as total, coalesce(sum(case when sales_returns.approval_status = 'approved' then sales_returns.taxable_total end), 0) as approved");

        return ['Returns' => number_format((int) ($t->total ?? 0)), 'Approved, before tax (₹)' => number_format((float) ($t->approved ?? 0), 2)];
    }
}
