<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\DealService;
use App\Modules\Masters\Exceptions\RateNotFoundException;
use App\Modules\Masters\Models\Item;
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
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Turns what a person typed into a priced, taxed quotation — and saves nothing. The service that saves (QuotationService) and the
 * screen that previews the total both come here, so the figure shown is the figure that will be stored.
 *
 *  - **Who and where**: the customer's name, contact and billing address are copies (they start from the deal or the customer
 *    ledger and may be adjusted on the quotation); the **place of supply** is a state, and with the supplier's state (the branch's,
 *    else the company's) it decides CGST + SGST or IGST (TaxService).
 *  - **Prices come only from the rate master** (RateService::getRate), as of the quotation's price date and in its price list
 *    (falling back to the standard rate, and the line says which it used). A line that is unchanged keeps the price it was saved
 *    with; a new or changed line, a new price list, or "refresh rates", looks the rate up again.
 *  - **A changed rate** needs `rate.override`, a reason, and leaves an audit entry on the rate (recorded by QuotationService once
 *    the quotation exists, from the `audit` of the line returned here).
 *  - **Tax** per line: the item's HSN and GST rate (or the ones the line was saved with), the discount per cent, all through TaxService.
 *
 * Input: ['quotation' => [valid_until, price_list_id, place_of_supply_state_id, party_name, contact_person, mobile, email, bill_line1,
 *         bill_line2, bill_city, bill_pin_code, customer_gstin, terms, remarks, reprice?],
 *         'items' => [[id?, deal_item_id?, lead_interest_id?, item_id, unit_id, quantity, discount_percent, rate_override?, override_reason?], …]]
 */
final class QuotationPricer
{
    /** The largest amount a stored money column holds (decimal 15,2). */
    private const MAX_AMOUNT = '9999999999999.99';

    public function __construct(
        private readonly ItemLookup $items,
        private readonly UomConverter $uoms,
        private readonly RateService $rates,
        private readonly TaxService $tax,
        private readonly SettingsService $settings,
    ) {}

    // ---- what a new quotation starts with ---------------------------------------------------------------------------

    /**
     * The header a new quotation starts with, from its source: who it is to (the deal's snapshot, else the lead's), the customer
     * ledger's billing address and GSTIN, the state it will be supplied to, a validity and the company's standard terms.
     *
     * @return array<string, string>
     */
    public function defaults(Lead $lead, ?Deal $deal): array
    {
        $customer = ($deal->customer_ledger_id ?? $lead->customer_ledger_id) === null ? null
            : ($deal->customer ?? $lead->customer);

        $address = $customer === null ? null : $this->billingAddress($customer->addresses()->where('status', 'active')->get());
        $ship = $customer === null ? null : $customer->addresses()->where('status', 'active')->where('is_shipping', true)->orderByDesc('is_default_shipping')->first();

        $companyId = (int) $lead->company_id;
        $days = max(1, (int) $this->settings->get('quotation.validity_days', $companyId));

        return [
            'valid_until' => now()->addDays($days)->toDateString(),
            'price_list_id' => '',
            'place_of_supply_state_id' => (string) ($ship->state_id ?? $address->state_id ?? $lead->state_id ?? ''),
            'party_name' => (string) ($deal->party_name ?? $lead->name),
            'contact_person' => (string) ($deal->contact_person ?? $lead->contact_person ?? ''),
            'mobile' => (string) ($deal->mobile ?? $lead->mobile ?? ''),
            'email' => (string) ($deal->email ?? $lead->email ?? ''),
            'bill_line1' => (string) ($address->line1 ?? $lead->address_line1 ?? ''),
            'bill_line2' => (string) ($address->line2 ?? $lead->address_line2 ?? ''),
            'bill_city' => (string) ($address->city ?? $lead->city ?? ''),
            'bill_pin_code' => (string) ($address->pin_code ?? $lead->pin_code ?? ''),
            'customer_gstin' => (string) ($address->gstin ?? $customer->gstin ?? ''),
            'terms' => (string) $this->settings->get('quotation.default_terms', $companyId),
            'remarks' => '',
        ];
    }

    /** @param  iterable<LedgerAddress>  $addresses */
    private function billingAddress(iterable $addresses): ?LedgerAddress
    {
        $best = null;

        foreach ($addresses as $address) {
            if (! $address->is_billing) {
                continue;
            }

            if ($address->is_default_billing) {
                return $address;
            }

            $best ??= $address;
        }

        return $best;
    }

    // ---- pricing ----------------------------------------------------------------------------------------------

    /**
     * The priced quotation, or a ValidationException saying what is wrong with what was typed.
     *
     * @param  array<string, mixed>  $input
     * @return array{quotation: array<string, mixed>, lines: list<array<string, mixed>>, totals: array<string, string>}
     *
     * @throws ValidationException
     */
    public function price(array $input, ?Quotation $editing, Lead $lead, ?Deal $deal, User $actor, int $locationId): array
    {
        $run = $this->run($input, $editing, $lead, $deal, $actor, $locationId);

        if ($run['errors'] !== []) {
            throw ValidationException::withMessages($run['errors']);
        }

        return ['quotation' => $run['quotation'], 'lines' => array_values($run['lines']), 'totals' => $run['totals']];
    }

    /**
     * What can be worked out so far, for the screen to show while the rest is still being filled in: the lines that could be priced (keyed
     * by the row they came from), the totals once everything is right, and what is wrong, by field. Nothing is saved or audited.
     *
     * @param  array<string, mixed>  $input
     * @return array{quotation: array<string, mixed>, lines: array<int, array<string, mixed>>, totals: array<string, string>, errors: array<string, list<string>>}
     */
    public function preview(array $input, ?Quotation $editing, Lead $lead, ?Deal $deal, User $actor, int $locationId): array
    {
        return $this->run($input, $editing, $lead, $deal, $actor, $locationId);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{quotation: array<string, mixed>, lines: array<int, array<string, mixed>>, totals: array<string, string>, errors: array<string, list<string>>}
     */
    private function run(array $input, ?Quotation $editing, Lead $lead, ?Deal $deal, User $actor, int $locationId): array
    {
        $header = $this->normaliseHeader((array) ($input['quotation'] ?? []));
        $rows = $this->normaliseLines((array) ($input['items'] ?? []));
        $data = ['quotation' => $header, 'items' => $rows];

        $rules = [
            'quotation.valid_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now()->toDateString(), 'before_or_equal:'.now()->addYear()->toDateString()],
            'quotation.price_list_id' => ['nullable', 'integer'],
            'quotation.place_of_supply_state_id' => ['required', 'integer'],
            'quotation.party_name' => ['required', 'string', 'max:200'],
            'quotation.contact_person' => ['nullable', 'string', 'max:150'],
            'quotation.mobile' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'quotation.email' => ['nullable', 'email:rfc', 'max:150'],
            'quotation.bill_line1' => ['required', 'string', 'max:200'],
            'quotation.bill_line2' => ['nullable', 'string', 'max:200'],
            'quotation.bill_city' => ['nullable', 'string', 'max:100'],
            'quotation.bill_pin_code' => ['nullable', 'regex:/^[1-9]\d{5}$/'],
            'quotation.customer_gstin' => ['nullable', 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'quotation.terms' => ['nullable', 'string', 'max:4000'],
            'quotation.remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:'.DealService::MAX_LINES],
        ];

        foreach ($rows as $i => $row) {
            $rules["items.$i.id"] = ['nullable', 'integer'];
            $rules["items.$i.deal_item_id"] = ['nullable', 'integer'];
            $rules["items.$i.lead_interest_id"] = ['nullable', 'integer'];
            $rules["items.$i.item_id"] = ['required', 'integer'];
            $rules["items.$i.unit_id"] = ['nullable', 'integer'];
            $rules["items.$i.quantity"] = ['required', 'bail', 'regex:/^\d{1,12}(\.\d{1,3})?$/', fn (string $a, mixed $v, \Closure $fail) => bccomp((string) $v, '0', 3) > 0 ? null : $fail('The quantity must be more than zero.')];
            $rules["items.$i.discount_percent"] = ['required', 'bail', 'regex:/^\d{1,3}(\.\d{1,2})?$/', fn (string $a, mixed $v, \Closure $fail) => bccomp((string) $v, '100', 2) <= 0 ? null : $fail('The discount cannot be more than 100 per cent.')];
            $rules["items.$i.rate_override"] = ['nullable', 'regex:/^\d{1,11}(\.\d{1,'.Rate::SCALE.'})?$/'];
            $rules["items.$i.override_reason"] = ['nullable', 'string', 'max:1000'];
        }

        $validator = Validator::make($data, $rules, [
            'quotation.valid_until.required' => 'Enter the date until which the quotation is valid.',
            'quotation.valid_until.date_format' => 'Enter the valid-until date as a valid date.',
            'quotation.valid_until.after_or_equal' => 'The quotation cannot be valid only until a day that has already passed.',
            'quotation.valid_until.before_or_equal' => 'A quotation cannot be valid for more than a year.',
            'quotation.place_of_supply_state_id.required' => 'Choose the state the goods are supplied to: it decides which GST is charged.',
            'quotation.party_name.required' => 'Enter the customer’s name.',
            'quotation.mobile.regex' => 'Enter a 10-digit Indian mobile number.',
            'quotation.bill_line1.required' => 'Enter the billing address.',
            'quotation.bill_pin_code.regex' => 'Enter a 6-digit PIN code.',
            'quotation.customer_gstin.regex' => 'Enter the customer’s GSTIN as 15 letters and digits, e.g. 24ABCDE1234F1Z5.',
            'items.required' => 'Add at least one item line.',
            'items.min' => 'Add at least one item line.',
            'items.max' => 'A quotation can have at most '.DealService::MAX_LINES.' item lines.',
            'items.*.item_id.required' => 'Choose the item.',
            'items.*.quantity.required' => 'Enter the quantity.',
            'items.*.quantity.regex' => 'Enter the quantity as a number with at most 3 decimals.',
            'items.*.discount_percent.required' => 'Enter the discount (0 for none).',
            'items.*.discount_percent.regex' => 'Enter the discount as a per cent with at most 2 decimals, e.g. 2.5.',
            'items.*.rate_override.regex' => 'Enter the rate as a number with at most '.Rate::SCALE.' decimals.',
        ], $this->attributeNames($rows));

        $result = ['quotation' => $header, 'lines' => [], 'totals' => [], 'errors' => []];

        $validator->after(function (\Illuminate\Validation\Validator $v) use (&$result, $editing, $lead, $deal, $actor, $locationId, $header) {
            // Rows that are not even well-formed cannot be priced. A problem with the header alone does not stop the lines being priced:
            // the screen shows what each line would cost while the rest is still being filled in.
            if (collect($v->errors()->keys())->contains(fn (string $key) => str_starts_with($key, 'items'))) {
                return;
            }

            $supply = $this->supply($v, $header, $locationId, (int) $lead->company_id);
            $result['quotation']['supply_type'] = $supply['type'] ?? null;
            $list = $this->priceList($v, $header['price_list_id']);

            $reprice = $editing === null || $this->wants($header['reprice']) || (int) $editing->price_list_id !== (int) $list;
            $priceDate = $editing === null || $this->wants($header['reprice']) ? now()->toDateString() : $editing->price_date->toDateString();

            $lines = $this->lines($v, $editing, $lead, $deal, $actor, $locationId, $list, $priceDate, $reprice, $supply['type'] ?? TaxService::INTRA);   // without a place of supply the figures are not used: it is reported and nothing is saved
            $result['lines'] = $lines;

            if ($v->errors()->any() || $supply === null) {
                return;
            }

            $totals = $this->tax->document(array_map(fn (array $l) => $l['figures'], $lines));

            if (bccomp($totals['grand_total'], self::MAX_AMOUNT, 2) > 0) {
                $v->errors()->add('items', 'The lines add up to more than the system can hold. Check the quantities and rates.');

                return;
            }

            $result['totals'] = $totals;
            $result['quotation'] = [
                ...$header,
                'price_list_id' => $list, 'place_of_supply_state_id' => (int) $header['place_of_supply_state_id'], 'price_date' => $priceDate,
                'supply_type' => $supply['type'], 'supplier_state_code' => $supply['supplier_code'], 'supplier_gstin' => $supply['supplier_gstin'],
            ];
        });

        $validator->fails();   // runs the rules and the pricing above; what they found is read from the validator

        $result['errors'] = $validator->errors()->toArray();

        return $result;
    }

    // ---- who supplies, and to where ----------------------------------------------------------------------------

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
            $v->errors()->add('quotation.place_of_supply_state_id', 'Choose a state from the list.');

            return null;
        }

        $branch = Location::query()->find($locationId);
        $company = Company::withoutGlobalScopes()->find($companyId);
        $code = trim((string) ($branch?->state_code ?: $company?->state_code));

        try {
            $type = $this->tax->supplyType($code, $state->gst_code);
        } catch (InvalidArgumentException $e) {
            $v->errors()->add('quotation.place_of_supply_state_id', $code === ''
                ? 'The branch has no state set, so the tax cannot be worked out. Ask an administrator to set the state of the branch.'
                : $e->getMessage());

            return null;
        }

        return ['type' => $type, 'supplier_code' => $code, 'supplier_gstin' => $company?->gstin];
    }

    private function priceList(\Illuminate\Validation\Validator $v, ?string $submitted): ?int
    {
        if ($submitted === null) {
            return null;
        }

        $list = PriceList::query()->where('status', 'active')->find((int) $submitted);

        if ($list === null) {
            $v->errors()->add('quotation.price_list_id', 'Choose an active price list, or leave it blank for the standard rates.');

            return null;
        }

        return (int) $list->getKey();
    }

    // ---- the lines ----------------------------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>> keyed by the row each came from
     */
    private function lines(
        \Illuminate\Validation\Validator $v, ?Quotation $editing, Lead $lead, ?Deal $deal, User $actor, int $locationId,
        ?int $priceList, string $priceDate, bool $reprice, string $supplyType,
    ): array {
        $rows = $v->getData()['items'];
        $current = $editing === null ? collect() : $editing->items()->get()->keyBy('id');
        $dealItems = $editing === null && $deal !== null ? $deal->items()->get()->keyBy('id') : collect();
        $interests = $editing === null && $deal === null ? $lead->interests()->get()->keyBy('id') : collect();
        $seenLines = $seenDeal = $seenInterest = [];
        $out = [];

        foreach ($rows as $i => $row) {
            $line = null;
            $dealItemId = null;
            $interestId = null;

            if ($row['id'] !== null) {
                $line = $current->get((int) $row['id']);

                if ($line === null || isset($seenLines[(int) $row['id']])) {
                    $v->errors()->add("items.$i.id", 'This row does not belong to the quotation.');

                    continue;
                }

                $seenLines[(int) $row['id']] = true;
                $dealItemId = $line->deal_item_id;       // the links back to the source are kept, whatever was sent
                $interestId = $line->lead_interest_id;
            } else {
                if ($row['deal_item_id'] !== null) {
                    $source = $dealItems->get((int) $row['deal_item_id']);

                    if ($source === null || isset($seenDeal[(int) $row['deal_item_id']])) {
                        $v->errors()->add("items.$i.deal_item_id", 'This row does not belong to the deal.');

                        continue;
                    }

                    $seenDeal[(int) $row['deal_item_id']] = true;
                    $dealItemId = $source->getKey();
                } elseif ($row['lead_interest_id'] !== null) {
                    $source = $interests->get((int) $row['lead_interest_id']);

                    if ($source === null || isset($seenInterest[(int) $row['lead_interest_id']])) {
                        $v->errors()->add("items.$i.lead_interest_id", 'This row does not belong to the inquiry.');

                        continue;
                    }

                    $seenInterest[(int) $row['lead_interest_id']] = true;
                    $interestId = $source->getKey();
                }
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
            $same = $line !== null && (int) $line->item_id === $itemId && (int) $line->unit_id === $unitId;
            $keep = $same && ! $reprice;

            if ($keep && $line instanceof QuotationItem) {
                $quote = null;
                $listRate = bcadd((string) $line->list_rate, '0', Rate::SCALE);
                $rateId = $line->rate_id;
                $source = (string) $line->rate_source;
                $gstRate = (string) $line->gst_rate;
                $hsn = $line->hsn_code;
            } else {
                try {
                    $quote = $this->rates->getRate($item, $locationId, $unit, $priceDate, $priceList);
                } catch (RateNotFoundException $e) {
                    $v->errors()->add("items.$i.item_id", $e->getMessage());

                    continue;
                }

                $listRate = $quote->rate;
                $rateId = $quote->rateId;
                $source = $quote->source;

                if ($item->gst_rate === null) {
                    $v->errors()->add("items.$i.item_id", "{$item->name} has no GST rate on the item master, so the tax cannot be worked out. Ask Purchase to complete the item.");

                    continue;
                }

                $gstRate = bcadd((string) $item->gst_rate, '0', 2);
                $hsn = $item->hsn_code;
            }

            // ---- a changed rate: permission, reason, audit ----
            $rate = $listRate;
            $overridden = false;
            $reason = null;
            $audit = null;
            $override = $row['rate_override'];

            if ($override !== null) {
                $new = bcadd($override, '0', Rate::SCALE);

                if (bccomp($new, '0', Rate::SCALE) <= 0) {
                    $v->errors()->add("items.$i.rate_override", 'The rate must be more than zero.');

                    continue;
                }

                if (bccomp($new, $listRate, Rate::SCALE) !== 0) {
                    $text = trim((string) $row['override_reason']);
                    $unchanged = $keep && $line instanceof QuotationItem && $line->rate_overridden && bccomp((string) $line->rate, $new, Rate::SCALE) === 0;

                    if (! $actor->can('rate.override') && ! $unchanged) {
                        $v->errors()->add("items.$i.rate_override", 'You are not allowed to change a rate on a quotation.');

                        continue;
                    }

                    if ($unchanged && $text === '') {
                        $text = (string) $line->override_reason;
                    }

                    if (mb_strlen($text) < 3) {
                        $v->errors()->add("items.$i.override_reason", 'Please say why the rate is being changed.');

                        continue;
                    }

                    $rate = $new;
                    $overridden = true;
                    $reason = $text;

                    if (! $unchanged) {
                        $audit = ['quote' => $quote ?? $this->quoteOf($line, $item, $locationId, $priceList, $priceDate), 'rate' => $new, 'reason' => $text];
                    }
                }
            }

            $discount = bcadd((string) $row['discount_percent'], '0', 2);

            $figures = $this->tax->line($quantity, $rate, $discount, $gstRate, $supplyType);

            if (bccomp($figures['total'], self::MAX_AMOUNT, 2) > 0) {
                $v->errors()->add("items.$i.quantity", 'This line comes to more than the system can hold. Check the quantity and the rate.');

                continue;
            }

            $out[$i] = [
                'id' => $line?->getKey(), 'deal_item_id' => $dealItemId, 'lead_interest_id' => $interestId,
                'item_id' => $itemId, 'unit_id' => $unitId, 'item_name' => $line !== null && $same ? $line->item_name : mb_substr($item->name, 0, 200), 'hsn_code' => $hsn,
                'quantity' => $quantity, 'rate_id' => $rateId, 'rate_source' => $source, 'list_rate' => $listRate, 'rate' => $rate,
                'rate_overridden' => $overridden, 'override_reason' => $reason, 'discount_percent' => $discount, 'gst_rate' => $gstRate,
                'figures' => $figures, 'audit' => $audit,
            ];
        }

        // Every line of the deal comes across only as a starting point: a quotation may drop a line (a partial offer), so nothing is demanded here.
        ksort($out);

        return $out;
    }

    /** The quote an already-saved line was priced from, rebuilt so that an override on a kept line can be audited against it. */
    private function quoteOf(?QuotationItem $line, Item $item, int $locationId, ?int $priceList, string $priceDate): RateQuote
    {
        return new RateQuote(
            rateId: (int) $line?->rate_id, itemId: (int) $item->getKey(), locationId: $locationId, unitId: (int) $line?->unit_id, priceListId: $priceList,
            rate: (string) $line?->list_rate, effectiveFrom: $priceDate, effectiveTo: null, asOf: $priceDate, source: (string) $line?->rate_source,
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

        foreach (['valid_until', 'price_list_id', 'place_of_supply_state_id', 'party_name', 'contact_person', 'mobile', 'email', 'bill_line1', 'bill_line2', 'bill_city', 'bill_pin_code', 'reprice'] as $key) {
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
                'id' => $get('id'), 'deal_item_id' => $get('deal_item_id'), 'lead_interest_id' => $get('lead_interest_id'), 'item_id' => $get('item_id'),
                'unit_id' => $get('unit_id'), 'quantity' => $clean('quantity'), 'discount_percent' => $clean('discount_percent') ?? '0',
                'rate_override' => $clean('rate_override'), 'override_reason' => $get('override_reason'),
            ];

            // an untouched blank row of the form is not a line
            if ($line['item_id'] === null && $line['quantity'] === null && $line['id'] === null && $line['deal_item_id'] === null && $line['lead_interest_id'] === null && $line['rate_override'] === null) {
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
            'quotation.valid_until' => 'valid-until date', 'quotation.price_list_id' => 'price list', 'quotation.place_of_supply_state_id' => 'place of supply',
            'quotation.party_name' => 'customer name', 'quotation.bill_line1' => 'billing address', 'quotation.bill_pin_code' => 'PIN code',
            'quotation.customer_gstin' => 'GSTIN', 'quotation.terms' => 'terms', 'quotation.remarks' => 'remarks',
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
