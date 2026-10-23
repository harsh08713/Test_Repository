<?php

namespace App\Modules\Sales\Http\Livewire\Orders;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Http\Livewire\Concerns\EditsItemRows;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Services\DealService;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\PriceList;
use App\Modules\Masters\Models\State;
use App\Modules\Masters\Services\LedgerLookup;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Services\OrderPricer;
use App\Modules\Sales\Services\QuotationConversionGuard;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Make a sales order — from an approved quotation, from an approved deal, or directly for a regular customer — or change a draft. The screen
 * only holds what was typed: every rule — that the source is approved and open (or the quotation accepted, approved, in date and the latest),
 * that the customer is approved, that each line is an approved item priced from the rate master or copied as quoted, the discounts, the place of
 * supply, the tax and the totals — lives in SalesOrderService / OrderPricer, which check the data again whatever the form sent. What is shown
 * under each line is the pricer's own answer for what is typed so far.
 */
#[Title('Sales order')]
class SalesOrderForm extends Component
{
    use EditsItemRows, ScopesToPeople;

    /** quotation | deal | direct */
    #[Locked]
    public string $sourceType = 'direct';

    #[Locked]
    public ?int $quotationId = null;

    #[Locked]
    public ?int $dealId = null;

    #[Locked]
    public ?int $orderId = null;

    #[Locked]
    public ?int $customerId = null;

    public string $customerLabel = '';

    public string $customerSearch = '';

    public string $locationId = '';

    /** @var array<string, mixed> */
    public array $o = [];

    /** @var list<array<string, mixed>> */
    public array $items = [];

    /** The reason to order from a quotation that is not accepted or has run out (for someone allowed to). */
    public string $exceptionReason = '';

    protected function rowsProperty(): string
    {
        return 'items';
    }

    /** @return array<string, mixed> */
    protected function blankRow(): array
    {
        return [
            'key' => Str::random(6), 'id' => '', 'quotation_item_id' => '', 'deal_item_id' => '', 'locked_item' => false, 'item_id' => '', 'item_label' => '',
            'description' => '', 'unit_id' => '', 'quantity' => '', 'discount_percent' => '0', 'rate_override' => '', 'override_reason' => '', 'search' => '',
        ];
    }

    /** An order's lines may be dropped (a partial order): the source's lines are where it starts, not what it must keep. */
    public function removeRow(int $index): void
    {
        $rows = $this->rows();

        if (isset($rows[$index])) {
            unset($rows[$index]);
            $this->setRows($rows);
        }
    }

    public function mount(?int $quotation = null, ?int $deal = null, ?SalesOrder $editing = null): void
    {
        $str = fn (mixed $v): string => $v === null ? '' : (string) $v;

        // ---- changing a draft ----
        if ($editing?->exists) {
            Gate::authorize('update', $editing);

            $this->orderId = $editing->id;
            $this->sourceType = $editing->source_type;
            $this->quotationId = $editing->quotation_id;
            $this->dealId = $editing->deal_id;
            $this->customerId = (int) $editing->customer_ledger_id;
            $this->customerLabel = trim(($editing->customer->code ?? '').' · '.$editing->party_name);
            $this->locationId = (string) $editing->location_id;
            $this->o = [
                'delivery_date' => $editing->delivery_date->toDateString(), 'customer_po_number' => $str($editing->customer_po_number), 'customer_po_date' => $editing->customer_po_date?->toDateString() ?? '',
                'price_list_id' => $str($editing->price_list_id), 'place_of_supply_state_id' => $str($editing->place_of_supply_state_id),
                'party_name' => $editing->party_name, 'contact_person' => $str($editing->contact_person), 'mobile' => $str($editing->mobile), 'email' => $str($editing->email),
                'bill_line1' => $str($editing->bill_line1), 'bill_line2' => $str($editing->bill_line2), 'bill_city' => $str($editing->bill_city), 'bill_pin_code' => $str($editing->bill_pin_code),
                'customer_gstin' => $str($editing->customer_gstin),
                'ship_line1' => $str($editing->ship_line1), 'ship_line2' => $str($editing->ship_line2), 'ship_city' => $str($editing->ship_city), 'ship_pin_code' => $str($editing->ship_pin_code),
                'terms' => $str($editing->terms), 'remarks' => $str($editing->remarks), 'reprice' => '',
            ];
            $this->items = $editing->items()->with('item:id,code,name')->get()->map(fn (SalesOrderItem $line) => [
                ...$this->blankRow(),
                'id' => (string) $line->id, 'quotation_item_id' => $str($line->quotation_item_id), 'deal_item_id' => $str($line->deal_item_id),
                'item_id' => (string) $line->item_id, 'item_label' => $line->item === null ? $line->item_name : trim($line->item->code.' · '.$line->item->name),
                'unit_id' => (string) $line->unit_id, 'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'),
                'discount_percent' => rtrim(rtrim((string) $line->discount_percent, '0'), '.') ?: '0',
                'rate_override' => $line->rate_overridden ? rtrim(rtrim((string) $line->rate, '0'), '.') : '', 'override_reason' => $str($line->override_reason),
            ])->all();

            return;
        }

        Gate::authorize('create', SalesOrder::class);
        $actor = $this->actor();

        // ---- from an approved quotation ----
        if ($quotation !== null) {
            $q = Quotation::query()->with(['lead', 'deal', 'customer'])->findOrFail($quotation);
            Gate::authorize('work', $q);

            $blocks = app(QuotationConversionGuard::class)->blocks($q);
            $live = app(SalesOrderService::class)->liveOrderFor($q) ?? ($q->deal === null ? null : app(SalesOrderService::class)->liveOrderFor($q->deal));

            if ($live !== null || $blocks['hard'] !== [] || ($blocks['soft'] !== [] && ! $actor->can('quotation.convert_exception'))) {
                session()->flash('status', $live !== null ? "This has already been ordered: sales order {$live->so_number} is live." : implode(' ', [...$blocks['hard'], ...$blocks['soft']]));
                $this->redirect($live !== null && Gate::allows('view', $live) ? route('sales.orders.show', $live) : route('sales.quotations.show', $q), navigate: false);

                return;
            }

            $this->sourceType = 'quotation';
            $this->quotationId = $q->id;
            $this->dealId = $q->deal_id;
            $this->start($q->customer, $q->location_id, $q, $q->deal);
            $this->items = $this->rowsFrom(app(SalesOrderService::class)->startingLines($q, null));

            return;
        }

        // ---- from an approved deal ----
        if ($deal !== null) {
            $d = Deal::query()->with(['lead', 'customer'])->findOrFail($deal);
            Gate::authorize('work', $d);
            $live = app(SalesOrderService::class)->liveOrderFor($d);

            if ($live !== null || ! $d->isReadyForOrder()) {
                session()->flash('status', $live !== null ? "This has already been ordered: sales order {$live->so_number} is live." : ($d->isApproved() ? 'A deal that is '.strtolower($d->stage->label()).' cannot be ordered from.' : 'The deal has to be approved before it can be ordered from.'));
                $this->redirect($live !== null && Gate::allows('view', $live) ? route('sales.orders.show', $live) : route('crm.deals.show', $d), navigate: false);

                return;
            }

            $this->sourceType = 'deal';
            $this->dealId = $d->id;
            $this->start($d->customer, $d->location_id, null, $d);
            $this->items = $this->rowsFrom(app(SalesOrderService::class)->startingLines(null, $d));

            return;
        }

        // ---- directly: the customer and the branch are chosen first ----
        $this->sourceType = 'direct';
        $this->addRow();
    }

    /** Begin an order for a customer: their details, addresses and the standard terms fill the form. */
    private function start(?Ledger $customer, int $locationId, ?Quotation $quotation, ?Deal $deal): void
    {
        $this->locationId = (string) $locationId;

        if ($customer === null) {
            $this->o = ['delivery_date' => now()->addDays(14)->toDateString(), 'party_name' => $deal->party_name ?? '', 'reprice' => ''];

            return;
        }

        $this->customerId = (int) $customer->getKey();
        $this->customerLabel = trim($customer->code.' · '.$customer->name);
        $this->o = [...app(OrderPricer::class)->defaults($customer, $quotation, $deal), 'reprice' => ''];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function rowsFrom(array $lines): array
    {
        $str = fn (mixed $v): string => $v === null ? '' : (string) $v;

        return array_map(fn (array $row) => [
            ...$this->blankRow(),
            'quotation_item_id' => $str($row['quotation_item_id']), 'deal_item_id' => $str($row['deal_item_id']), 'item_id' => $str($row['item_id']), 'item_label' => $row['item_label'],
            'unit_id' => $str($row['unit_id']), 'quantity' => rtrim(rtrim((string) $row['quantity'], '0'), '.'), 'discount_percent' => rtrim(rtrim((string) $row['discount_percent'], '0'), '.') ?: '0',
            'rate_override' => $str($row['rate_override']), 'override_reason' => $str($row['override_reason']),
        ], $lines);
    }

    // ---- the customer (a direct order, or a source with no customer ledger) --------------------------------------

    public function pickCustomer(int $id, OrderPricer $pricer): void
    {
        if ($this->orderId !== null || ($this->sourceType !== 'direct' && $this->customerId !== null)) {
            return;
        }

        $customer = app(LedgerLookup::class)->usable($id, LedgerType::Customer);

        if ($customer === null) {
            return;
        }

        $this->customerId = (int) $customer->getKey();
        $this->customerLabel = trim($customer->code.' · '.$customer->name);
        $this->customerSearch = '';
        $this->o = [...$pricer->defaults($customer, null, null), 'reprice' => ''];
    }

    public function clearCustomer(): void
    {
        if ($this->orderId === null && ($this->sourceType === 'direct' || $this->customerId === null)) {
            $this->customerId = null;
            $this->customerLabel = '';
            $this->customerSearch = '';
        }
    }

    // ---- save -----------------------------------------------------------------------------------------------

    public function save(SalesOrderService $service): void
    {
        $user = $this->actor();
        $this->resetErrorBag();

        // Drop the rows nobody filled in, so the service's row numbers are this form's row numbers.
        $this->items = array_values(array_filter($this->items, fn (array $row) => trim((string) ($row['id'] ?? '')) !== '' || trim((string) ($row['quotation_item_id'] ?? '')) !== ''
            || trim((string) ($row['deal_item_id'] ?? '')) !== '' || trim((string) ($row['item_id'] ?? '')) !== '' || trim((string) ($row['quantity'] ?? '')) !== ''));

        $input = ['order' => $this->o, 'items' => $this->items, 'customer_ledger_id' => $this->customerId === null ? '' : (string) $this->customerId];

        try {
            if ($this->orderId !== null) {
                $order = SalesOrder::query()->findOrFail($this->orderId);
                Gate::authorize('update', $order);
                $saved = $service->update($order, $input, $user);
            } else {
                Gate::authorize('create', SalesOrder::class);

                $saved = match ($this->sourceType) {
                    'quotation' => $service->createFromQuotation(Quotation::query()->findOrFail($this->quotationId), $input, $user, trim($this->exceptionReason) === '' ? null : $this->exceptionReason),
                    'deal' => $service->createFromDeal(Deal::query()->findOrFail($this->dealId), $input, $user),
                    default => $this->createDirect($service, $input, $user),
                };
            }
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', $this->orderId !== null ? 'Order saved.' : "Sales order {$saved->so_number} made. Check it, then send it for approval.");

        $this->redirectRoute('sales.orders.show', $saved, navigate: false);
    }

    /** @param  array<string, mixed>  $input */
    private function createDirect(SalesOrderService $service, array $input, User $user): SalesOrder
    {
        if ($this->customerId === null) {
            throw new FieldRuleException('Choose the customer first.');
        }

        if (! ctype_digit($this->locationId)) {
            throw new FieldRuleException('Choose the branch the order is for.');
        }

        return $service->createDirect($this->customerId, (int) $this->locationId, $input, $user);
    }

    // ---- what the person sees while typing -----------------------------------------------------------------

    /**
     * The pricer's answer for what is typed so far — see QuotationForm::preview(). Nothing is saved or audited.
     *
     * @return array{lines: array<int, array<string, mixed>>, totals: array<string, string>|null, notes: array<int, string>, supply: string|null}
     */
    private function preview(): array
    {
        $none = ['lines' => [], 'totals' => null, 'notes' => [], 'supply' => null];
        $rows = array_filter($this->items, fn (array $row) => trim((string) ($row['item_id'] ?? '')) !== '' && trim((string) ($row['quantity'] ?? '')) !== '');

        if ($rows === [] || $this->customerId === null || ! ctype_digit($this->locationId)) {
            return $none;
        }

        $editing = $this->orderId !== null ? SalesOrder::query()->find($this->orderId) : null;
        $quotation = $this->orderId === null && $this->quotationId !== null ? Quotation::query()->find($this->quotationId) : null;
        $deal = $this->orderId === null && $this->dealId !== null && $this->quotationId === null ? Deal::query()->find($this->dealId) : null;
        $policy = $editing->pricing_policy ?? ($quotation !== null && (string) app(SettingsService::class)->get('sales_order.quotation_pricing', (int) $quotation->company_id) !== 'rates' ? 'copy' : 'rates');
        $customer = Ledger::query()->find($this->customerId);

        if ($customer === null) {
            return $none;
        }

        $run = app(OrderPricer::class)->preview(['order' => $this->o, 'items' => $this->items], [
            'editing' => $editing, 'quotation' => $quotation, 'deal' => $deal, 'policy' => $policy, 'location_id' => (int) $this->locationId, 'company_id' => (int) $customer->company_id,
        ], $this->actor());

        $notes = [];

        foreach ($run['errors'] as $key => $messages) {
            if (preg_match('/^items\.(\d+)\.item_id$/', $key, $m) === 1) {
                $notes[(int) $m[1]] = $messages[0];
            }
        }

        return [
            'lines' => $run['lines'], 'totals' => $run['errors'] === [] ? $run['totals'] : null, 'notes' => $notes,
            'supply' => isset($run['order']['supply_type']) ? (string) $run['order']['supply_type'] : null,
        ];
    }

    public function render(LedgerLookup $ledgers): View
    {
        $saved = $this->orderId === null ? [] : SalesOrderItem::query()->where('sales_order_id', $this->orderId)->pluck('item_id')->map(fn ($v) => (int) $v)->all();
        $help = $this->rowHelp($saved);
        $term = trim($this->customerSearch);
        $quotation = $this->quotationId === null ? null : Quotation::query()->find($this->quotationId);
        $deal = $this->dealId === null ? null : Deal::query()->find($this->dealId);
        $soft = $quotation !== null && $this->orderId === null ? app(QuotationConversionGuard::class)->blocks($quotation)['soft'] : [];

        return view('sales.orders.form', [
            'editing' => $this->orderId !== null,
            'orderNumber' => $this->orderId === null ? null : SalesOrder::query()->whereKey($this->orderId)->value('so_number'),
            'quotation' => $quotation,
            'deal' => $deal,
            'customers' => $this->orderId === null && $this->customerId === null && $term !== '' ? $ledgers->search(LedgerType::Customer, $term, self::MAX_SEARCH_RESULTS)->map(fn (Ledger $c) => ['id' => (int) $c->getKey(), 'label' => trim($c->code.' · '.$c->name)])->all() : [],
            'branches' => $this->sourceType === 'direct' && $this->orderId === null ? Location::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all() : [],
            'states' => State::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'gst_code']),
            'priceLists' => PriceList::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'itemUnits' => $help['itemUnits'],
            'results' => $help['results'],
            'maxLines' => DealService::MAX_LINES,
            'preview' => $this->preview(),
            'canOverride' => $this->actor()->can('rate.override'),
            'softBlocks' => $soft,
            'today' => now()->toDateString(),
            'backUrl' => $this->orderId !== null ? route('sales.orders.show', $this->orderId)
                : ($quotation !== null ? route('sales.quotations.show', $quotation) : ($deal !== null ? route('crm.deals.show', $deal) : route('sales.orders.index'))),
        ]);
    }
}
