<?php

namespace App\Modules\CRM\Http\Livewire\Leads;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Http\Livewire\Concerns\EditsItemRows;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadInterest;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Services\LeadService;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\CRM\Services\SalesTeamService;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Services\LedgerLookup;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Enter an inquiry from an existing customer, or correct one. The customer is chosen from the approved, active customers
 * of the ledger master and fixed once the inquiry is entered; the rest — the branch, who owns it, what the customer asked
 * for with the rate they expect — is typed here and checked again by LeadService, whatever the form sent. The customer's
 * name, contact and address are copied from the ledger by the service, so none of them is asked for.
 */
#[Title('Inquiry')]
class InquiryForm extends Component
{
    use EditsItemRows, ScopesToPeople;

    #[Locked]
    public ?int $leadId = null;

    /** @var array<string, mixed> */
    public array $lead = [];

    /** @var list<array<string, mixed>> */
    public array $interests = [];

    public string $customerSearch = '';

    public string $customerLabel = '';

    protected function rowsProperty(): string
    {
        return 'interests';
    }

    public function mount(?Lead $editing = null): void
    {
        $str = fn (mixed $v): string => $v === null ? '' : (string) $v;

        if ($editing?->exists) {
            abort_unless($editing->isExisting(), 404);   // a new lead has its own form
            Gate::authorize('update', $editing);

            $this->leadId = $editing->id;
            $this->lead = [
                'location_id' => (string) $editing->location_id, 'lead_date' => $editing->lead_date->toDateString(),
                'lead_source_id' => (string) $editing->lead_source_id, 'customer_ledger_id' => (string) $editing->customer_ledger_id,
                'assigned_to' => $str($editing->assigned_to), 'requirement' => $str($editing->requirement), 'remarks' => $str($editing->remarks),
            ];
            $this->customerLabel = trim(($editing->customer->code ?? '').' · '.$editing->name, ' ·');
            $this->interests = $editing->interests()->with(['item:id,code,name'])->get()->map(fn (LeadInterest $row) => [
                ...$this->blankRow(),
                'id' => (string) $row->id, 'item_id' => $str($row->item_id), 'item_label' => $row->item === null ? '' : trim($row->item->code.' · '.$row->item->name),
                'unit_id' => $str($row->unit_id), 'quantity' => $row->quantity === null ? '' : rtrim(rtrim((string) $row->quantity, '0'), '.'),
                'expected_rate' => $row->expected_rate === null ? '' : (string) $row->expected_rate,
            ])->all();

            return;
        }

        Gate::authorize('createInquiry', Lead::class);

        $branches = Location::query()->where('status', 'active')->pluck('id');

        $this->lead = [
            'location_id' => $branches->count() === 1 ? (string) $branches->first() : '', 'lead_date' => now()->toDateString(), 'lead_source_id' => '',
            'customer_ledger_id' => '', 'assigned_to' => (string) $this->actor()->getKey(), 'requirement' => '', 'remarks' => '',
        ];
        $this->addRow();
    }

    // ---- the customer ---------------------------------------------------------------------------------------

    /** Pick a customer from the search results. Only an approved, active customer can be picked. */
    public function pickCustomer(int $id): void
    {
        if ($this->leadId !== null) {
            return;   // fixed once the inquiry is entered
        }

        $customer = app(LedgerLookup::class)->usable($id, LedgerType::Customer);

        if ($customer !== null) {
            $this->lead['customer_ledger_id'] = (string) $customer->getKey();
            $this->customerLabel = trim($customer->code.' · '.$customer->name);
            $this->customerSearch = '';
        }
    }

    public function clearCustomer(): void
    {
        if ($this->leadId === null) {
            $this->lead['customer_ledger_id'] = '';
            $this->customerLabel = '';
            $this->customerSearch = '';
        }
    }

    // ---- save -----------------------------------------------------------------------------------------------

    public function save(LeadService $service): void
    {
        $user = $this->actor();

        $editing = $this->leadId !== null ? Lead::query()->findOrFail($this->leadId) : null;
        Gate::authorize($editing ? 'update' : 'createInquiry', $editing ?? Lead::class);

        // what the last attempt was told is no longer true of this one
        $this->resetErrorBag();

        // An inquiry is for a customer: without one the service would take it for a new lead and ask for a name and a mobile number.
        if ($editing === null && ! ctype_digit((string) ($this->lead['customer_ledger_id'] ?? ''))) {
            $this->addError('lead.customer_ledger_id', 'Choose the customer the inquiry is from.');

            return;
        }

        // Drop the rows nobody filled in, so the service's row numbers are this form's row numbers.
        $this->interests = array_values(array_filter($this->interests, fn (array $row) => trim((string) ($row['id'] ?? '')) !== ''
            || trim((string) ($row['item_id'] ?? '')) !== '' || trim((string) ($row['quantity'] ?? '')) !== '' || trim((string) ($row['expected_rate'] ?? '')) !== ''));

        $input = ['lead' => $this->lead, 'interests' => $this->interests];

        try {
            // Validation errors thrown by the service are shown against the fields (same paths as this form's state).
            $saved = $editing === null ? $service->create($input, $user) : $service->update($editing, $input, $user);
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', $editing ? 'Inquiry saved.' : "Inquiry {$saved->lead_number} added. Send it for approval when it is ready, and plan the first follow-up.");

        // Someone who entered an inquiry for another person may not be able to open it afterwards.
        Gate::allows('view', $saved)
            ? $this->redirectRoute('crm.leads.show', $saved, navigate: false)
            : $this->redirectRoute('crm.leads.index', navigate: false);
    }

    /** What the lines come to, for the person typing: the service works out the real figure when it saves. */
    private function previewTotal(): ?string
    {
        $total = null;

        foreach ($this->interests as $row) {
            $qty = str_replace(',', '', trim((string) ($row['quantity'] ?? '')));
            $rate = str_replace(',', '', trim((string) ($row['expected_rate'] ?? '')));

            if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $qty) === 1 && preg_match('/^\d{1,13}(\.\d{1,2})?$/', $rate) === 1) {
                $total = bcadd($total ?? '0', bcadd(bcmul($qty, $rate, 5), '0.005', 2), 2);
            }
        }

        return $total;
    }

    public function render(SalesTeamService $teams, SalesScope $scope, LedgerLookup $ledgers): View
    {
        $user = $this->actor();
        $id = fn (string $key): ?int => ctype_digit((string) ($this->lead[$key] ?? '')) ? (int) $this->lead[$key] : null;

        // ---- who can own it (only asked of people who may hand leads out, and only when it is first entered) ----
        $canAssign = $this->leadId === null && $user->can('lead.assign');
        $owners = [];
        if ($canAssign) {
            $managed = $scope->managedUserIds($user);
            $staff = $id('location_id') !== null ? $teams->staffInBranch((int) $id('location_id')) : $teams->staff();
            $owners = $managed === null ? $staff : array_intersect_key($staff, array_flip([...$managed, (int) $user->getKey()]));
        }

        $saved = $this->leadId === null ? [] : LeadInterest::query()->where('lead_id', $this->leadId)->whereNotNull('item_id')->pluck('item_id')->map(fn ($v) => (int) $v)->all();
        $help = $this->rowHelp($saved);

        $term = trim($this->customerSearch);

        return view('crm.leads.inquiry-form', [
            'editing' => $this->leadId !== null,
            'leadNumber' => $this->leadId === null ? null : Lead::query()->whereKey($this->leadId)->value('lead_number'),
            'branches' => Location::query()->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $id('location_id') ?? 0))->orderBy('name')->pluck('name', 'id')->all(),
            'sources' => LeadSource::query()->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $id('lead_source_id') ?? 0))->orderBy('name')->pluck('name', 'id')->all(),
            'canAssign' => $canAssign,
            'owners' => $owners,
            'ownerName' => $this->leadId !== null && $id('assigned_to') !== null ? ($teams->staff()[(int) $id('assigned_to')] ?? null) : null,
            'customers' => $this->leadId === null && $term !== '' ? $ledgers->search(LedgerType::Customer, $term, self::MAX_SEARCH_RESULTS)->map(fn (Ledger $c) => ['id' => (int) $c->getKey(), 'label' => trim($c->code.' · '.$c->name)])->all() : [],
            'itemUnits' => $help['itemUnits'],
            'results' => $help['results'],
            'maxInterests' => LeadService::MAX_INTERESTS,
            'preview' => $this->previewTotal(),
            'today' => now()->toDateString(),
        ]);
    }
}
