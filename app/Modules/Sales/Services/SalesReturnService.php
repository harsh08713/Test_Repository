<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Services\UomConverter;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Services\NumberSeriesService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes and changes sales returns and credit notes — the one door for their data.
 *
 * A return is made against one **issued** invoice (not a cancelled one) and takes quantity off its lines at the rate, discount and GST those
 * lines carried (TaxService, the same arithmetic as the invoice, so returning a whole line reproduces it exactly). What may still be returned
 * of a line is its invoiced quantity less what other returns have taken that are **pending or approved**: a draft or a rejected one reserves
 * nothing. That is checked when the return is saved and again, under a lock on the invoice, when it is sent for approval, so two returns that
 * each fit alone cannot both go through when together they do not.
 *
 * The cost of a returned line is the invoice line's own cost snapshot (unit cost × returned quantity) — never today's cost; a line whose invoice
 * line had no cost has none, and then so does the return's total.
 *
 * Input: ['return_type' => 'return'|'credit_note', 'return_date' => 'Y-m-d' (optional, today), 'reason' => string, 'lines' => [invoice line id => quantity]]
 */
final class SalesReturnService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly NumberSeriesService $numbers,
        private readonly TaxService $tax,
        private readonly UomConverter $units,
    ) {}

    /**
     * How much of each line of the invoice can still be returned.
     *
     * @return array<int, string> invoice line id => quantity (3 decimals)
     */
    public function returnable(SalesInvoice $invoice, ?int $exceptReturnId = null): array
    {
        $taken = DB::table('sales_return_items as i')
            ->join('sales_returns as r', 'r.id', '=', 'i.sales_return_id')
            ->where('r.sales_invoice_id', $invoice->getKey())
            ->whereIn('r.approval_status', ['pending', 'approved'])
            ->when($exceptReturnId !== null, fn ($q) => $q->where('r.id', '<>', $exceptReturnId))
            ->groupBy('i.sales_invoice_item_id')
            ->selectRaw('i.sales_invoice_item_id as line, sum(i.quantity) as quantity')
            ->pluck('quantity', 'line');

        $left = [];

        foreach (SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->get(['id', 'quantity']) as $line) {
            $left[(int) $line->id] = bcsub((string) $line->quantity, (string) ($taken[$line->id] ?? '0'), 3);
        }

        return $left;
    }

    /**
     * Price the lines without saving: what the form shows while it is being filled in. Errors are collected, not thrown.
     *
     * @param  array<int|string, mixed>  $quantities  invoice line id => quantity as typed
     * @return array{lines: list<array<string, mixed>>, totals: array<string, string>|null, cost_total: string|null, errors: array<string, string>}
     */
    public function preview(SalesInvoice $invoice, array $quantities, ?int $exceptReturnId = null): array
    {
        $left = $this->returnable($invoice, $exceptReturnId);
        $invoiceLines = SalesInvoiceItem::query()->with('unit:id,code,name,decimal_allowed')->where('sales_invoice_id', $invoice->getKey())->orderBy('line_no')->get()->keyBy('id');
        $lines = [];
        $errors = [];
        $costKnown = true;
        $cost = '0.00';

        foreach ($quantities as $lineId => $typed) {
            $typed = trim((string) $typed);

            if ($typed === '' || (is_numeric($typed) && bccomp($typed, '0', 3) === 0)) {
                continue;
            }

            $key = "lines.$lineId";
            $line = $invoiceLines->get((int) $lineId);

            if ($line === null) {
                $errors[$key] = 'That is not a line of this invoice.';

                continue;
            }

            if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $typed) !== 1 || bccomp($typed, '0', 3) <= 0) {
                $errors[$key] = 'Enter a quantity greater than zero, with up to 3 decimals.';

                continue;
            }

            $quantity = bcadd($typed, '0', 3);

            if ($line->unit instanceof Unit && ! $this->units->accepts($line->unit, $quantity)) {
                $errors[$key] = "{$line->unit->name} cannot be split: enter a whole number.";

                continue;
            }

            if (bccomp($quantity, $left[(int) $lineId] ?? '0', 3) > 0) {
                $errors[$key] = 'Only '.rtrim(rtrim($left[(int) $lineId] ?? '0', '0'), '.').' of this line can still be returned.';

                continue;
            }

            $figures = $this->tax->line($quantity, (string) $line->rate, (string) $line->discount_percent, (string) $line->gst_rate, $invoice->supply_type);
            $unitCost = $line->unit_cost;
            $costAmount = $unitCost === null ? null : bcadd(bcmul($quantity, (string) $unitCost, 7), '0.005', 2);
            $costKnown = $costKnown && $costAmount !== null;
            $cost = $costAmount === null ? $cost : bcadd($cost, $costAmount, 2);

            $lines[] = ['invoice_line' => $line, 'quantity' => $quantity, 'figures' => $figures, 'unit_cost' => $unitCost, 'cost_amount' => $costAmount];
        }

        return [
            'lines' => $lines,
            'totals' => $lines === [] ? null : $this->tax->document(array_map(fn (array $l) => $l['figures'], $lines)),
            'cost_total' => $lines === [] || ! $costKnown ? null : $cost,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws FieldRuleException
     * @throws ValidationException
     */
    public function create(SalesInvoice $invoice, array $input, User $actor): SalesReturn
    {
        return DB::transaction(function () use ($invoice, $input, $actor) {
            $fresh = $this->lockedInvoice($invoice, $actor);
            $header = $this->header($fresh, $input);

            // The number first, before anything else is written.
            $number = $this->numbers->next(DocumentType::SalesReturn, (int) $fresh->location_id, now()->startOfDay());

            $priced = $this->pricedOrFail($fresh, $input, null);

            $return = new SalesReturn;
            $return->forceFill([
                'company_id' => (int) $fresh->company_id, 'location_id' => (int) $fresh->location_id, 'return_number' => $number,
                'sales_invoice_id' => $fresh->getKey(), 'customer_ledger_id' => $fresh->customer_ledger_id, 'assigned_to' => $fresh->assigned_to,
                'party_name' => $fresh->party_name, 'supply_type' => $fresh->supply_type,
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ])->forceFill($header + $this->totals($priced));
            $return->save();

            $this->writeLines($return, $priced['lines'], $actor);

            activity('sales_returns')->causedBy($actor)->performedOn($return)->event('created')
                ->withProperties(['invoice' => $fresh->invoice_number, 'type' => $return->return_type, 'grand_total' => (string) $return->grand_total])->log('created');

            return $return->refresh();
        }, 3);
    }

    /**
     * Change a draft (or a returned) return.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws FieldRuleException
     * @throws ValidationException
     */
    public function update(SalesReturn $return, array $input, User $actor): SalesReturn
    {
        return DB::transaction(function () use ($return, $input, $actor) {
            $fresh = SalesReturn::query()->lockForUpdate()->findOrFail($return->getKey());

            if (! $fresh->isEditableByWorkflow()) {
                throw new FieldRuleException('A sales return that is waiting for approval or approved cannot be changed.');
            }

            $invoice = $this->lockedInvoice($fresh->invoice, $actor);
            $header = $this->header($invoice, $input);
            $priced = $this->pricedOrFail($invoice, $input, (int) $fresh->getKey());

            $fresh->items()->get()->each->forceDelete();   // outright, not soft: the old lines must free their unique keys (line number, source line)
            $fresh->forceFill(['updated_by' => $actor->getKey()] + $header + $this->totals($priced))->save();
            $this->writeLines($fresh, $priced['lines'], $actor);

            activity('sales_returns')->causedBy($actor)->performedOn($fresh)->event('updated')
                ->withProperties(['grand_total' => (string) $fresh->grand_total])->log('updated');

            return $fresh->refresh();
        }, 3);
    }

    /** Why a return cannot be sent for approval right now, or null. Locks the invoice so the answer holds until the submission commits. */
    public function problemBeforeSubmit(SalesReturn $return): ?string
    {
        $invoice = SalesInvoice::query()->lockForUpdate()->find($return->sales_invoice_id);

        if ($invoice === null || $invoice->status !== SalesInvoice::ISSUED) {
            return 'The invoice has been cancelled, so nothing can be returned against it.';
        }

        $left = $this->returnable($invoice, (int) $return->getKey());

        foreach ($return->items()->get() as $line) {
            if (bccomp((string) $line->quantity, $left[$line->sales_invoice_item_id] ?? '0', 3) > 0) {
                return "Line {$line->line_no} ({$line->item_name}) returns more than the invoice still allows: other returns have taken part of it.";
            }
        }

        return null;
    }

    // ---- internals ------------------------------------------------------------------------------------------------

    /** @throws FieldRuleException */
    private function lockedInvoice(SalesInvoice $invoice, User $actor): SalesInvoice
    {
        $fresh = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
        $reach = $this->tenant->accessibleLocationIdsFor($actor, (int) $fresh->company_id);

        if ($reach !== null && ! in_array((int) $fresh->location_id, $reach, true)) {
            throw new FieldRuleException('This is in a branch you do not work in.');
        }

        if ($fresh->status !== SalesInvoice::ISSUED) {
            throw new FieldRuleException('This invoice has been cancelled, so nothing can be returned against it.');
        }

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{return_type: string, return_date: string, reason: string}
     *
     * @throws ValidationException
     */
    private function header(SalesInvoice $invoice, array $input): array
    {
        $errors = [];
        $type = (string) ($input['return_type'] ?? '');
        $reason = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['reason'] ?? '')));
        $date = (string) ($input['return_date'] ?? '') === '' ? now()->toDateString() : (string) $input['return_date'];

        if (! in_array($type, [SalesReturn::RETURN, SalesReturn::CREDIT_NOTE], true)) {
            $errors['return_type'] = 'Choose whether this is a return of goods or a credit note.';
        }

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) {
            $errors['reason'] = 'Please say why (3 to 1000 characters).';
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            $errors['return_date'] = 'Enter a valid date.';
        } elseif ($date < $invoice->invoice_date->toDateString() || $date > now()->toDateString()) {
            $errors['return_date'] = 'The date must be between the invoice date and today.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['return_type' => $type, 'return_date' => $date, 'reason' => $reason];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{lines: list<array<string, mixed>>, totals: array<string, string>|null, cost_total: string|null, errors: array<string, string>}
     *
     * @throws ValidationException
     */
    private function pricedOrFail(SalesInvoice $invoice, array $input, ?int $exceptReturnId): array
    {
        $priced = $this->preview($invoice, (array) ($input['lines'] ?? []), $exceptReturnId);

        if ($priced['errors'] !== []) {
            throw ValidationException::withMessages($priced['errors']);
        }

        if ($priced['lines'] === []) {
            throw ValidationException::withMessages(['lines' => 'Enter the quantity to return on at least one line.']);
        }

        return $priced;
    }

    /**
     * @param  array{totals: array<string, string>|null, cost_total: string|null}  $priced
     * @return array<string, string|null>
     */
    private function totals(array $priced): array
    {
        $t = $priced['totals'] ?? [];

        return [
            'gross_total' => $t['gross'] ?? '0.00', 'discount_total' => $t['discount'] ?? '0.00', 'taxable_total' => $t['taxable'] ?? '0.00',
            'cgst_total' => $t['cgst'] ?? '0.00', 'sgst_total' => $t['sgst'] ?? '0.00', 'igst_total' => $t['igst'] ?? '0.00',
            'round_off' => $t['round_off'] ?? '0.00', 'grand_total' => $t['grand_total'] ?? '0.00', 'cost_total' => $priced['cost_total'],
        ];
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function writeLines(SalesReturn $return, array $lines, User $actor): void
    {
        foreach ($lines as $i => $l) {
            /** @var SalesInvoiceItem $source */
            $source = $l['invoice_line'];
            $f = $l['figures'];

            $line = new SalesReturnItem;
            $line->forceFill([
                'company_id' => (int) $return->company_id, 'sales_return_id' => $return->getKey(), 'sales_invoice_item_id' => $source->getKey(), 'line_no' => $i + 1,
                'item_id' => $source->item_id, 'unit_id' => $source->unit_id, 'item_name' => $source->item_name, 'hsn_code' => $source->hsn_code,
                'quantity' => $l['quantity'], 'rate' => $source->rate, 'discount_percent' => $source->discount_percent, 'gst_rate' => $source->gst_rate,
                'gross_amount' => $f['gross'], 'discount_amount' => $f['discount'], 'taxable_amount' => $f['taxable'],
                'cgst_amount' => $f['cgst'], 'sgst_amount' => $f['sgst'], 'igst_amount' => $f['igst'], 'line_total' => $f['total'],
                'unit_cost' => $l['unit_cost'], 'cost_amount' => $l['cost_amount'],
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ])->save();
        }
    }
}
