<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/** Goods receipts with the order they are against, the quantities accepted and rejected, whether stock has been posted, and the approval. */
final class GoodsReceiptRegister extends Report
{
    public function key(): string
    {
        return 'goods-receipt-register';
    }

    public function title(): string
    {
        return 'Goods receipts';
    }

    public function description(): string
    {
        return 'Every goods receipt you may see: the purchase order, the supplier, how much was accepted and rejected, whether the stock has been posted, and its approval.';
    }

    public function group(): string
    {
        return 'Procurement';
    }

    public function permission(): string
    {
        return 'grn.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Received';
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
        return 'Stock is posted once, when a receipt is approved. "Posted" is blank for a receipt whose stock has not gone in (yet). Quantities are in each item\'s base unit and added across items of different kinds only as a count of pieces — read them line by line on the receipt.';
    }

    public function columns(): array
    {
        return [
            new C('grn_number', 'Receipt'), new C('grn_date', 'Date', C::DATE), new C('po_number', 'Order'), new C('supplier_name', 'Supplier'), new C('branch', 'Branch'),
            new C('line_count', 'Lines', C::NUMBER), new C('accepted_text', 'Accepted'), new C('rejected_text', 'Rejected'), new C('posted_at', 'Posted', C::DATETIME), new C('approval', 'Approval'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = Grn::query()
            ->join('purchase_orders as po', 'po.id', '=', 'grns.purchase_order_id')
            ->leftJoin('locations as l', 'l.id', '=', 'grns.location_id')
            ->select(['grns.id', 'grns.grn_number', 'grns.grn_date', 'grns.supplier_name', 'grns.posted_at', 'grns.approval_status', 'po.po_number', 'l.name as branch'])
            ->selectRaw('(select count(*) from grn_items gi where gi.grn_id = grns.id and gi.deleted_at is null) as line_count')
            ->selectRaw('(select coalesce(sum(gi.base_accepted), 0) from grn_items gi where gi.grn_id = grns.id and gi.deleted_at is null) as accepted')
            ->selectRaw('(select coalesce(sum(gi.base_received - gi.base_accepted), 0) from grn_items gi where gi.grn_id = grns.id and gi.deleted_at is null) as rejected');

        $this->between($query, 'grns.grn_date', $input);
        $input->branch === null || $query->where('grns.location_id', $input->branch);
        $input->choice === null || $query->where('grns.approval_status', $input->choice);

        return $query->orderByDesc('grns.grn_date')->orderByDesc('grns.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $trim = fn (mixed $v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.') ?: '0';
        $out['accepted_text'] = $trim($row->getAttribute('accepted'));
        $out['rejected_text'] = $trim($row->getAttribute('rejected'));
        $out['approval'] = ['draft' => 'Draft', 'pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected'][(string) $row->getRawOriginal('approval_status')] ?? (string) $row->getRawOriginal('approval_status');

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), "count(*) as total, coalesce(sum(grns.approval_status = 'approved'), 0) as approved, coalesce(sum(grns.approval_status = 'approved' and grns.posted_at is null), 0) as unposted");

        return ['Receipts' => number_format((int) ($t->total ?? 0)), 'Approved' => number_format((int) ($t->approved ?? 0)), 'Approved but not posted' => number_format((int) ($t->unposted ?? 0))];
    }
}
