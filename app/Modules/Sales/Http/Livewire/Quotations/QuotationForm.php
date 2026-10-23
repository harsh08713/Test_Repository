<?php

namespace App\Modules\Sales\Http\Livewire\Quotations;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Http\Livewire\Concerns\EditsItemRows;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\DealService;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\Masters\Models\PriceList;
use App\Modules\Masters\Models\State;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\QuotationItem;
use App\Modules\Sales\Services\QuotationPricer;
use App\Modules\Sales\Services\QuotationService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Make a quotation from a deal or an approved inquiry, or change a draft. The screen only holds what was typed: every rule — that the
 * source is approved and open, that each line is an approved item priced from the rate master, the discounts, the place of supply, the
 * tax and the totals — lives in QuotationService / QuotationPricer, which check the data again whatever the form sent. What is shown
 * under each line (the rate, the tax, the total) is the pricer's own answer for what is typed so far; it is worked out again, and
 * stored, only when the quotation is saved.
 */
#[Title('Quotation')]
class QuotationForm extends Component
{
    use EditsItemRows, ScopesToPeople;

    #[Locked]
    public ?int $leadId = null;

    #[Locked]
    public ?int $sourceDealId = null;

    #[Locked]
    public ?int $quotationId = null;

    /** @var array<string, mixed> */
    public array $q = [];

    /** @var list<array<string, mixed>> */
    public array $items = [];

    protected function rowsProperty(): string
    {
        return 'items';
    }

    /** @return array<string, mixed> */
    protected function blankRow(): array
    {
        return [
            'key' => Str::random(6), 'id' => '', 'deal_item_id' => '', 'lead_interest_id' => '', 'locked_item' => false, 'item_id' => '', 'item_label' => '',
            'description' => '', 'unit_id' => '', 'quantity' => '', 'discount_percent' => '0', 'rate_override' => '', 'override_reason' => '', 'search' => '',
        ];
    }

    /** A quotation's lines may be dropped (a partial offer): the source's lines are where it starts, not what it must keep. */
    public function removeRow(int $index): void
    {
        $rows = $this->rows();

        if (isset($rows[$index])) {
            unset($rows[$index]);
            $this->setRows($rows);
        }
    }

    public function mount(?int $deal = null, ?int $inquiry = null, ?Quotation $editing = null): void
    {
        $str = fn (mixed $v): string => $v === null ? '' : (string) $v;

        // ---- changing a draft ----
        if ($editing?->exists) {
            Gate::authorize('update', $editing);

            $this->quotationId = $editing->id;
            $this->leadId = (int) $editing->lead_id;
            $this->sourceDealId = $editing->deal_id === null ? null : (int) $editing->deal_id;
            $this->q = [
                'valid_until' => $editing->valid_until->toDateString(), 'price_list_id' => $str($editing->price_list_id), 'place_of_supply_state_id' => $str($editing->place_of_supply_state_id),
                'party_name' => $editing->party_name, 'contact_person' => $str($editing->contact_person), 'mobile' => $str($editing->mobile), 'email' => $str($editing->email),
                'bill_line1' => $str($editing->bill_line1), 'bill_line2' => $str($editing->bill_line2), 'bill_city' => $str($editing->bill_city), 'bill_pin_code' => $str($editing->bill_pin_code),
                'customer_gstin' => $str($editing->customer_gstin), 'terms' => $str($editing->terms), 'remarks' => $str($editing->remarks), 'reprice' => '',
            ];
            $this->items = $editing->items()->with('item:id,code,name')->get()->map(fn (QuotationItem $line) => [
                ...$this->blankRow(),
                'id' => (string) $line->id, 'deal_item_id' => $str($line->deal_item_id), 'lead_interest_id' => $str($line->lead_interest_id),
                'item_id' => (string) $line->item_id, 'item_label' => $line->item === null ? $line->item_name : trim($line->item->code.' · '.$line->item->name),
                'unit_id' => (string) $line->unit_id, 'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'),
                'discount_percent' => rtrim(rtrim((string) $line->discount_percent, '0'), '.') ?: '0',
                'rate_override' => $line->rate_overridden ? rtrim(rtrim((string) $line->rate, '0'), '.') : '', 'override_reason' => $str($line->override_reason),
            ])->all();

            return;
        }

        // ---- making one ----
        abort_if($deal === null && $inquiry === null, 404);
        Gate::authorize('create', Quotation::class);

        $source = $deal !== null ? Deal::query()->with('lead')->findOrFail($deal) : null;
        $lead = $source->lead ?? Lead::query()->with(['customer'])->findOrFail($inquiry);

        if ($source !== null) {
            Gate::authorize('work', $source);
        } else {
            Gate::authorize('view', $lead);
            abort_unless(app(LeadAccess::class)->works($this->actor(), $lead), 403);
        }

        // Say what is missing rather than show a form that cannot be saved.
        $back = $source !== null ? route('crm.deals.show', $source) : route('crm.leads.show', $lead);

        if ($source !== null && ! ($source->isApproved() && $source->isOpen())) {
            session()->flash('status', $source->isApproved() ? 'A deal that is '.strtolower($source->stage->label()).' cannot be quoted.' : 'The deal has to be approved before it can be quoted.');
            $this->redirect($back, navigate: false);

            return;
        }

        if ($source === null) {
            $made = app(DealService::class)->dealOf($lead);

            if ($made !== null || ! $lead->isReadyForQuotation()) {
                session()->flash('status', $made !== null ? "This inquiry has been converted to deal {$made->deal_number}: make the quotation from the deal." : 'Only an approved inquiry that is still open can be quoted directly.');
                $this->redirect($made !== null && Gate::allows('view', $made) ? route('crm.deals.show', $made) : $back, navigate: false);

                return;
            }
        }

        $this->leadId = $lead->id;
        $this->sourceDealId = $source?->id;
        $this->q = [...app(QuotationPricer::class)->defaults($lead, $source), 'reprice' => ''];

        $this->items = array_map(fn (array $row) => [
            ...$this->blankRow(),
            'deal_item_id' => $str($row['deal_item_id']), 'lead_interest_id' => $str($row['lead_interest_id']),
            'item_id' => $str($row['item_id']), 'item_label' => $row['item_label'], 'unit_id' => $str($row['unit_id']),
            'quantity' => $row['quantity'] === null ? '' : rtrim(rtrim((string) $row['quantity'], '0'), '.'),
        ], app(QuotationService::class)->startingLines($lead, $source));

        if ($this->items === []) {
            $this->addRow();
        }
    }

    // ---- save -----------------------------------------------------------------------------------------------

    public function save(QuotationService $service): void
    {
        $user = $this->actor();
        $editing = $this->quotationId !== null ? Quotation::query()->findOrFail($this->quotationId) : null;
        $deal = $this->sourceDealId !== null ? Deal::query()->findOrFail($this->sourceDealId) : null;
        $lead = Lead::query()->findOrFail($this->leadId);

        $this->resetErrorBag();
        Gate::authorize($editing ? 'update' : 'create', $editing ?? Quotation::class);

        // Drop the rows nobody filled in, so the service's row numbers are this form's row numbers.
        $this->items = array_values(array_filter($this->items, fn (array $row) => trim((string) ($row['id'] ?? '')) !== '' || trim((string) ($row['deal_item_id'] ?? '')) !== ''
            || trim((string) ($row['lead_interest_id'] ?? '')) !== '' || trim((string) ($row['item_id'] ?? '')) !== '' || trim((string) ($row['quantity'] ?? '')) !== ''));

        $input = ['quotation' => $this->q, 'items' => $this->items];

        try {
            // Validation errors thrown by the service are shown against the fields (same paths as this form's state).
            $saved = $editing === null ? $service->create($deal ?? $lead, $input, $user) : $service->update($editing, $input, $user);
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', $editing ? 'Quotation saved.' : "Quotation {$saved->quotation_number} made. Check it, then send it for approval.");

        $this->redirectRoute('sales.quotations.show', $saved, navigate: false);
    }

    // ---- what the person sees while typing -----------------------------------------------------------------

    /**
     * The pricer's answer for what is typed so far: per row its rate and amounts, and the totals once everything is right — or, per row,
     * why it cannot be priced yet. Nothing is saved or audited by this.
     *
     * @return array{lines: array<int, array<string, mixed>>, totals: array<string, string>|null, notes: array<int, string>, supply: string|null}
     */
    private function preview(): array
    {
        $none = ['lines' => [], 'totals' => null, 'notes' => [], 'supply' => null];
        $rows = array_filter($this->items, fn (array $row) => trim((string) ($row['item_id'] ?? '')) !== '' && trim((string) ($row['quantity'] ?? '')) !== '');

        if ($rows === []) {
            return $none;
        }

        $editing = $this->quotationId !== null ? Quotation::query()->find($this->quotationId) : null;
        $lead = Lead::query()->findOrFail($this->leadId);
        $deal = $this->sourceDealId !== null ? Deal::query()->find($this->sourceDealId) : null;

        $run = app(QuotationPricer::class)->preview(['quotation' => $this->q, 'items' => $this->items], $editing, $lead, $deal, $this->actor(), (int) $lead->location_id);

        $notes = [];

        foreach ($run['errors'] as $key => $messages) {
            if (preg_match('/^items\.(\d+)\.item_id$/', $key, $m) === 1) {
                $notes[(int) $m[1]] = $messages[0];
            }
        }

        return [
            'lines' => $run['lines'], 'totals' => $run['errors'] === [] ? $run['totals'] : null, 'notes' => $notes,
            'supply' => isset($run['quotation']['supply_type']) ? (string) $run['quotation']['supply_type'] : null,
        ];
    }

    public function render(): View
    {
        $lead = Lead::query()->with(['location:id,name', 'assignee:id,name'])->findOrFail($this->leadId);
        $deal = $this->sourceDealId === null ? null : Deal::query()->find($this->sourceDealId);
        $saved = $this->quotationId === null ? [] : QuotationItem::query()->where('quotation_id', $this->quotationId)->pluck('item_id')->map(fn ($v) => (int) $v)->all();
        $help = $this->rowHelp($saved);
        $preview = $this->preview();

        return view('sales.quotations.form', [
            'editing' => $this->quotationId !== null,
            'quotationNumber' => $this->quotationId === null ? null : Quotation::query()->whereKey($this->quotationId)->first()?->displayNumber(),
            'lead' => $lead,
            'deal' => $deal,
            'states' => State::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'gst_code']),
            'priceLists' => PriceList::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'itemUnits' => $help['itemUnits'],
            'results' => $help['results'],
            'maxLines' => DealService::MAX_LINES,
            'preview' => $preview,
            'canOverride' => $this->actor()->can('rate.override'),
            'today' => now()->toDateString(),
            'backUrl' => $this->quotationId !== null ? route('sales.quotations.show', $this->quotationId) : ($deal !== null ? route('crm.deals.show', $deal) : route('crm.leads.show', $lead)),
        ]);
    }
}
