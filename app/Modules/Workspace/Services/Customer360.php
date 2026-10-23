<?php

namespace App\Modules\Workspace\Services;

use App\Models\User;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\DealStageEntry;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\DealAccess;
use App\Modules\CRM\Services\FollowupAgenda;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\Marketing\Models\Communication;
use App\Modules\Marketing\Services\CommunicationAccess;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\CreditCheck;
use App\Modules\Sales\Services\QuotationAccess;
use App\Modules\Sales\Services\SalesOrderAccess;
use App\Modules\Workspace\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the company knows about one customer, in one place — for the person looking, and only what they may already see elsewhere.
 *
 * Each section is read through the access class of its own screen (`LeadAccess`, `DealAccess`, `QuotationAccess`, `SalesOrderAccess`,
 * `FollowupAgenda`, `TaskAccess`), so a salesperson sees their own leads, deals, quotations and orders for this customer, a manager their team's, an
 * administrator all of them — and a section the person has no right to at all (no `deal.view`) is left out. The customer's master record and
 * contacts follow the Ledger screen (`ledger.view`); **bank details never appear here**.
 *
 * What is linked: records whose customer is this ledger (inquiries, deals, quotations, orders, invoices), and the leads that became those deals.
 * A new lead that was never tied to a customer record is not found by name or phone number — it is not this customer until somebody links it.
 * Sections that need later phases (communications, campaign attribution, documents, support tickets) are named, not faked.
 */
final class Customer360
{
    /** How many of each kind are listed; the total is always counted. */
    public const LIST = 8;

    public function __construct(
        private readonly LeadAccess $leads,
        private readonly DealAccess $deals,
        private readonly QuotationAccess $quotations,
        private readonly SalesOrderAccess $orders,
        private readonly FollowupAgenda $agenda,
        private readonly TaskAccess $tasks,
        private readonly CreditCheck $credit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Ledger $ledger, User $user): array
    {
        $id = (int) $ledger->getKey();
        $can = fn (string $permission): bool => $user->can($permission);

        $deals = $can('deal.view') ? $this->deals->restrict(Deal::query(), $user)->where('deals.customer_ledger_id', $id) : null;
        $leadIdsFromDeals = $deals === null ? [] : (clone $deals)->pluck('deals.lead_id')->filter()->all();
        $leads = $can('lead.view') ? $this->leads->restrict(Lead::query(), $user)->where(fn (Builder $q) => $q->where('leads.customer_ledger_id', $id)->orWhereIn('leads.id', $leadIdsFromDeals)) : null;
        $quotes = $can('quotation.view') ? $this->quotations->restrict(Quotation::query(), $user)->where('quotations.customer_ledger_id', $id) : null;
        $orders = $can('sales_order.view') ? $this->orders->restrict(SalesOrder::query(), $user)->where('sales_orders.customer_ledger_id', $id) : null;
        $invoices = $can('sales_invoice.view') ? $this->orders->restrictInvoices(SalesInvoice::query(), $user)->where('sales_invoices.customer_ledger_id', $id) : null;

        $sales = $orders === null ? null : (clone $orders)->where('sales_orders.approval_status', 'approved')->where('sales_orders.state', '<>', 'cancelled')
            ->toBase()->selectRaw('count(*) as n, coalesce(sum(sales_orders.taxable_total), 0) as net, max(sales_orders.order_date) as last_on')->first();

        return [
            'credit' => $this->creditStatus($ledger),
            'contacts' => $ledger->contacts()->orderByDesc('is_primary')->orderBy('name')->get(['id', 'name', 'designation', 'phone', 'mobile', 'email', 'is_primary']),
            'addresses' => $ledger->addresses()->get(),
            'totals' => [
                'leads' => $leads === null ? null : (clone $leads)->count(),
                'open_deals' => $deals === null ? null : (clone $deals)->whereNotIn('deals.stage', ['won', 'lost'])->count(),
                'open_quotations' => $quotes === null ? null : (clone $quotes)->whereIn('quotations.state', ['draft', 'sent', 'accepted'])->count(),
                'orders' => $sales === null ? null : (int) $sales->n,
                'sales' => $sales === null ? null : (string) $sales->net,
                'last_order' => $sales?->last_on,
            ],
            'leads' => $leads === null ? null : $this->rows($leads->clone(), 'leads', 'leads.lead_date'),
            'deals' => $deals === null ? null : $this->rows($deals->clone(), 'deals', 'deals.deal_date'),
            'quotations' => $quotes === null ? null : $this->rows($quotes->clone(), 'quotations', 'quotations.quotation_date'),
            'orders' => $orders === null ? null : $this->rows($orders->clone(), 'sales_orders', 'sales_orders.order_date'),
            'invoices' => $invoices === null ? null : $this->rows($invoices->clone(), 'sales_invoices', 'sales_invoices.invoice_date'),
            'communications' => $can('communication.view') ? $this->communications($user, $id, $leads === null ? [] : (clone $leads)->pluck('leads.id')->all()) : null,
            'possible' => $can('lead.view') ? $this->possibleLeads($ledger, $user) : null,
            'followups' => $can('followup.view') && $leads !== null ? $this->followups($user, (clone $leads)->pluck('leads.id')->all()) : null,
            'tasks' => $can('task.view') ? $this->taskRows($user, $id, $leads, $deals, $quotes, $orders) : null,
            'history' => $deals === null ? null : $this->stageHistory((clone $deals)->pluck('deals.id')->all()),
            'later' => [
                ['title' => 'WhatsApp, SMS and call history', 'phase' => 16, 'why' => 'Those channels need their providers, which are connected in Phase 16. E-mail from campaigns and workflows is listed above.'],
                ['title' => 'Documents and e-signatures', 'phase' => 19, 'why' => 'Document management is an optional extension (Phase 19).'],
                ['title' => 'Support tickets', 'phase' => 19, 'why' => 'The support module is an optional extension (Phase 19).'],
            ],
        ];
    }

    /**
     * The messages the system sent or held back to this customer or to the leads that are theirs, as far as the person may read them.
     *
     * @param  list<int|string>  $leadIds
     * @return Collection<int, Communication>
     */
    private function communications(User $user, int $ledgerId, array $leadIds): Collection
    {
        return app(CommunicationAccess::class)->restrict(Communication::query(), $user)
            ->where(fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('communications.subject_type', 'ledger')->where('communications.subject_id', $ledgerId))
                ->orWhere(fn (Builder $w) => $w->where('communications.subject_type', 'lead')->whereIn('communications.subject_id', $leadIds === [] ? [0] : $leadIds)))
            ->with('campaign:id,code,name')->orderByDesc('communications.id')->limit(self::LIST)->get();
    }

    /**
     * Leads nobody has tied to a customer whose mobile number or e-mail address is one of this customer's (the ledger's own or a contact's).
     * They are suggestions to look at, never part of the customer's figures: only a person linking the lead makes it this customer's.
     *
     * @return Collection<int, Lead>
     */
    private function possibleLeads(Ledger $ledger, User $user): Collection
    {
        $mobiles = collect([$ledger->phone, ...$ledger->contacts()->get(['mobile', 'phone'])->flatMap(fn ($c) => [$c->mobile, $c->phone])->all()])
            ->map(fn ($n) => substr((string) preg_replace('/\D+/', '', (string) $n), -10))->filter(fn (string $n) => strlen($n) === 10)->unique()->values()->all();
        $emails = collect([$ledger->email, ...$ledger->contacts()->pluck('email')->all()])
            ->map(fn ($e) => strtolower(trim((string) $e)))->filter(fn (string $e) => $e !== '')->unique()->values()->all();

        if ($mobiles === [] && $emails === []) {
            return collect();
        }

        return $this->leads->restrict(Lead::query(), $user)->whereNull('leads.customer_ledger_id')
            ->where(function (Builder $q) use ($mobiles, $emails) {
                $mobiles === [] || $q->orWhereRaw("right(replace(replace(replace(replace(leads.mobile, ' ', ''), '-', ''), '+', ''), '.', ''), 10) in (".implode(',', array_fill(0, count($mobiles), '?')).')', $mobiles);
                $emails === [] || $q->orWhereIn(DB::raw('lower(leads.email)'), $emails);
            })
            ->orderByDesc('leads.lead_date')->orderByDesc('leads.id')->limit(self::LIST)->get();
    }

    /**
     * @return array{limit: string|null, days: int, exposure: string, available: string|null, status: string}
     */
    private function creditStatus(Ledger $ledger): array
    {
        $limit = $ledger->credit_limit === null || bccomp((string) $ledger->credit_limit, '0', 2) <= 0 ? null : bcadd((string) $ledger->credit_limit, '0', 2);
        $exposure = $this->credit->exposureOf($ledger);

        return [
            'limit' => $limit, 'days' => (int) ($ledger->credit_days ?? 0), 'exposure' => $exposure,
            'available' => $limit === null ? null : bcsub($limit, $exposure, 2),
            'status' => match (true) {
                $limit === null => 'no_limit',
                bccomp($exposure, $limit, 2) > 0 => 'over',
                bccomp(bcmul($exposure, '100', 2), bcmul($limit, '80', 2), 2) >= 0 => 'near',
                default => 'ok',
            },
        ];
    }

    /**
     * @param  Builder<*>  $query
     * @return Collection<int, mixed>
     */
    private function rows(Builder $query, string $table, string $dateColumn): Collection
    {
        return $query->orderByDesc($dateColumn)->orderByDesc("{$table}.id")->limit(self::LIST)->get();
    }

    /**
     * @param  list<int|string>  $leadIds
     * @return array{planned: Collection<int, Followup>, done: Collection<int, Followup>}
     */
    private function followups(User $user, array $leadIds): array
    {
        $base = fn (): Builder => Followup::query()->whereIn('followups.id', $this->agenda->query($user)->select('followups.id'))
            ->where('followups.subject_type', 'lead')->whereIn('followups.subject_id', $leadIds === [] ? [0] : $leadIds)->with('assignee:id,name');

        return [
            'planned' => $base()->where('followups.status', 'pending')->orderBy('followups.scheduled_at')->limit(self::LIST)->get(),
            'done' => $base()->where('followups.status', 'done')->orderByDesc('followups.completed_at')->limit(4)->get(),
        ];
    }

    /**
     * @param  Builder<Lead>|null  $leads
     * @param  Builder<Deal>|null  $deals
     * @param  Builder<Quotation>|null  $quotes
     * @param  Builder<SalesOrder>|null  $orders
     * @return Collection<int, Task>
     */
    private function taskRows(User $user, int $ledgerId, ?Builder $leads, ?Builder $deals, ?Builder $quotes, ?Builder $orders): Collection
    {
        $about = [['ledger', [$ledgerId]]];
        $leads === null || $about[] = ['lead', (clone $leads)->pluck('leads.id')->all()];
        $deals === null || $about[] = ['deal', (clone $deals)->pluck('deals.id')->all()];
        $quotes === null || $about[] = ['quotation', (clone $quotes)->pluck('quotations.id')->all()];
        $orders === null || $about[] = ['sales_order', (clone $orders)->pluck('sales_orders.id')->all()];

        return $this->tasks->restrict(Task::query(), $user)->with('assignee:id,name')
            ->where(function (Builder $q) use ($about) {
                foreach ($about as [$type, $ids]) {
                    $ids === [] || $q->orWhere(fn (Builder $w) => $w->where('tasks.subject_type', $type)->whereIn('tasks.subject_id', $ids));
                }
            })
            ->orderByRaw("case when tasks.status in ('open','in_progress') then 0 else 1 end")->orderByRaw('tasks.due_at is null')->orderBy('tasks.due_at')->orderByDesc('tasks.id')
            ->limit(self::LIST)->get();
    }

    /**
     * @param  list<int|string>  $dealIds
     * @return Collection<int, DealStageEntry>
     */
    private function stageHistory(array $dealIds): Collection
    {
        return $dealIds === [] ? collect() : DealStageEntry::query()->whereIn('deal_id', $dealIds)->with('deal:id,deal_number', 'actor:id,name')->orderByDesc('id')->limit(12)->get();
    }
}
