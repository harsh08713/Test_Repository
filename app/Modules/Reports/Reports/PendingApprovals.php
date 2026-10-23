<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Shared\Enums\ApprovalLevelStatus;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Approval;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Documents waiting for a decision. Everyone sees the ones that are waiting for **them** (the Approvals inbox, as a report); a holder of
 * `approval.override` — who may decide a level they were not designated for — sees every document waiting in the company.
 */
final class PendingApprovals extends Report
{
    public function key(): string
    {
        return 'pending-approvals';
    }

    public function title(): string
    {
        return 'Pending approvals';
    }

    public function description(): string
    {
        return 'Documents waiting for a decision, how long they have waited and whether the time allowed has run out.';
    }

    public function group(): string
    {
        return 'Control';
    }

    public function permission(): string
    {
        return 'report.view';
    }

    public function filters(): array
    {
        return ['date', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Sent for approval';
    }

    public function choiceLabel(): string
    {
        return 'Document';
    }

    public function choices(): array
    {
        return collect(DocumentType::cases())->mapWithKeys(fn (DocumentType $t) => [$t->value => $t->label()])->all();
    }

    public function startsOnThisMonth(): bool
    {
        return false;
    }

    public function note(): string
    {
        return 'You see the documents waiting for you. A holder of the “override approvals” right sees every document waiting in the company.';
    }

    public function columns(): array
    {
        return [
            new C('document', 'Document'), new C('number', 'Number'), new C('party', 'Customer / item'), new C('submitter', 'Sent by'), new C('level_name', 'Level'),
            new C('approver', 'Waiting for'), new C('created_at', 'Waiting since', C::DATETIME), new C('days_waiting', 'Days waiting', C::NUMBER),
            new C('due_at', 'Due by', C::DATETIME), new C('late', 'Past its time'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $mine = $user->can('approval.override')
            ? null
            : app(ApprovalService::class)->pendingFor($user)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $join = fn (string $table, string $alias) => [$table, fn ($j) => $j->on("{$table}.id", '=', 'approvals.approvable_id')->where('approvals.approvable_type', '=', $alias)];

        $query = Approval::query()
            ->where('approvals.status', ApprovalLevelStatus::Pending->value)
            ->leftJoin('users as sub', 'sub.id', '=', 'approvals.submitted_by')
            ->leftJoin('users as who', 'who.id', '=', 'approvals.approver_user_id')
            ->leftJoin(...$join('leads', 'lead'))
            ->leftJoin(...$join('deals', 'deal'))
            ->leftJoin(...$join('quotations', 'quotation'))
            ->leftJoin(...$join('sales_orders', 'sales_order'))
            ->leftJoin(...$join('sales_invoices', 'sales_invoice'))
            ->leftJoin(...$join('ledgers', 'ledger'))
            ->leftJoin(...$join('items', 'item'))
            ->select(['approvals.id', 'approvals.approvable_type', 'approvals.level_name', 'approvals.approver_role', 'approvals.created_at', 'approvals.due_at', 'sub.name as submitter', 'who.name as approver_name'])
            ->selectRaw('coalesce(leads.lead_number, deals.deal_number, quotations.quotation_number, sales_orders.so_number, sales_invoices.invoice_number, ledgers.code, items.code) as number')
            ->selectRaw('coalesce(leads.name, deals.party_name, quotations.party_name, sales_orders.party_name, sales_invoices.party_name, ledgers.name, items.name) as party')
            ->selectRaw('datediff(curdate(), date(approvals.created_at)) as days_waiting');

        $mine === null || $query->whereIn('approvals.id', $mine === [] ? [0] : $mine);
        $this->between($query, 'approvals.created_at', $input, true);
        $input->choice === null || $query->where('approvals.approvable_type', $input->choice);

        return $query->orderBy('approvals.created_at')->orderBy('approvals.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['document'] = DocumentType::tryFrom((string) $row->getRawOriginal('approvable_type'))?->label() ?? $row->getRawOriginal('approvable_type');
        $out['approver'] = $row->approver_name ?? ($row->approver_role !== null ? 'Anyone with the role “'.$row->approver_role.'”' : 'Anyone who may approve');
        $out['late'] = $row->due_at !== null && Carbon::parse($row->due_at)->isPast() ? 'Yes' : 'No';

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), 'count(*) as total, coalesce(sum(approvals.due_at < now()), 0) as late, coalesce(max(datediff(curdate(), date(approvals.created_at))), 0) as oldest');

        return ['Waiting' => number_format((int) ($t->total ?? 0)), 'Past their time' => number_format((int) ($t->late ?? 0)), 'Longest wait (days)' => number_format((int) ($t->oldest ?? 0))];
    }
}
