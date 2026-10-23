<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Services\DealService;
use App\Modules\Masters\Exceptions\RateNotFoundException;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\LedgerAddress;
use App\Modules\Masters\Models\PriceList;
use App\Modules\Masters\Models\Rate;
use App\Modules\Masters\Models\State;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Masters\Services\RateService;
use App\Modules\Masters\Services\UomConverter;
use App\Modules\Masters\Support\RateQuote;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\QuotationItem;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Turns what a person typed into a priced, taxed sales order — and saves nothing (SalesOrderService saves; the order form's live preview asks
 * for the same answer, so the figure shown is the figure that will be stored). It does for orders what QuotationPricer does for quotations,
 * with the order's own policy on prices:
 *
 *  - **From a quotation** the default policy is **copy**: a line that still has the item and unit it was quoted with keeps the quotation's
 *    price, discount-free rate, GST rate and rate-override note exactly (the customer was promised that price, and an override already
 *    audited is not audited twice); only the quantity and the discount can be adjusted. Company setting `sales_order.quotation_pricing =
 *    rates` looks every line up again instead. A line the quotation did not have, or whose item or unit changed, is looked up.
 *  - **From a deal or direct** every line is looked up in the rate master (RateService, as of the order's price date, in its price list).
 *  - **On an order being edited**, an unchanged line keeps the price it was saved with unless "refresh rates" is asked for.
 *  - A changed rate needs `rate.override`, a reason, and leaves an audit entry on the rate (recorded by SalesOrderService once the order exists).
 *  - Tax per line and totals are TaxService's, by the place of supply.
 *
 * Input: ['order' => [delivery_date, customer_po_number, customer_po_date, price_list_id, place_of_supply_state_id, party_name, contact_person,
 *         mobile, email, bill_line1…, customer_gstin, ship_line1…, terms, remarks, reprice?],
 *         'items' => [[id?, quotation_item_id?, deal_item_id?, item_id, unit_id, quantity, discount_percent, rate_override?, override_reason?], …]]
 */
final class OrderPricer
{
    private const MAX_AMOUNT = '9999999999999.99';

    public function __construct(
        private readonly ItemLookup $items,
        private readonly UomConverter $uoms,
        private readonly RateService $rates,
        private readonly TaxService $tax,
        private readonly SettingsService $settings,
    ) {}

    // ---- what a new order starts with ---------------------------------------------------------------------------

    /**
     * The header a new order starts with, from its source: the quotation (copied as it was quoted), else the deal, else the customer's
     * ledger; the customer's default billing and shipping addresses; the company's standard delivery time and terms.
     *
     * @param  Ledger  $customer
     * @return array<string, string>
     */
    public function defaults($customer, ?Quotation $quotation, ?Deal $deal): array
    {
        $addresses = $customer->addresses()->where('status', 'active')->get();
        $bill = $this->pick($addresses, 'is_billing', 'is_default_billing');
        $ship = $this->pick($addresses, 'is_shipping', 'is_default_shipping') ?? $bill;
        $companyId = (int) $customer->company_id;
        $days = max(0, (int) $this->settings->get('sales_order.delivery_days', $companyId));

        $q = $quotation;

        return [
            'delivery_date' => now()->addDays($days)->toDateString(),
            'customer_po_number' => '',
            'customer_po_date' => '',
            'price_list_id' => (string) ($q->price_list_id ?? ''),
            'place_of_supply_state_id' => (string) ($q->place_of_supply_state_id ?? $ship->state_id ?? $bill->state_id ?? ''),
            'party_name' => (string) ($q->party_name ?? $customer->name),
            'contact_person' => (string) ($q->contact_person ?? $deal->contact_person ?? ''),
            'mobile' => (string) ($q->mobile ?? $deal->mobile ?? ''),
            'email' => (string) ($q->email ?? $deal->email ?? $customer->email ?? ''),
            'bill_line1' => (string) ($q->bill_line1 ?? $bill->line1 ?? ''),
            'bill_line2' => (string) ($q->bill_line2 ?? $bill->line2 ?? ''),
            'bill_city' => (string) ($q->bill_city ?? $bill->city ?? ''),
            'bill_pin_code' => (string) ($q->bill_pin_code ?? $bill->pin_code ?? ''),
            'customer_gstin' => (string) ($q->customer_gstin ?? $bill->gstin ?? $customer->gstin ?? ''),
            'ship_line1' => (string) ($ship->line1 ?? ''),
            'ship_line2' => (string) ($ship->line2 ?? ''),
            'ship_city' => (string) ($ship->city ?? ''),
            'ship_pin_code' => (string) ($ship->pin_code ?? ''),
            'terms' => (string) ($q->terms ?? $this->settings->get('quotation.default_terms', $companyId)),
            'remarks' => '',
        ];
    }

    /** @param  iterable<LedgerAddress>  $addresses */
    private function pick(iterable $addresses, string $kind, string $default): ?LedgerAddress
    {
        $best = null;

        foreach ($addresses as $address) {
            if (! $address->{$kind}) {
                continue;
            }

            if ($address->{$default}) {
                return $address;
            }

            $best ??= $address;
        }

        return $best;
    }

    // ---- pricing ------------------------------------------------------------------------------------------------

    /**
     * The priced order, or a ValidationException saying what is wrong with what was typed.
     *
     * @param  array<string, mixed>  $input
     * @param  array{editing: ?SalesOrder, quotation: ?Quotation, deal: ?Deal, policy: string, location_id: int, company_id: int}  $ctx
     * @return array{order: array<string, mixed>, lines: list<array<string, mixed>>, totals: array<string, string>}
     *
     * @throws ValidationException
     */
    public function price(array $input, array $ctx, User $actor): array
    {
        $run = $this->run($input, $ctx, $actor);

        if ($run['errors'] !== []) {
            throw ValidationException::withMessages($run['errors']);
        }

        return ['order' => $run['order'], 'lines' => array_values($run['lines']), 'totals' => $run['totals']];
    }

    /**
     * What can be worked out so far, for the screen: the lines that could be priced (keyed by their row), the totals once everything is right,
     * and what is wrong, by field. Nothing is saved or audited.
     *
     * @param  array<string, mixed>  $input
     * @param  array{editing: ?SalesOrder, quotation: ?Quotation, deal: ?Deal, policy: string, location_id: int, company_id: int}  $ctx
     * @return array{order: array<string, mixed>, lines: array<int, array<string, mixed>>, totals: array<string, string>, errors: array<string, list<string>>}
     */
    public function preview(array $input, array $ctx, User $actor): array
    {
        return $this->run($input, $ctx, $actor);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{editing: ?SalesOrder, quotation: ?Quotation, deal: ?Deal, policy: string, location_id: int, company_id: int}  $ctx
     * @return array{order: array<string, mixed>, lines: array<int, array<string, mixed>>, totals: array<string, string>, errors: array<string, list<string>>}
     */
    private function run(array $input, array $ctx, User $actor): array
    {
        $header = $this->normaliseHeader((array) ($input['order'] ?? []));
        $rows = $this->normaliseLines((array) ($input['items'] ?? []));
        $editing = $ctx['editing'];
        $earliestDelivery = $editing === null ? now()->toDateString() : $editing->order_date->toDateString();

        $rules = [
            'order.delivery_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$earliestDelivery, 'before_or_equal:'.now()->addYears(2)->toDateString()],
            'order.customer_po_number' => ['nullable', 'string', 'max:60'],
            'order.customer_po_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString()],
            'order.price_list_id' => ['nullable', 'integer'],
            'order.place_of_supply_state_id' => ['required', 'integer'],
            'order.party_name' => ['required', 'string', 'max:200'],
            'order.contact_person' => ['nullable', 'string', 'max:150'],
            'order.mobile' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'order.email' => ['nullable', 'email:rfc', 'max:150'],
            'order.bill_line1' => ['required', 'string', 'max:200'],
            'order.bill_line2' => ['nullable', 'string', 'max:200'],
            'order.bill_city' => ['nullable', 'string', 'max:100'],
            'order.bill_pin_code' => ['nullable', 'regex:/^[1-9]\d{5}$/'],
            'order.customer_gstin' => ['nullable', 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'order.ship_line1' => ['nullable', 'string', 'max:200'],
            'order.ship_line2' => ['nullable', 'string', 'max:200'],
            'order.ship_city' => ['nullable', 'string', 'max:100'],
            'order.ship_pin_code' => ['nullable', 'regex:/^[1-9]\d{5}$/'],
            'order.terms' => ['nullable', 'string', 'max:4000'],
            'order.remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:'.DealService::MAX_LINES],
        ];

        foreach ($rows as $i => $row) {
            $rules["items.$i.id"] = ['nullable', 'integer'];
            $rules["items.$i.quotation_item_id"] = ['nullable', 'integer'];
            $rules["items.$i.deal_item_id"] = ['nullable', 'integer'];
            $rules["items.$i.item_id"] = ['required', 'integer'];
            $rules["items.$i.unit_id"] = ['nullable', 'integer'];
            $rules["items.$i.quantity"] = ['required', 'bail', 'regex:/^\d{1,12}(\.\d{1,3})?$/', fn (string $a, mixed $v, \Closure $fail) => bccomp((string) $v, '0', 3) > 0 ? null : $fail('The quantity must be more than zero.')];
            $rules["items.$i.discount_percent"] = ['required', 'bail', 'regex:/^\d{1,3}(\.\d{1,2})?$/', fn (string $a, mixed $v, \Closure $fail) => bccomp((string) $v, '100', 2) <= 0 ? null : $fail('The discount cannot be more than 100 per cent.')];
            $rules["items.$i.rate_override"] = ['nullable', 'regex:/^\d{1,11}(\.\d{1,'.Rate::SCALE.'})?$/'];
            $rules["items.$i.override_reason"] = ['nullable', 'string', 'max:1000'];
        }

        $validator = Validator::make(['order' => $header, 'items' => $rows], $rules, [
            'order.delivery_date.required' => 'Enter the date the customer wants the goods.',
            'order.delivery_date.date_format' => 'Enter the delivery date as a valid date.',
            'order.delivery_date.after_or_equal' => $editing === null ? 'The delivery date cannot be in the past.' : 'The delivery date cannot be before the order date.',
            'order.delivery_date.before_or_equal' => 'The delivery date is more than two years away.',
            'order.customer_po_date.before_or_equal' => 'The customer’s order date cannot be in the future.',
            'order.customer_po_date.date_format' => 'Enter the customer’s order date as a valid date.',
            'order.place_of_supply_state_id.required' => 'Choose the state the goods are supplied to: it decides which GST is charged.',
            'order.party_name.required' => 'Enter the customer’s name.',
            'order.mobile.regex' => 'Enter a 10-digit Indian mobile number.',
            'order.bill_line1.required' => 'Enter the billing address.',
            'order.bill_pin_code.regex' => 'Enter a 6-digit PIN code.',
            'order.ship_pin_code.regex' => 'Enter a 6-digit PIN code.',
            'order.customer_gstin.regex' => 'Enter the customer’s GSTIN as 15 letters and digits, e.g. 24ABCDE1234F1Z5.',
            'items.required' => 'Add at least one item line.',
            'items.min' => 'Add at least one item line.',
            'items.max' => 'An order can have at most '.DealService::MAX_LINES.' item lines.',
            'items.*.item_id.required' => 'Choose the item.',
            'items.*.quantity.required' => 'Enter the quantity.',
            'items.*.quantity.regex' => 'Enter the quantity as a number with at most 3 decimals.',
            'items.*.discount_percent.required' => 'Enter the discount (0 for none).',
            'items.*.discount_percent.regex' => 'Enter the discount as a per cent with at most 2 decimals, e.g. 2.5.',
            'items.*.rate_override.regex' => 'Enter the rate as a number with at most '.Rate::SCALE.' decimals.',
        ], $this->attributeNames($rows));

        $result = ['order' => $header, 'lines' => [], 'totals' => [], 'errors' => []];

        $validator->after(function (\Illuminate\Validation\Validator $v) use (&$result, $ctx, $actor, $header) {
            // Rows that are not even well-formed cannot be priced; a problem with the header alone does not stop the lines being priced.
            if (collect($v->errors()->keys())->contains(fn (string $key) => str_starts_with($key, 'items'))) {
                return;
            }

            $editing = $ctx['editing'];
            $supply = $this->supply($v, $header, $ctx['location_id'], $ctx['company_id']);
            $result['order']['supply_type'] = $supply['type'] ?? null;
            $list = $this->priceList($v, $header['price_list_id']);

            $reprice = $editing === null || $this->wants($header['reprice']) || (int) $editing->price_list_id !== (int) $list;
            $priceDate = $editing === null || $this->wants($header['reprice']) ? now()->toDateString() : $editing->price_date->toDateString();

            $lines = $this->lines($v, $ctx, $actor, $list, $priceDate, $reprice, $supply['type'] ?? TaxService::INTRA);
            $result['lines'] = $lines;

            if ($v->errors()->any() || $supply === null) {
                return;
            }

            $totals = $this->tax->document(array_map(fn (array $l) => $l['figures'], $lines));

            if (bccomp($totals['grand_total'], self::MAX_AMOUNT, 2) > 0) {
                $v->errors()->add('items', 'The lines add up to more than the system can hold. Check the quantities and rates.');

                return;
            }

            // no shipping address typed: the goods go to the billing address
            $ship = array_filter([$header['ship_line1'], $header['ship_line2'], $header['ship_city'], $header['ship_pin_code']], fn ($p) => $p !== null);
            $shipTo = $ship === [] ? ['ship_line1' => $header['bill_line1'], 'ship_line2' => $header['bill_line2'], 'ship_city' => $header['bill_city'], 'ship_pin_code' => $header['bill_pin_code']] : [];

            $result['totals'] = $totals;
            $result['order'] = [
                ...$header, ...$shipTo,
                'price_list_id' => $list, 'place_of_supply_state_id' => (int) $header['place_of_supply_state_id'], 'price_date' => $priceDate,
                'supply_type' => $supply['type'], 'supplier_state_code' => $supply['supplier_code'], 'supplier_gstin' => $supply['supplier_gstin'],
            ];
        });

        $validator->fails();   // runs the rules and the pricing above; what they found is read from the validator

        $result['errors'] = $validator->errors()->toArray();

        return $result;
    }

    // ---- who supplies, and to where -----------------------------------------------------------------------------

    /**
     * @param  array<string, string|null>  $header
     * @return array{type: string, supplier_code: string, supplier_gstin: string|null}|null
     */
    private function supply(\Illuminate\Validation\Validator $v, array $header, int $locationId, int $companyId): ?array
    {
        if ($header['place_of_supply_state_id'] === null) {
            return null;   // the rule for it has said so
        }

        $state = State::query()->where('status', 'active')->find((int) $header['place_of_supply_state_id']);

        if ($state === null) {
            $v->errors()->add('order.place_of_supply_state_id', 'Choose a state from the list.');

            return null;
        }

        $branch = Location::query()->find($locationId);
        $company = Company::withoutGlobalScopes()->find($companyId);
        $code = trim((string) ($branch->state_code ?: $company->state_code));

        try {
            $type = $this->tax->supplyType($code, $state->gst_code);
        } catch (InvalidArgumentException $e) {
            $v->errors()->add('order.place_of_supply_state_id', $code === ''
                ? 'The branch has no state set, so the tax cannot be worked out. Ask an administrator to set the state of the branch.'
                : $e->getMessage());

            return null;
        }

        return ['type' => $type, 'supplier_code' => $code, 'supplier_gstin' => $company->gstin];
    }

    private function priceList(\Illuminate\Validation\Validator $v, ?string $submitted): ?int
    {
        if ($submitted === null) {
            return null;
        }

        $list = PriceList::query()->where('status', 'active')->find((int) $submitted);

        if ($list === null) {
            $v->errors()->add('order.price_list_id', 'Choose an active price list, or leave it blank for the standard rates.');

            return null;
        }

        return (int) $list->getKey();
    }

    // ---- the lines ----------------------------------------------------------------------------------------------

    /**
     * @param  array{editing: ?SalesOrder, quotation: ?Quotation, deal: ?Deal, policy: string, location_id: int, company_id: int}  $ctx
     * @return array<int, array<string, mixed>> keyed by the row each came from
     */
    private function lines(\Illuminate\Validation\Validator $v, array $ctx, User $actor, ?int $priceList, string $priceDate, bool $reprice, string $supplyType): array
    {
        $rows = $v->getData()['items'];
        $editing = $ctx['editing'];
        $quotation = $ctx['quotation'];
        $deal = $ctx['deal'];

        /** @var Collection<int, SalesOrderItem> $current */
        $current = $editing === null ? collect() : $editing->items()->get()->keyBy('id');
        /** @var Collection<int, QuotationItem> $quoted */
        $quoted = $editing === null && $quotation !== null ? $quotation->items()->get()->keyBy('id') : collect();
        $dealItems = $editing === null && $deal !== null ? $deal->items()->get()->keyBy('id') : collect();
        $copy = $ctx['policy'] === 'copy';
        $seen = ['line' => [], 'quotation' => [], 'deal' => []];
        $out = [];

        foreach ($rows as $i => $row) {
            $line = null;       // the order's own saved line
            $basis = null;      // what an unchanged line may keep its price from: the saved line, else the quotation's line (copy policy)
            $quotationItemId = null;
            $dealItemId = null;

            if ($row['id'] !== null) {
                $line = $current->get((int) $row['id']);

                if ($line === null || isset($seen['line'][(int) $row['id']])) {
                    $v->errors()->add("items.$i.id", 'This row does not belong to the order.');

                    continue;
                }

                $seen['line'][(int) $row['id']] = true;
                $basis = $line;
                $quotationItemId = $line->quotation_item_id;     // the links back to the source are kept, whatever was sent
                $dealItemId = $line->deal_item_id;
            } elseif ($row['quotation_item_id'] !== null) {
                $source = $quoted->get((int) $row['quotation_item_id']);

                if ($source === null || isset($seen['quotation'][(int) $row['quotation_item_id']])) {
                    $v->errors()->add("items.$i.quotation_item_id", 'This row does not belong to the quotation.');

                    continue;
                }

                $seen['quotation'][(int) $row['quotation_item_id']] = true;
                $quotationItemId = $source->getKey();
                $basis = $copy ? $source : null;
            } elseif ($row['deal_item_id'] !== null) {
                $source = $dealItems->get((int) $row['deal_item_id']);

                if ($source === null || isset($seen['deal'][(int) $row['deal_item_id']])) {
                    $v->errors()->add("items.$i.deal_item_id", 'This row does not belong to the deal.');

                    continue;
                }

                $seen['deal'][(int) $row['deal_item_id']] = true;
                $dealItemId = $source->getKey();
            }

            $itemId = (int) $row['item_id'];
            $item = $this->items->usable($itemId) ?? ((int) $line?->item_id === $itemId ? Item::query()->with(['baseUnit', 'uoms.unit'])->find($itemId) : null);

            if ($item === null) {
                $v->errors()->add("items.$i.item_id", 'Choose an approved, active item.');

                continue;
            }

            $unitId = $row['unit_id'] !== null ? (int) $row['unit_id'] : (int) $item->base_unit_id;
            $sold = $unitId === (int) $item->base_unit_id || $item->uoms->contains(fn ($uom) => (int) $uom->unit_id === $unitId);
            $unit = $sold ? Unit::query()->find($unitId) : null;

            if ($unit === null) {
                $v->errors()->add("items.$i.unit_id", "{$item->name} is not sold in that unit.");

                continue;
            }

            $quantity = bcadd((string) $row['quantity'], '0', 3);

            if (! $this->uoms->accepts($unit, $quantity)) {
                $v->errors()->add("items.$i.quantity", "{$unit->name} cannot be split: enter a whole number.");

                continue;
            }

            // ---- the price: kept if the line is unchanged, otherwise looked up ----
            $same = $basis !== null && (int) $basis->item_id === $itemId && (int) $basis->unit_id === $unitId;
            $keep = $same && ! ($reprice && $line !== null);
            // a quotation's price is copied only on a new order; once the order exists its own lines are the basis

            if ($keep) {
                $quote = null;
                $listRate = bcadd((string) $basis->list_rate, '0', Rate::SCALE);
                $rateId = $basis->rate_id;
                $rateSource = (string) $basis->rate_source;
                $gstRate = (string) $basis->gst_rate;
                $hsn = $basis->hsn_code;
                $name = (string) $basis->item_name;
            } else {
                try {
                    $quote = $this->rates->getRate($item, $ctx['location_id'], $unit, $priceDate, $priceList);
                } catch (RateNotFoundException $e) {
                    $v->errors()->add("items.$i.item_id", $e->getMessage());

                    continue;
                }

                $listRate = $quote->rate;
                $rateId = $quote->rateId;
                $rateSource = $quote->source;

                if ($item->gst_rate === null) {
                    $v->errors()->add("items.$i.item_id", "{$item->name} has no GST rate on the item master, so the tax cannot be worked out. Ask Purchase to complete the item.");

                    continue;
                }

                $gstRate = bcadd((string) $item->gst_rate, '0', 2);
                $hsn = $item->hsn_code;
                $name = mb_substr($item->name, 0, 200);
            }

            // ---- the rate: the master's, the basis's own changed rate, or a newly changed one (permission, reason, audit) ----
            $rate = $listRate;
            $overridden = false;
            $reason = null;
            $audit = null;
            $override = $row['rate_override'];

            if ($keep && $basis->rate_overridden && $override === null && $line === null) {
                // a quotation's own changed rate comes across with the line (it was audited when it was quoted)
                $rate = bcadd((string) $basis->rate, '0', Rate::SCALE);
                $overridden = true;
                $reason = $basis->override_reason;
            }

            if ($override !== null) {
                $new = bcadd($override, '0', Rate::SCALE);

                if (bccomp($new, '0', Rate::SCALE) <= 0) {
                    $v->errors()->add("items.$i.rate_override", 'The rate must be more than zero.');

                    continue;
                }

                if (bccomp($new, $listRate, Rate::SCALE) !== 0) {
                    $text = trim((string) $row['override_reason']);
                    $unchanged = $keep && $basis->rate_overridden && bccomp((string) $basis->rate, $new, Rate::SCALE) === 0;

                    if (! $actor->can('rate.override') && ! $unchanged) {
                        $v->errors()->add("items.$i.rate_override", 'You are not allowed to change a rate on an order.');

                        continue;
                    }

                    if ($unchanged && $text === '') {
                        $text = (string) $basis->override_reason;
                    }

                    if (mb_strlen($text) < 3) {
                        $v->errors()->add("items.$i.override_reason", 'Please say why the rate is being changed.');

                        continue;
                    }

                    $rate = $new;
                    $overridden = true;
                    $reason = $text;

                    if (! $unchanged) {
                        $audit = ['quote' => $quote ?? $this->quoteOf($basis, $item, $ctx['location_id'], $priceList, $priceDate), 'rate' => $new, 'reason' => $text];
                    }
                } else {
                    $rate = $listRate;   // typed the master's own rate: nothing is overridden
                }
            }

            $discount = bcadd((string) $row['discount_percent'], '0', 2);
            $figures = $this->tax->line($quantity, $rate, $discount, $gstRate, $supplyType);

            if (bccomp($figures['total'], self::MAX_AMOUNT, 2) > 0) {
                $v->errors()->add("items.$i.quantity", 'This line comes to more than the system can hold. Check the quantity and the rate.');

                continue;
            }

            $out[$i] = [
                'id' => $line?->getKey(), 'quotation_item_id' => $quotationItemId, 'deal_item_id' => $dealItemId,
                'item_id' => $itemId, 'unit_id' => $unitId, 'item_name' => $name, 'hsn_code' => $hsn,
                'quantity' => $quantity, 'rate_id' => $rateId, 'rate_source' => $rateSource, 'list_rate' => $listRate, 'rate' => $rate,
                'rate_overridden' => $overridden, 'override_reason' => $reason, 'discount_percent' => $discount, 'gst_rate' => $gstRate,
                'figures' => $figures, 'audit' => $audit,
            ];
        }

        ksort($out);

        return $out;
    }

    /** The quote a saved line was priced from, rebuilt so that an override on a kept line can be audited against it. */
    private function quoteOf(SalesOrderItem|QuotationItem|null $basis, Item $item, int $locationId, ?int $priceList, string $priceDate): RateQuote
    {
        return new RateQuote(
            rateId: (int) $basis?->rate_id, itemId: (int) $item->getKey(), locationId: $locationId, unitId: (int) $basis?->unit_id, priceListId: $priceList,
            rate: (string) $basis?->list_rate, effectiveFrom: $priceDate, effectiveTo: null, asOf: $priceDate, source: (string) $basis?->rate_source,
        );
    }

    // ---- normalising --------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $q
     * @return array<string, string|null>
     */
    private function normaliseHeader(array $q): array
    {
        $out = [];
        $keys = ['delivery_date', 'customer_po_number', 'customer_po_date', 'price_list_id', 'place_of_supply_state_id', 'party_name', 'contact_person', 'mobile', 'email',
            'bill_line1', 'bill_line2', 'bill_city', 'bill_pin_code', 'ship_line1', 'ship_line2', 'ship_city', 'ship_pin_code', 'reprice'];

        foreach ($keys as $key) {
            $out[$key] = $this->text($q[$key] ?? null);
        }

        $out['mobile'] = $out['mobile'] === null ? null : preg_replace('/\D+/', '', $out['mobile']);
        $out['mobile'] = $out['mobile'] === '' ? null : $out['mobile'];
        $out['email'] = $out['email'] === null ? null : mb_strtolower($out['email']);
        $gstin = $this->text($q['customer_gstin'] ?? null);
        $out['customer_gstin'] = $gstin === null ? null : mb_strtoupper(str_replace(' ', '', $gstin));

        foreach (['terms', 'remarks'] as $key) {
            $text = trim((string) preg_replace("/\r\n?/", "\n", (string) ($q[$key] ?? '')));
            $out[$key] = $text === '' ? null : $text;
        }

        return $out;
    }

    /**
     * @param  array<int|string, mixed>  $rows
     * @return list<array<string, string|null>>
     */
    private function normaliseLines(array $rows): array
    {
        $lines = [];

        foreach (array_values($rows) as $row) {
            $row = (array) $row;
            $get = fn (string $key) => $this->text($row[$key] ?? null);
            $clean = fn (string $key) => ($t = $get($key)) === null ? null : str_replace(',', '', $t);

            $line = [
                'id' => $get('id'), 'quotation_item_id' => $get('quotation_item_id'), 'deal_item_id' => $get('deal_item_id'), 'item_id' => $get('item_id'),
                'unit_id' => $get('unit_id'), 'quantity' => $clean('quantity'), 'discount_percent' => $clean('discount_percent') ?? '0',
                'rate_override' => $clean('rate_override'), 'override_reason' => $get('override_reason'),
            ];

            // an untouched blank row of the form is not a line
            if ($line['item_id'] === null && $line['quantity'] === null && $line['id'] === null && $line['quotation_item_id'] === null && $line['deal_item_id'] === null && $line['rate_override'] === null) {
                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     * @return array<string, string>
     */
    private function attributeNames(array $rows): array
    {
        $names = [
            'order.delivery_date' => 'delivery date', 'order.customer_po_number' => 'customer’s order number', 'order.customer_po_date' => 'customer’s order date',
            'order.price_list_id' => 'price list', 'order.place_of_supply_state_id' => 'place of supply', 'order.party_name' => 'customer name',
            'order.bill_line1' => 'billing address', 'order.bill_pin_code' => 'PIN code', 'order.ship_pin_code' => 'PIN code', 'order.customer_gstin' => 'GSTIN',
            'order.terms' => 'terms', 'order.remarks' => 'remarks',
        ];

        foreach (array_keys($rows) as $i) {
            $n = $i + 1;

            foreach (['item_id' => 'item', 'unit_id' => 'unit', 'quantity' => 'quantity', 'discount_percent' => 'discount', 'rate_override' => 'rate', 'override_reason' => 'reason'] as $field => $label) {
                $names["items.$i.$field"] = "line $n $label";
            }
        }

        return $names;
    }

    private function wants(?string $flag): bool
    {
        return $flag !== null && in_array(strtolower($flag), ['1', 'true', 'yes', 'on'], true);
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) ($value ?? '')));

        return $text === '' ? null : $text;
    }
}
