<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\DealAccess;
use App\Modules\CRM\Services\DealService;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Services\LedgerLookup;
use App\Modules\Masters\Services\RateService;
use App\Modules\Sales\Enums\SalesOrderState;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Models\SalesOrderStateEntry;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\NumberSeriesService;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes and moves sales orders. The single gate for order data — the screens come through here — so the rules are the same whichever way. It
 * does not decide who may (SalesOrderPolicy does), but it checks on the server what a policy cannot know: the branch against the *actor*,
 * whether the source is still approved and open, the customer, and what state the order is in.
 *
 *  - **Making.** Three sources, one order each: an **approved quotation** (`createFromQuotation`: cleared by QuotationConversionGuard — accepted,
 *    approved, the latest revision, in date — or an audited exception), an **approved deal** (`createFromDeal`, no quotation needed) and a
 *    **direct order** for a regular customer (`createDirect`: a customer with at least `sales_order.direct_min_orders` approved orders). The
 *    customer must be an approved, active customer ledger. A quotation, and a deal, can have one live order at a time (the lock on the source
 *    and the unique keys on `live_quotation_id` / `live_deal_id`); a cancelled order frees them. The number is taken first, in the same
 *    transaction.
 *  - **Prices** are OrderPricer's: copied from the quotation as quoted (company setting) or looked up in the rate master; tax by place of supply.
 *  - **Credit.** The check (CreditCheck) is worked out on every save and again at submission; an order over the limit in a company that blocks
 *    cannot be submitted until someone holding `credit_limit.override` allows it, with a reason (`overrideCredit`, audited).
 *  - **Approval** is the shared workflow; approving it makes the invoice (InvoiceGenerator) and, for an order from a deal, wins the deal.
 *  - **Cancelling** (`cancel`) withdraws the order and, with it, its invoice; it frees the quotation and deal to be ordered again.
 *
 * Input: see OrderPricer.
 */
final class SalesOrderService
{
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly NumberSeriesService $numbers,
        private readonly OrderPricer $pricer,
        private readonly RateService $rates,
        private readonly QuotationConversionGuard $guard,
        private readonly QuotationAccess $quotationAccess,
        private readonly DealAccess $dealAccess,
        private readonly DealService $deals,
        private readonly LeadService $leads,
        private readonly LedgerLookup $ledgers,
        private readonly SettingsService $settings,
        private readonly CreditCheck $credit,
    ) {}

    // ---- reading ------------------------------------------------------------------------------------------------

    /**
     * The lines a new order starts with, from its source, as the form starts with them.
     *
     * @return list<array<string, mixed>>
     */
    public function startingLines(?Quotation $quotation, ?Deal $deal): array
    {
        if ($quotation !== null) {
            return $quotation->items()->with(['item:id,code,name'])->get()->map(fn ($line) => [
                'quotation_item_id' => $line->getKey(), 'deal_item_id' => null, 'item_id' => $line->item_id, 'item_label' => $line->item === null ? $line->item_name : trim($line->item->code.' · '.$line->item->name),
                'unit_id' => $line->unit_id, 'quantity' => (string) $line->quantity, 'discount_percent' => (string) $line->discount_percent,
                'rate_override' => $line->rate_overridden ? (string) $line->rate : '', 'override_reason' => (string) $line->override_reason,
            ])->all();
        }

        return $deal === null ? [] : $deal->items()->with(['item:id,code,name'])->get()->map(fn ($line) => [
            'quotation_item_id' => null, 'deal_item_id' => $line->getKey(), 'item_id' => $line->item_id, 'item_label' => $line->item === null ? '' : trim($line->item->code.' · '.$line->item->name),
            'unit_id' => $line->unit_id, 'quantity' => (string) $line->quantity, 'discount_percent' => '0', 'rate_override' => '', 'override_reason' => '',
        ])->all();
    }

    /**
     * The order that is live (not cancelled) for a quotation or a deal, whoever made it. $lock asks for the latest committed answer and holds it
     * (a locking read): inside a transaction a plain read would miss an order somebody committed while this one waited for the source's row lock.
     */
    public function liveOrderFor(Quotation|Deal $source, bool $lock = false): ?SalesOrder
    {
        $column = $source instanceof Quotation ? 'quotation_id' : 'deal_id';

        return SalesOrder::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $source->company_id)->where($column, $source->getKey())
            ->where('state', '<>', SalesOrderState::Cancelled->value)->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    /** Has this customer at least `sales_order.direct_min_orders` approved, uncancelled orders — is it a regular customer? */
    public function isRegularCustomer(Ledger $customer): bool
    {
        $needed = max(0, (int) $this->settings->get('sales_order.direct_min_orders', (int) $customer->company_id));

        return $needed === 0 || $this->orderCount($customer) >= $needed;
    }

    public function orderCount(Ledger $customer): int
    {
        return SalesOrder::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $customer->company_id)->where('customer_ledger_id', $customer->getKey())
            ->where('approval_status', 'approved')->where('state', '<>', SalesOrderState::Cancelled->value)->count();
    }

    // ---- making -------------------------------------------------------------------------------------------------

    /**
     * Make an order from an approved quotation. $exceptionReason is the reason someone holding `quotation.convert_exception` gives to order
     * from a quotation that has not been accepted or has run past its date (audited by the guard).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function createFromQuotation(Quotation $quotation, array $input, User $actor, ?string $exceptionReason = null): SalesOrder
    {
        return DB::transaction(function () use ($quotation, $input, $actor, $exceptionReason) {
            $number = $this->numbers->next(DocumentType::SalesOrder, (int) $quotation->location_id, now());

            $fresh = Quotation::query()->lockForUpdate()->findOrFail($quotation->getKey());
            $this->assertBranch((int) $fresh->company_id, (int) $fresh->location_id, $actor);

            if (! $this->quotationAccess->works($actor, $fresh)) {
                throw new FieldRuleException('This quotation is not one of yours to order from.');
            }

            $note = $this->guard->assertConvertible($fresh, $actor, $exceptionReason);
            $deal = $fresh->deal_id === null ? null : Deal::query()->lockForUpdate()->findOrFail($fresh->deal_id);
            $this->assertNoLiveOrder($fresh, $deal);

            $customer = $this->customer($fresh->customer_ledger_id ?? $this->submittedCustomer($input), 'quotation');
            $policy = (string) $this->settings->get('sales_order.quotation_pricing', (int) $fresh->company_id) === 'rates' ? 'rates' : 'copy';
            $lead = Lead::query()->findOrFail($fresh->lead_id);

            return $this->persist($number, 'quotation', $fresh, $deal, $lead, $customer, (int) $fresh->location_id, (int) $lead->assigned_to, $policy, $input, $actor,
                "Made from quotation {$fresh->displayNumber()}".($note === null ? '' : " by exception: {$note}"));
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Make an order from an approved, open deal — no quotation needed. The deal's lines are priced from the rate master (the rate on a deal is
     * only an expectation).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function createFromDeal(Deal $deal, array $input, User $actor): SalesOrder
    {
        return DB::transaction(function () use ($deal, $input, $actor) {
            $number = $this->numbers->next(DocumentType::SalesOrder, (int) $deal->location_id, now());

            $fresh = Deal::query()->lockForUpdate()->findOrFail($deal->getKey());
            $this->assertBranch((int) $fresh->company_id, (int) $fresh->location_id, $actor);

            if (! $this->dealAccess->works($actor, $fresh)) {
                throw new FieldRuleException('This deal is not one of yours to order from.');
            }

            if (! $fresh->isApproved()) {
                throw new FieldRuleException('The deal has not been approved, so nothing can be ordered from it yet.');
            }

            if (! $fresh->isOpen()) {
                throw new FieldRuleException('A deal that is '.strtolower($fresh->stage->label()).' cannot be ordered from.');
            }

            $this->assertNoLiveOrder(null, $fresh);
            $customer = $this->customer($fresh->customer_ledger_id ?? $this->submittedCustomer($input), 'deal');
            $lead = Lead::query()->findOrFail($fresh->lead_id);

            return $this->persist($number, 'deal', null, $fresh, $lead, $customer, (int) $fresh->location_id, (int) $fresh->assigned_to, 'rates', $input, $actor, "Made from deal {$fresh->deal_number}");
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Make an order straight for a regular customer, in one of the actor's branches, credited to the actor.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function createDirect(int $customerLedgerId, int $locationId, array $input, User $actor): SalesOrder
    {
        $customer = $this->customer($customerLedgerId, 'direct');

        return DB::transaction(function () use ($customer, $locationId, $input, $actor) {
            $number = $this->numbers->next(DocumentType::SalesOrder, $locationId, now());

            $this->assertBranch((int) $customer->company_id, $locationId, $actor);

            if (! $this->isRegularCustomer($customer)) {
                $needed = (int) $this->settings->get('sales_order.direct_min_orders', (int) $customer->company_id);

                throw new FieldRuleException("{$customer->name} is not a regular customer yet (it needs {$needed} approved ".($needed === 1 ? 'order' : 'orders').' first): make the order from a deal or a quotation.');
            }

            return $this->persist($number, 'direct', null, null, null, $customer, $locationId, (int) $actor->getKey(), 'rates', $input, $actor, 'Made directly for a regular customer');
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function persist(string $number, string $type, ?Quotation $quotation, ?Deal $deal, ?Lead $lead, Ledger $customer, int $locationId, int $ownerId, string $policy, array $input, User $actor, string $note): SalesOrder
    {
        $data = $this->pricer->price($input, [
            'editing' => null, 'quotation' => $quotation, 'deal' => $deal, 'policy' => $policy, 'location_id' => $locationId, 'company_id' => (int) $customer->company_id,
        ], $actor);

        $order = new SalesOrder;
        $order->fill([
            'delivery_date' => $data['order']['delivery_date'], 'customer_po_number' => $data['order']['customer_po_number'], 'customer_po_date' => $data['order']['customer_po_date'],
            'terms' => $data['order']['terms'], 'remarks' => $data['order']['remarks'],
        ]);
        $order->forceFill([
            'company_id' => (int) $customer->company_id, 'location_id' => $locationId, 'so_number' => $number, 'order_date' => now()->toDateString(),
            'source_type' => $type, 'quotation_id' => $quotation?->getKey(), 'deal_id' => $deal?->getKey(), 'lead_id' => $lead?->getKey(),
            'customer_ledger_id' => $customer->getKey(), 'assigned_to' => $ownerId, 'state' => SalesOrderState::Open->value, 'pricing_policy' => $policy,
            'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
        ]);
        $this->applyHeader($order, $data);

        try {
            $order->save();
        } catch (UniqueConstraintViolationException) {
            // the lock on the source makes this unreachable in practice; the unique key is what makes it impossible
            throw new FieldRuleException('This '.($quotation !== null ? 'quotation' : 'deal').' already has a sales order.');
        }

        $this->syncLines($order, $data['lines'], $actor);
        $this->recordState($order, null, SalesOrderState::Open, $actor, $note);
        $this->credit->refresh($order);

        return $order->refresh();
    }

    // ---- changing -----------------------------------------------------------------------------------------------

    /**
     * Change an open draft (or a returned order): everything is checked and priced again as on creation.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function update(SalesOrder $order, array $input, User $actor): SalesOrder
    {
        return DB::transaction(function () use ($order, $input, $actor) {
            $fresh = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $this->assertBranch((int) $fresh->company_id, (int) $fresh->location_id, $actor);

            if (! $fresh->isContentEditable()) {
                throw new FieldRuleException($this->lockedBecause($fresh));
            }

            $data = $this->pricer->price($input, [
                'editing' => $fresh, 'quotation' => null, 'deal' => null, 'policy' => $fresh->pricing_policy, 'location_id' => (int) $fresh->location_id, 'company_id' => (int) $fresh->company_id,
            ], $actor);

            $fresh->fill([
                'delivery_date' => $data['order']['delivery_date'], 'customer_po_number' => $data['order']['customer_po_number'], 'customer_po_date' => $data['order']['customer_po_date'],
                'terms' => $data['order']['terms'], 'remarks' => $data['order']['remarks'],
            ]);
            $fresh->forceFill(['updated_by' => $actor->getKey()]);

            // an override was given for an order of a certain size: a bigger order needs it given again
            if ($fresh->credit_override_reason !== null && bccomp($data['totals']['grand_total'], (string) $fresh->grand_total, 2) > 0) {
                $fresh->forceFill(['credit_override_reason' => null, 'credit_override_by' => null]);
            }

            $this->applyHeader($fresh, $data);
            $fresh->save();

            $this->syncLines($fresh, $data['lines'], $actor);
            $this->credit->refresh($fresh);

            return $fresh->refresh();
        });
    }

    private function lockedBecause(SalesOrder $order): string
    {
        return match (true) {
            $order->isCancelled() => 'This order was cancelled and can no longer be changed.',
            $order->state === SalesOrderState::Invoiced => 'This order has been invoiced and can no longer be changed.',
            $order->isPendingApproval() => 'This order is waiting for approval, so it is locked.',
            $order->isApproved() => 'This order is approved, so it is locked. Re-open it, with a reason, to change it.',
            default => 'This order cannot be changed.',
        };
    }

    // ---- credit -------------------------------------------------------------------------------------------------

    /**
     * Allow an order that takes the customer over their credit limit in a company that blocks it. Needs `credit_limit.override` and a reason;
     * audited. A bigger order later needs it given again.
     *
     * @throws AuthorizationException
     * @throws FieldRuleException
     * @throws ValidationException
     */
    public function overrideCredit(SalesOrder $order, string $reason, User $actor): SalesOrder
    {
        if (! $actor->can('credit_limit.override')) {
            throw new AuthorizationException('You are not allowed to override a customer’s credit limit.');
        }

        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Please say why the order may go over the credit limit (3 to 500 characters).']);
        }

        return DB::transaction(function () use ($order, $reason, $actor) {
            $fresh = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $this->assertBranch((int) $fresh->company_id, (int) $fresh->location_id, $actor);

            if (! $fresh->isContentEditable()) {
                throw new FieldRuleException($this->lockedBecause($fresh));
            }

            if ($this->credit->evaluate($fresh)['result']->value !== 'blocked') {
                throw new FieldRuleException('This order is not over a credit limit that blocks, so there is nothing to override.');
            }

            $fresh->forceFill(['credit_override_reason' => $reason, 'credit_override_by' => $actor->getKey()])->save();
            $result = $this->credit->refresh($fresh);

            activity('sales_orders')->causedBy($actor)->performedOn($fresh)->event('credit_overridden')
                ->withProperties(['reason' => $reason, 'limit' => $fresh->credit_limit, 'exposure' => $fresh->credit_exposure, 'order_total' => (string) $fresh->grand_total, 'result' => $result->value])
                ->log('credit_overridden');

            return $fresh->refresh();
        });
    }

    // ---- cancelling ---------------------------------------------------------------------------------------------

    /**
     * Withdraw an order, and the invoice made from it. A reason is required. The quotation and the deal it came from can be ordered again.
     *
     * @throws FieldRuleException
     * @throws ValidationException
     */
    public function cancel(SalesOrder $order, string $reason, User $actor): SalesOrder
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Please say why the order is being cancelled (3 to 500 characters).']);
        }

        return DB::transaction(function () use ($order, $reason, $actor) {
            $fresh = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $this->assertBranch((int) $fresh->company_id, (int) $fresh->location_id, $actor);

            if ($fresh->isCancelled()) {
                throw new FieldRuleException('This order has already been cancelled.');
            }

            if ($fresh->isPendingApproval()) {
                throw new FieldRuleException('This order is waiting for approval: wait for the decision before cancelling it.');
            }

            // An invoice that has returns or credit notes against it stays: they are part of the books. (A draft or rejected one does not count.)
            $returns = SalesReturn::query()->whereIn('approval_status', ['pending', 'approved'])
                ->whereIn('sales_invoice_id', SalesInvoice::withoutGlobalScopes(Tenancy::SCOPES)->where('sales_order_id', $fresh->getKey())->select('id'))->count();

            if ($returns > 0) {
                throw new FieldRuleException('Returns or credit notes have been made against the invoice of this order, so it cannot be cancelled. Deal with them first.');
            }

            $from = $fresh->state;
            $fresh->forceFill(['state' => SalesOrderState::Cancelled->value, 'cancelled_at' => now(), 'cancel_reason' => $reason, 'cancelled_by' => $actor->getKey(), 'updated_by' => $actor->getKey()])->save();

            foreach (SalesInvoice::withoutGlobalScopes(Tenancy::SCOPES)->where('sales_order_id', $fresh->getKey())->where('status', SalesInvoice::ISSUED)->lockForUpdate()->get() as $invoice) {
                $invoice->forceFill(['status' => SalesInvoice::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => "Order {$fresh->so_number} was cancelled: {$reason}", 'updated_by' => $actor->getKey()])->save();
            }

            $this->recordState($fresh, $from, SalesOrderState::Cancelled, $actor, $reason);

            return $fresh->refresh();
        });
    }

    // ---- what approval does to the source ---------------------------------------------------------------------------

    /**
     * An order from a deal (or from a quotation of one) wins the deal, and the deal its lead; an order from a quotation made straight from an
     * inquiry wins that inquiry. Done once the order is approved; a source that can no longer move (lost, already won) is left as it is.
     */
    public function sourceWon(SalesOrder $order, ?User $actor): void
    {
        if ($order->deal_id !== null) {
            $deal = Deal::query()->find($order->deal_id);

            if ($deal !== null && $deal->isApproved() && in_array($deal->stage, [DealStage::Discovery, DealStage::Negotiation, DealStage::Quotation], true)) {
                $this->deals->setStageBySystem($deal, DealStage::Won, $actor, "Sales order {$order->so_number} approved");
            }

            return;
        }

        $lead = $order->lead_id === null ? null : Lead::query()->find($order->lead_id);

        if ($lead !== null && $lead->isOpen() && $lead->stage !== LeadStage::Won) {
            $this->leads->setStageBySystem($lead, LeadStage::Won, $actor, "Sales order {$order->so_number} approved");
        }
    }

    // ---- checks -------------------------------------------------------------------------------------------------

    /** The branch is asked about the *actor* explicitly, so the answer does not depend on who (if anyone) is signed in around this call. @throws FieldRuleException */
    private function assertBranch(int $companyId, int $locationId, User $actor): void
    {
        $reach = $this->tenant->accessibleLocationIdsFor($actor, $companyId);

        if ($reach !== null && ! in_array($locationId, $reach, true)) {
            throw new FieldRuleException('This is in a branch you do not work in.');
        }
    }

    /** @throws FieldRuleException */
    private function assertNoLiveOrder(?Quotation $quotation, ?Deal $deal): void
    {
        $existing = ($quotation !== null ? $this->liveOrderFor($quotation, lock: true) : null) ?? ($deal !== null ? $this->liveOrderFor($deal, lock: true) : null);

        if ($existing !== null) {
            throw new FieldRuleException("This has already been ordered: sales order {$existing->so_number} is live. Cancel it first to order again.");
        }
    }

    /**
     * The customer to sell to: an approved, active customer ledger of the company.
     *
     * @throws FieldRuleException
     */
    private function customer(int|string|null $id, string $source): Ledger
    {
        $customer = $id === null || $id === '' ? null : $this->ledgers->usable((int) $id, LedgerType::Customer);

        if ($customer === null) {
            throw new FieldRuleException($source === 'direct'
                ? 'Choose a customer that is approved and active.'
                : 'There is no approved customer to sell to: link the customer ledger first, or choose one that is approved and active.');
        }

        return $customer;
    }

    /** @param  array<string, mixed>  $input */
    private function submittedCustomer(array $input): ?string
    {
        $id = trim((string) ($input['customer_ledger_id'] ?? ''));

        return $id === '' ? null : $id;
    }

    // ---- persistence --------------------------------------------------------------------------------------------

    /** @param  array{order: array<string, mixed>, lines: list<array<string, mixed>>, totals: array<string, string>}  $data */
    private function applyHeader(SalesOrder $order, array $data): void
    {
        $h = $data['order'];
        $t = $data['totals'];

        $order->forceFill([
            'price_list_id' => $h['price_list_id'], 'price_date' => $h['price_date'],
            'party_name' => $h['party_name'], 'contact_person' => $h['contact_person'], 'mobile' => $h['mobile'], 'email' => $h['email'],
            'bill_line1' => $h['bill_line1'], 'bill_line2' => $h['bill_line2'], 'bill_city' => $h['bill_city'], 'bill_pin_code' => $h['bill_pin_code'], 'customer_gstin' => $h['customer_gstin'],
            'ship_line1' => $h['ship_line1'], 'ship_line2' => $h['ship_line2'], 'ship_city' => $h['ship_city'], 'ship_pin_code' => $h['ship_pin_code'],
            'place_of_supply_state_id' => $h['place_of_supply_state_id'], 'supply_type' => $h['supply_type'],
            'supplier_state_code' => $h['supplier_state_code'], 'supplier_gstin' => $h['supplier_gstin'],
            'gross_total' => $t['gross'], 'discount_total' => $t['discount'], 'taxable_total' => $t['taxable'], 'cgst_total' => $t['cgst'],
            'sgst_total' => $t['sgst'], 'igst_total' => $t['igst'], 'round_off' => $t['round_off'], 'grand_total' => $t['grand_total'],
        ]);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function syncLines(SalesOrder $order, array $lines, User $actor): void
    {
        $existing = $order->items()->get()->keyBy('id');
        $kept = [];

        foreach ($lines as $n => $row) {
            $model = $row['id'] !== null ? $existing->get((int) $row['id']) : null;
            $model ??= new SalesOrderItem;
            $f = $row['figures'];

            $model->forceFill([
                'line_no' => $n + 1, 'quotation_item_id' => $row['quotation_item_id'], 'deal_item_id' => $row['deal_item_id'],
                'item_id' => $row['item_id'], 'unit_id' => $row['unit_id'], 'item_name' => $row['item_name'], 'hsn_code' => $row['hsn_code'],
                'quantity' => $row['quantity'], 'rate_id' => $row['rate_id'], 'rate_source' => $row['rate_source'], 'list_rate' => $row['list_rate'],
                'rate' => $row['rate'], 'rate_overridden' => $row['rate_overridden'], 'override_reason' => $row['override_reason'],
                'discount_percent' => $row['discount_percent'], 'gross_amount' => $f['gross'], 'discount_amount' => $f['discount'],
                'taxable_amount' => $f['taxable'], 'gst_rate' => $row['gst_rate'], 'cgst_amount' => $f['cgst'], 'sgst_amount' => $f['sgst'],
                'igst_amount' => $f['igst'], 'line_total' => $f['total'], 'updated_by' => $actor->getKey(),
            ]);

            if (! $model->exists) {
                $model->forceFill(['company_id' => (int) $order->company_id, 'sales_order_id' => $order->getKey(), 'created_by' => $actor->getKey()]);
            }

            $model->save();
            $kept[] = $model->getKey();

            // A rate somebody changed is recorded on the rate master's audit trail, against this order.
            if ($row['audit'] !== null) {
                $this->rates->recordOverride($row['audit']['quote'], $row['audit']['rate'], $row['audit']['reason'], $actor, $order);
            }
        }

        foreach ($existing as $id => $line) {
            if (! in_array($id, $kept, true)) {
                $line->forceDelete();
            }
        }
    }

    private function recordState(SalesOrder $order, ?SalesOrderState $from, SalesOrderState $to, ?User $actor, ?string $comment): void
    {
        $entry = new SalesOrderStateEntry;
        $entry->forceFill([
            'company_id' => (int) $order->company_id, 'sales_order_id' => $order->getKey(), 'from_state' => $from?->value, 'to_state' => $to->value,
            'comment' => $comment === null ? null : mb_substr($comment, 0, 1000), 'actor_id' => $actor?->getKey(), 'created_at' => now(),
        ])->save();
    }
}
