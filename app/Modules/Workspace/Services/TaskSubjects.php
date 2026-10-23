<?php

namespace App\Modules\Workspace\Services;

use App\Models\User;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\DealAccess;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Services\LedgerLookup;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\QuotationAccess;
use App\Modules\Sales\Services\SalesOrderAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds the records a task can be about, for the picker on the task form: each kind through the access class of its own screen and only for a
 * person who may see that kind at all, so the picker never offers more than the person could open. (The service checks the choice again when the
 * task is saved: this only narrows what is offered.)
 */
final class TaskSubjects
{
    public const MIN = 2;

    public function __construct(
        private readonly LeadAccess $leads,
        private readonly DealAccess $deals,
        private readonly QuotationAccess $quotations,
        private readonly SalesOrderAccess $orders,
        private readonly LedgerLookup $ledgers,
    ) {}

    /**
     * @return list<array{type: string, kind: string, id: int, label: string}>
     */
    public function search(User $user, string $term, int $limit = 4): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN) {
            return [];
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
        $out = [];

        $add = function (string $type, string $kind, Builder $query, callable $label) use (&$out, $limit): void {
            foreach ($query->limit($limit)->get() as $row) {
                $out[] = ['type' => $type, 'kind' => $kind, 'id' => (int) $row->getKey(), 'label' => $label($row)];
            }
        };

        if ($user->can('lead.view')) {
            $add('lead', 'Lead', $this->leads->restrict(Lead::query(), $user)->where(fn (Builder $q) => $q->where('leads.lead_number', 'like', $like)->orWhere('leads.name', 'like', $like)->orWhere('leads.mobile', 'like', $like))->orderByDesc('leads.id'), fn (Lead $l) => $l->title());
        }

        if ($user->can('deal.view')) {
            $add('deal', 'Deal', $this->deals->restrict(Deal::query(), $user)->where(fn (Builder $q) => $q->where('deals.deal_number', 'like', $like)->orWhere('deals.party_name', 'like', $like))->orderByDesc('deals.id'), fn (Deal $d) => $d->deal_number.' · '.$d->party_name);
        }

        if ($user->can('quotation.view')) {
            $add('quotation', 'Quotation', $this->quotations->restrict(Quotation::query(), $user)->where(fn (Builder $q) => $q->where('quotations.quotation_number', 'like', $like)->orWhere('quotations.party_name', 'like', $like))->orderByDesc('quotations.id'), fn (Quotation $x) => $x->quotation_number.' · '.$x->party_name);
        }

        if ($user->can('sales_order.view')) {
            $add('sales_order', 'Sales order', $this->orders->restrict(SalesOrder::query(), $user)->where(fn (Builder $q) => $q->where('sales_orders.so_number', 'like', $like)->orWhere('sales_orders.party_name', 'like', $like))->orderByDesc('sales_orders.id'), fn (SalesOrder $o) => $o->so_number.' · '.$o->party_name);
        }

        if ($user->can('ledger.view')) {
            foreach ($this->ledgers->search(LedgerType::Customer, $term, $limit) as $ledger) {
                $out[] = ['type' => 'ledger', 'kind' => 'Customer', 'id' => (int) $ledger->getKey(), 'label' => $ledger->code.' · '.$ledger->name];
            }
        }

        return $out;
    }
}
