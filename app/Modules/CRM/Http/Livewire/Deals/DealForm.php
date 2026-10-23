<?php

namespace App\Modules\CRM\Http\Livewire\Deals;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Http\Livewire\Concerns\EditsItemRows;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\DealItem;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\DealService;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Services\LedgerLookup;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Turn a qualified lead into a deal, or correct a deal that is still a draft. The screen only holds what was typed: every
 * rule — that the lead is qualified and still unconverted, that each line is an approved item in a unit it is sold in with
 * a quantity the unit allows, that every line of the lead comes across, the amounts and the total — lives in DealService,
 * which checks the data again whatever the form sent. The total shown here is only a preview.
 */
#[Title('Deal')]
class DealForm extends Component
{
    use EditsItemRows, ScopesToPeople;

    /** The lead the deal is (or is being) made from. */
    #[Locked]
    public ?int $leadId = null;

    #[Locked]
    public ?int $dealId = null;

    /** An inquiry's deal is for the inquiry's customer: it is not chosen here. */
    #[Locked]
    public bool $customerFixed = false;

    /** @var array<string, mixed> */
    public array $deal = [];

    /** @var list<array<string, mixed>> */
    public array $items = [];

    public string $customerSearch = '';

    public string $customerLabel = '';

    protected function rowsProperty(): string
    {
        return 'items';
    }

    public function mount(?int $source = null, ?Deal $editing = null): void
    {
        $str = fn (mixed $v): string => $v === null ? '' : (string) $v;

        // ---- correcting a deal that is still a draft (or was returned) ----
        if ($editing?->exists) {
            Gate::authorize('update', $editing);

            $lead = $editing->lead()->firstOrFail();

            $this->dealId = $editing->id;
            $this->leadId = $lead->id;
            $this->customerFixed = $lead->isExisting();
            $this->deal = [
                'customer_ledger_id' => $str($editing->customer_ledger_id), 'expected_close_date' => $editing->expected_close_date->toDateString(),
                'probability' => (string) $editing->probability, 'remarks' => $str($editing->remarks),
            ];
            $this->customerLabel = $editing->customer === null ? '' : trim($editing->customer->code.' · '.$editing->customer->name);
            $this->items = $editing->items()->with(['item:id,code,name'])->get()->map(fn (DealItem $line) => [
                ...$this->blankRow(),
                'id' => (string) $line->id, 'item_id' => (string) $line->item_id, 'item_label' => $line->item === null ? '' : trim($line->item->code.' · '.$line->item->name),
                'unit_id' => (string) $line->unit_id, 'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'), 'expected_rate' => (string) $line->expected_rate,
            ])->all();

            return;
        }

        // ---- converting a lead ----
        abort_if($source === null, 404);

        $lead = Lead::query()->findOrFail($source);
        $made = app(DealService::class)->dealOf($lead);

        if ($made !== null) {
            session()->flash('status', "This lead has already been converted to deal {$made->deal_number}.");
            Gate::allows('view', $made)
                ? $this->redirectRoute('crm.deals.show', $made, navigate: false)
                : $this->redirectRoute('crm.leads.show', $lead, navigate: false);

            return;
        }

        Gate::authorize('convert', $lead);

        $this->leadId = $lead->id;
        $this->customerFixed = $lead->isExisting();
        $this->deal = [
            'customer_ledger_id' => $str($lead->customer_ledger_id), 'expected_close_date' => now()->addDays(30)->toDateString(), 'probability' => '10', 'remarks' => '',
        ];
        $this->customerLabel = $lead->customer === null ? '' : trim($lead->customer->code.' · '.$lead->customer->name);

        // Every line the lead listed starts as a row; a line the lead only described still needs its item chosen.
        $this->items = array_map(fn (array $row) => [
            ...$this->blankRow(),
            'lead_interest_id' => (string) $row['lead_interest_id'], 'locked_item' => $row['item_id'] !== null,
            'item_id' => $str($row['item_id']), 'item_label' => $row['item_label'], 'description' => (string) ($row['description'] ?? ''),
            'unit_id' => $str($row['unit_id']), 'quantity' => $row['quantity'] === null ? '' : rtrim(rtrim((string) $row['quantity'], '0'), '.'),
            'expected_rate' => $row['expected_rate'] === null ? '' : (string) $row['expected_rate'],
        ], app(DealService::class)->linesFromLead($lead));

        if ($this->items === []) {
            $this->addRow();
        }
    }

    // ---- the customer (a deal from a new lead may be linked to a customer ledger) ----------------------------

    /** Pick a customer from the search results. Only an approved, active customer can be picked. */
    public function pickCustomer(int $id): void
    {
        if ($this->customerFixed) {
            return;
        }

        $customer = app(LedgerLookup::class)->usable($id, LedgerType::Customer);

        if ($customer !== null) {
            $this->deal['customer_ledger_id'] = (string) $customer->getKey();
            $this->customerLabel = trim($customer->code.' · '.$customer->name);
            $this->customerSearch = '';
        }
    }

    public function clearCustomer(): void
    {
        if (! $this->customerFixed) {
            $this->deal['customer_ledger_id'] = '';
            $this->customerLabel = '';
            $this->customerSearch = '';
        }
    }

    // ---- save -----------------------------------------------------------------------------------------------

    public function save(DealService $service): void
    {
        $user = $this->actor();

        $lead = Lead::query()->findOrFail($this->leadId);
        $editing = $this->dealId !== null ? Deal::query()->findOrFail($this->dealId) : null;

        // what the last attempt was told is no longer true of this one
        $this->resetErrorBag();

        // Somebody else may have converted the lead while this form was open: say so, rather than turn the person away.
        $made = $editing === null ? $service->dealOf($lead) : null;

        if ($made !== null) {
            $this->addError('form', "This lead has already been converted to deal {$made->deal_number}.");

            return;
        }

        Gate::authorize($editing ? 'update' : 'convert', $editing ?? $lead);

        // Drop the rows nobody filled in, so the service's row numbers are this form's row numbers.
        $this->items = array_values(array_filter($this->items, fn (array $row) => trim((string) ($row['id'] ?? '')) !== '' || trim((string) ($row['lead_interest_id'] ?? '')) !== ''
            || trim((string) ($row['item_id'] ?? '')) !== '' || trim((string) ($row['quantity'] ?? '')) !== '' || trim((string) ($row['expected_rate'] ?? '')) !== ''));

        $input = ['deal' => $this->deal, 'items' => $this->items];

        try {
            // Validation errors thrown by the service are shown against the fields (same paths as this form's state).
            $saved = $editing === null ? $service->convert($lead, $input, $user) : $service->update($editing, $input, $user);
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', $editing ? 'Deal saved.' : "Deal {$saved->deal_number} made from lead {$lead->lead_number}. Send it for approval when it is ready.");

        $this->redirectRoute('crm.deals.show', $saved, navigate: false);
    }

    /** What the lines come to, for the person typing: the service works out the real figure when it saves. */
    private function previewTotal(): ?string
    {
        $total = null;

        foreach ($this->items as $row) {
            $qty = str_replace(',', '', trim((string) ($row['quantity'] ?? '')));
            $rate = str_replace(',', '', trim((string) ($row['expected_rate'] ?? '')));

            if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $qty) === 1 && preg_match('/^\d{1,13}(\.\d{1,2})?$/', $rate) === 1) {
                $total = bcadd($total ?? '0', bcadd(bcmul($qty, $rate, 5), '0.005', 2), 2);
            }
        }

        return $total;
    }

    public function render(LedgerLookup $ledgers): View
    {
        $lead = Lead::query()->with(['location:id,name', 'assignee:id,name'])->findOrFail($this->leadId);
        $saved = $this->dealId === null ? [] : DealItem::query()->where('deal_id', $this->dealId)->pluck('item_id')->map(fn ($v) => (int) $v)->all();
        $help = $this->rowHelp($saved);
        $term = trim($this->customerSearch);

        return view('crm.deals.form', [
            'editing' => $this->dealId !== null,
            'dealNumber' => $this->dealId === null ? null : Deal::query()->whereKey($this->dealId)->value('deal_number'),
            'lead' => $lead,
            'customers' => ! $this->customerFixed && $term !== '' ? $ledgers->search(LedgerType::Customer, $term, self::MAX_SEARCH_RESULTS)->map(fn (Ledger $c) => ['id' => (int) $c->getKey(), 'label' => trim($c->code.' · '.$c->name)])->all() : [],
            'itemUnits' => $help['itemUnits'],
            'results' => $help['results'],
            'maxLines' => DealService::MAX_LINES,
            'preview' => $this->previewTotal(),
            'today' => now()->toDateString(),
        ]);
    }
}
