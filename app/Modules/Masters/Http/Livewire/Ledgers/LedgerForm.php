<?php

namespace App\Modules\Masters\Http\Livewire\Ledgers;

use App\Models\User;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\PaymentMethod;
use App\Modules\Masters\Models\State;
use App\Modules\Masters\Services\LedgerService;
use App\Modules\Masters\Services\PinCodeLookup;
use App\Modules\Shared\Rules\IndianPinCode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Create or edit a ledger with its addresses, contacts and (for those who may see them) bank details.
 *
 * The component only holds form state. Every rule lives in LedgerService, which is also what checks the data:
 * nothing here is trusted just because the form sent it — the type, the permission to see bank details and the
 * ledger being edited are server-held (locked) values, and the service re-derives what the user may do.
 */
#[Title('Ledger')]
class LedgerForm extends Component
{
    #[Locked]
    public ?int $ledgerId = null;

    #[Locked]
    public string $type = '';

    /** Whether this user may see and edit the bank account number (drives the screen; the service decides for real). */
    #[Locked]
    public bool $canSensitive = false;

    /** @var array<string, mixed> */
    public array $ledger = [];

    /** @var list<array<string, mixed>> */
    public array $addresses = [];

    /** @var list<array<string, mixed>> */
    public array $contacts = [];

    /** @var array<string, mixed> */
    public array $bank = [];

    public bool $hasBank = false;

    /** What anyone without the sensitive permission sees of an existing account, e.g. "HDFC Bank · XXXXXX1234". */
    #[Locked]
    public string $bankSummary = '';

    /** @var array<int, string> PIN code hints per address row */
    public array $pinHints = [];

    public function mount(LedgerService $service, ?string $type = null, ?Ledger $editing = null): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        if ($editing?->exists) {
            Gate::authorize('update', $editing);

            $this->ledgerId = $editing->id;
            $this->type = $editing->ledger_type->value;
            $this->canSensitive = Gate::allows('viewSensitive', $editing);
            $this->loadFrom($editing);

            return;
        }

        $kind = LedgerType::tryFrom((string) $type) ?? abort(404);
        Gate::authorize('createOfType', [Ledger::class, $kind]);

        $this->type = $kind->value;
        $this->canSensitive = $user->can('ledger.view_sensitive');
        $this->ledger = [
            'code' => '', 'name' => '', 'legal_name' => '',
            'account_group_id' => (string) ($service->defaultGroupId($kind) ?? ''),
            'customer_kind' => $kind === LedgerType::Customer ? 'b2b' : '',
            'gstin' => '', 'pan' => '', 'email' => '', 'phone' => '',
            'credit_days' => '', 'credit_limit' => '', 'lead_time_days' => '',
            'payment_preferences' => [], 'status' => 'active', 'remarks' => '',
        ];

        if ($kind->isParty()) {
            $this->addresses = [$this->blankAddress(first: true)];
            $this->contacts = [$this->blankContact()];
        }

        if ($kind->requiresBankDetails() && $this->canSensitive) {
            $this->hasBank = true;
            $this->bank = $this->blankBank();
        }
    }

    private function kind(): LedgerType
    {
        return LedgerType::from($this->type);
    }

    // ---- repeaters ------------------------------------------------------------------------------------------

    public function addAddress(): void
    {
        if (count($this->addresses) < LedgerService::MAX_ROWS) {
            $this->addresses[] = $this->blankAddress(first: $this->addresses === []);
        }
    }

    public function removeAddress(int $index): void
    {
        unset($this->addresses[$index], $this->pinHints[$index]);
        $this->addresses = array_values($this->addresses);
        $this->pinHints = [];
    }

    /** Make one address the default billing / shipping address, and clear the flag on the rest. */
    public function setDefault(int $index, string $kind): void
    {
        abort_unless(in_array($kind, ['billing', 'shipping'], true) && isset($this->addresses[$index]), 404);

        foreach (array_keys($this->addresses) as $i) {
            $this->addresses[$i]["is_default_{$kind}"] = $i === $index;
        }

        $this->addresses[$index]["is_{$kind}"] = true;
    }

    public function addContact(): void
    {
        if (count($this->contacts) < LedgerService::MAX_ROWS) {
            $this->contacts[] = $this->blankContact();
        }
    }

    public function removeContact(int $index): void
    {
        unset($this->contacts[$index]);
        $this->contacts = array_values($this->contacts);
    }

    public function setPrimary(int $index): void
    {
        abort_unless(isset($this->contacts[$index]), 404);

        foreach (array_keys($this->contacts) as $i) {
            $this->contacts[$i]['is_primary'] = $i === $index;
        }
    }

    public function toggleBank(): void
    {
        if (! $this->canSensitive || ! $this->kind()->allowsBankDetails() || $this->kind()->requiresBankDetails()) {
            return;
        }

        $this->hasBank = ! $this->hasBank;
        $this->bank = $this->hasBank && $this->bank === [] ? $this->blankBank() : $this->bank;
    }

    /** A new PIN code fills the state and district when the geography master knows it unambiguously. */
    public function updatedAddresses(mixed $value, string $name): void
    {
        [$index, $field] = array_pad(explode('.', $name, 2), 2, null);

        if (! is_numeric($index) || ! isset($this->addresses[(int) $index])) {
            return;
        }

        $index = (int) $index;

        if ($field === 'state_id') {
            // a district belongs to one state: a different state invalidates it
            $this->addresses[$index]['district_id'] = '';

            return;
        }

        if ($field !== 'pin_code') {
            return;
        }

        $this->pinHints[$index] = '';
        $pin = trim((string) $value);

        if (! IndianPinCode::isValid($pin)) {
            return;
        }

        $result = app(PinCodeLookup::class)->resolve($pin);

        if (! $result->found) {
            $this->pinHints[$index] = 'This PIN code is not in the geography master — choose the state and district yourself.';

            return;
        }

        if ($result->state !== null) {
            $this->addresses[$index]['state_id'] = (string) $result->state->id;
        }

        if ($result->district !== null) {
            $this->addresses[$index]['district_id'] = (string) $result->district->id;

            if (trim((string) $this->addresses[$index]['city']) === '') {
                $this->addresses[$index]['city'] = $result->district->name;
            }
        }

        $this->pinHints[$index] = $result->isAmbiguous()
            ? 'This PIN code covers more than one district or block, so only the unambiguous parts were filled.'
            : 'State and district filled from the PIN code master.';
    }

    // ---- save -----------------------------------------------------------------------------------------------

    public function save(LedgerService $service): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $editing = $this->ledgerId !== null ? Ledger::query()->findOrFail($this->ledgerId) : null;
        Gate::authorize($editing ? 'update' : 'createOfType', $editing ?? [Ledger::class, $this->kind()]);

        // Validation errors thrown by the service are shown against the fields (same paths as this form's state).
        $saved = $service->save($this->kind(), [
            'ledger' => $this->ledger,
            'addresses' => $this->addresses,
            'contacts' => $this->contacts,
            'bank' => $this->hasBank ? $this->bank : null,
        ], $editing, $user);

        session()->flash('status', $editing ? 'Ledger saved.' : 'Ledger added as a draft. Submit it for approval when it is complete.');
        $this->redirectRoute('masters.ledgers.show', $saved, navigate: false);
    }

    public function render(LedgerService $service): View
    {
        $kind = $this->kind();
        $stateIds = collect($this->addresses)->pluck('state_id')->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->unique()->values()->all();

        return view('masters.ledgers.form', [
            'kind' => $kind,
            'editing' => $this->ledgerId !== null,
            'groups' => $service->groupOptions($kind, isset($this->ledger['account_group_id']) && is_numeric($this->ledger['account_group_id']) ? (int) $this->ledger['account_group_id'] : null),
            'states' => State::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'districts' => $stateIds === [] ? collect() : District::query()->whereIn('state_id', $stateIds)->where('status', 'active')->orderBy('name')->get(['id', 'state_id', 'name'])->groupBy('state_id'),
            'paymentMethods' => PaymentMethod::query()->where('status', 'active')->orderBy('name')->pluck('name', 'code')->all(),
            'accountTypes' => LedgerService::ACCOUNT_TYPES,
            'suggestedCode' => $this->ledgerId === null ? $service->nextCode($kind) : null,
            'maxRows' => LedgerService::MAX_ROWS,
        ]);
    }

    // ---- state helpers --------------------------------------------------------------------------------------

    private function loadFrom(Ledger $ledger): void
    {
        $str = fn (mixed $v): string => $v === null ? '' : (string) $v;

        $this->ledger = [
            'code' => $ledger->code, 'name' => $ledger->name, 'legal_name' => $str($ledger->legal_name),
            'account_group_id' => (string) $ledger->account_group_id, 'customer_kind' => $str($ledger->customer_kind),
            'gstin' => $str($ledger->gstin), 'pan' => $str($ledger->pan), 'email' => $str($ledger->email), 'phone' => $str($ledger->phone),
            'credit_days' => $str($ledger->credit_days), 'credit_limit' => $str($ledger->credit_limit), 'lead_time_days' => $str($ledger->lead_time_days),
            'payment_preferences' => $ledger->payment_preferences ?? [], 'status' => $ledger->status, 'remarks' => $str($ledger->remarks),
        ];

        $this->addresses = $ledger->addresses()->get()->map(fn ($a) => [
            'key' => Str::random(6), 'id' => (string) $a->id, 'label' => $str($a->label),
            'is_billing' => $a->is_billing, 'is_shipping' => $a->is_shipping,
            'is_default_billing' => $a->is_default_billing, 'is_default_shipping' => $a->is_default_shipping,
            'line1' => $a->line1, 'line2' => $str($a->line2), 'city' => $str($a->city), 'state_id' => (string) $a->state_id,
            'district_id' => $str($a->district_id), 'pin_code' => $a->pin_code, 'gstin' => $str($a->gstin), 'phone' => $str($a->phone),
        ])->all();

        $this->contacts = $ledger->contacts()->get()->map(fn ($c) => [
            'key' => Str::random(6), 'id' => (string) $c->id, 'name' => $c->name, 'designation' => $str($c->designation),
            'phone' => $str($c->phone), 'mobile' => $str($c->mobile), 'email' => $str($c->email), 'is_primary' => $c->is_primary,
        ])->all();

        $bank = $ledger->bankDetail()->first();
        $this->hasBank = $bank !== null;

        if ($bank !== null) {
            $this->bankSummary = $bank->bank_name.' · '.$bank->masked();

            // The full number reaches the browser only for people allowed to see it.
            $this->bank = [
                'bank_name' => $bank->bank_name, 'branch' => $str($bank->branch), 'account_holder' => $str($bank->account_holder),
                'account_type' => $str($bank->account_type), 'ifsc' => $bank->ifsc,
                'account_number' => $this->canSensitive ? $bank->account_number : '',
            ];
        } elseif ($this->canSensitive && $ledger->ledger_type->requiresBankDetails()) {
            $this->hasBank = true;
            $this->bank = $this->blankBank();
        }
    }

    /** @return array<string, mixed> */
    private function blankAddress(bool $first = false): array
    {
        return [
            'key' => Str::random(6), 'id' => '', 'label' => '',
            'is_billing' => $first, 'is_shipping' => true,
            'is_default_billing' => $first, 'is_default_shipping' => $first,
            'line1' => '', 'line2' => '', 'city' => '', 'state_id' => '', 'district_id' => '', 'pin_code' => '', 'gstin' => '', 'phone' => '',
        ];
    }

    /** @return array<string, mixed> */
    private function blankContact(): array
    {
        return ['key' => Str::random(6), 'id' => '', 'name' => '', 'designation' => '', 'phone' => '', 'mobile' => '', 'email' => '', 'is_primary' => false];
    }

    /** @return array<string, mixed> */
    private function blankBank(): array
    {
        return ['bank_name' => '', 'branch' => '', 'account_holder' => '', 'account_type' => '', 'account_number' => '', 'ifsc' => ''];
    }
}
