<?php

namespace App\Modules\Masters\Services;

use App\Models\User;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\AccountGroup;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\LedgerAddress;
use App\Modules\Masters\Models\LedgerBankDetail;
use App\Modules\Masters\Models\LedgerContact;
use App\Modules\Masters\Models\State;
use App\Modules\Masters\Support\Hierarchy;
use App\Modules\Shared\Rules\Gstin;
use App\Modules\Shared\Rules\Ifsc;
use App\Modules\Shared\Rules\IndianMobile;
use App\Modules\Shared\Rules\IndianPinCode;
use App\Modules\Shared\Rules\Pan;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates and changes ledgers together with their addresses, contacts and bank details.
 *
 * It is the single gate for ledger data: whatever calls it (the form, a seeder, a future import) gets the same
 * validation, normalisation and rules, and everything is written in one transaction. It does not decide who may
 * do it (policies do) and it does not submit for approval (ApprovalService does).
 *
 * Input shape (the same paths the form binds to, so errors line up with the screen):
 *   ledger    => code, name, legal_name, account_group_id, customer_kind, gstin, pan, email, phone, credit_days,
 *                credit_limit, lead_time_days, payment_preferences[], status, remarks
 *   addresses => [ id?, label, is_billing, is_shipping, is_default_billing, is_default_shipping, line1, line2, city,
 *                  state_id, district_id, pin_code, gstin, phone ]
 *   contacts  => [ id?, name, designation, phone, mobile, email, is_primary ]
 *   bank      => null | [ bank_name, branch, account_holder, account_type, account_number, ifsc ]
 */
final class LedgerService
{
    public const MAX_ROWS = 25;

    public const ACCOUNT_TYPES = ['current' => 'Current', 'savings' => 'Savings', 'cash_credit' => 'Cash credit', 'overdraft' => 'Overdraft'];

    public function __construct(private readonly TenantContext $tenant) {}

    // ---- options for the screens ----------------------------------------------------------------------------

    /**
     * The account groups a ledger of this type may be filed under, as id => "Assets › Current Assets › Sundry Debtors".
     * An editing ledger keeps its current group even if that group has since been deactivated.
     *
     * @return array<int, string>
     */
    public function groupOptions(LedgerType $type, ?int $keepGroupId = null): array
    {
        $groups = AccountGroup::query()->orderBy('code')->get();
        $byId = $groups->keyBy('id');

        $allowed = $groups->filter(fn (AccountGroup $g) => $g->status === 'active' || $g->id === $keepGroupId);

        $roots = $type->groupRootCodes();
        if ($roots !== null) {
            $ids = [];
            foreach ($groups->whereIn('code', $roots) as $root) {
                $ids = [...$ids, $root->id, ...Hierarchy::descendantIds($groups, $root->id)];
            }
            $allowed = $allowed->whereIn('id', $ids);
        }

        return $allowed->mapWithKeys(function (AccountGroup $g) use ($byId) {
            $path = [];
            for ($node = $g, $guard = 0; $node !== null && $guard < 20; $node = $byId->get($node->parent_id), $guard++) {
                array_unshift($path, $node->name);
            }

            return [$g->id => implode(' › ', $path)];
        })->sort()->all();
    }

    /** The group a new ledger of this type starts in (its standard group), if the company still has it. */
    public function defaultGroupId(LedgerType $type): ?int
    {
        $code = $type->groupRootCodes()[0] ?? null;

        return $code === null ? null : AccountGroup::query()->where('code', $code)->value('id');
    }

    /** An account code nobody has used yet, e.g. CUS-00007. Not reserved: a clash at save time just picks the next one. */
    public function nextCode(LedgerType $type): string
    {
        $prefix = $type->codePrefix();

        $last = (int) Ledger::withTrashed()
            ->where('code', 'like', $prefix.'-%')
            ->whereRaw('code regexp ?', ['^'.$prefix.'-[0-9]+$'])
            ->max(DB::raw('cast(substring(code, '.(strlen($prefix) + 2).') as unsigned)'));

        return sprintf('%s-%05d', $prefix, $last + 1);
    }

    // ---- save -----------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function save(LedgerType $type, array $input, ?Ledger $editing, User $by): Ledger
    {
        if ($editing !== null && $editing->ledger_type !== $type) {
            throw new \LogicException('The type of a ledger cannot be changed.');
        }

        $data = $this->validate($type, $input, $editing, $by);

        return DB::transaction(function () use ($type, $data, $editing, $by) {
            $companyId = (int) $this->tenant->activeCompanyId();
            $ledger = $editing ?? new Ledger;
            $ledger->fill(Arr::only($data['ledger'], ['name', 'legal_name', 'account_group_id', 'customer_kind', 'gstin', 'pan', 'email', 'phone',
                'credit_days', 'credit_limit', 'lead_time_days', 'payment_preferences', 'status', 'remarks']));

            if ($editing === null) {
                $ledger->forceFill(['company_id' => $companyId, 'ledger_type' => $type->value, 'created_by' => $by->getKey()]);
            }

            $ledger->forceFill(['updated_by' => $by->getKey()]);
            $this->persistWithCode($ledger, $type, $data['ledger']['code'] ?? null);

            $this->syncChildren($ledger, 'addresses', LedgerAddress::class, $data['addresses'], [
                'label', 'is_billing', 'is_shipping', 'is_default_billing', 'is_default_shipping', 'line1', 'line2', 'city',
                'state_id', 'district_id', 'pin_code', 'gstin', 'phone',
            ], $by);
            $this->syncChildren($ledger, 'contacts', LedgerContact::class, $data['contacts'], ['name', 'designation', 'phone', 'mobile', 'email', 'is_primary'], $by);

            if ($by->can('ledger.view_sensitive') && $type->allowsBankDetails()) {
                $this->syncBank($ledger, $data['bank'], $by);
            }

            return $ledger->refresh();
        });
    }

    /** Insert/update, choosing the account code when none was given and trying again if someone else took it first. */
    private function persistWithCode(Ledger $ledger, LedgerType $type, ?string $code): void
    {
        // A code typed in always wins; a blank one keeps the code an existing ledger already has, and a new ledger gets the next free one.
        if ($code !== null) {
            $ledger->forceFill(['code' => $code]);
        }

        $generate = $code === null && ! $ledger->exists;

        for ($attempt = 1; ; $attempt++) {
            if ($generate) {
                $ledger->forceFill(['code' => $this->nextCode($type)]);
            }

            try {
                DB::transaction(fn () => $ledger->save());

                return;
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), 'name_unique')) {
                    throw ValidationException::withMessages(['ledger.name' => 'A '.strtolower($type->label()).' ledger with this name already exists.']);
                }

                if (! $generate || $attempt >= 5) {
                    throw ValidationException::withMessages(['ledger.code' => 'This account code is already in use.']);
                }
            }
        }
    }

    /**
     * Make a ledger's child rows match the submitted list: update the ones it names, add the new ones, remove the rest.
     *
     * @param  class-string<Model>  $class
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fields
     */
    private function syncChildren(Ledger $ledger, string $relation, string $class, array $rows, array $fields, User $by): void
    {
        $existing = $ledger->{$relation}()->get()->keyBy('id');
        $kept = [];

        foreach ($rows as $index => $row) {
            $id = $row['id'] ?? null;
            $model = null;

            if ($id !== null) {
                $model = $existing->get((int) $id);

                // An id that is not one of this ledger's own rows is a tampered request: never reach for the row.
                if ($model === null) {
                    throw ValidationException::withMessages(["{$relation}.{$index}.id" => 'This row does not belong to the ledger.']);
                }
            }

            $model ??= new $class;
            $model->fill(Arr::only($row, $fields));

            if (! $model->exists) {
                $model->forceFill(['company_id' => $ledger->company_id, 'ledger_id' => $ledger->id, 'created_by' => $by->getKey()]);
            }

            $model->forceFill(['updated_by' => $by->getKey()])->save();
            $kept[] = $model->getKey();
        }

        foreach ($existing as $id => $model) {
            if (! in_array($id, $kept, true)) {
                $model->delete();
            }
        }
    }

    /** @param  array<string, mixed>|null  $bank */
    private function syncBank(Ledger $ledger, ?array $bank, User $by): void
    {
        $current = $ledger->bankDetail()->withTrashed()->first();

        if ($bank === null) {
            if ($current !== null && ! $current->trashed()) {
                $current->delete();
            }

            return;
        }

        $model = $current ?? new LedgerBankDetail;
        $before = $model->exists && ! $model->trashed() ? ['ifsc' => $model->ifsc, 'hash' => $model->account_number_hash] : null;

        $model->fill(Arr::only($bank, ['bank_name', 'branch', 'account_holder', 'account_type', 'ifsc']));

        $number = $bank['account_number'] ?? null;
        if ($number !== null && $number !== '') {
            $model->forceFill([
                'account_number' => $number,
                'account_last4' => substr($number, -4),
                'account_number_hash' => LedgerBankDetail::hashNumber($number),
            ]);
        }

        if (! $model->exists) {
            $model->forceFill(['company_id' => $ledger->company_id, 'ledger_id' => $ledger->id, 'created_by' => $by->getKey()]);
        }

        $model->forceFill(['updated_by' => $by->getKey()]);
        $wasTrashed = $model->exists && $model->trashed();
        $model->save();

        if ($wasTrashed) {
            $model->restore();
        }

        $changed = $before === null
            || $before['hash'] !== $model->account_number_hash
            || $before['ifsc'] !== $model->ifsc;

        if ($changed) {
            // The audit trail records that the account changed and its last four digits — never the number.
            activity('ledger')
                ->performedOn($ledger)
                ->causedBy($by)
                ->event('bank_details_changed')
                ->withProperties(['account_last4' => $model->account_last4, 'ifsc' => $model->ifsc, 'created' => $before === null])
                ->log('bank_details_changed');
        }
    }

    // ---- validation -----------------------------------------------------------------------------------------

    /**
     * Normalise and validate. Returns the cleaned data.
     *
     * @param  array<string, mixed>  $input
     * @return array{ledger: array<string, mixed>, addresses: list<array<string, mixed>>, contacts: list<array<string, mixed>>, bank: array<string, mixed>|null}
     *
     * @throws ValidationException
     */
    public function validate(LedgerType $type, array $input, ?Ledger $editing, User $by): array
    {
        $companyId = (int) $this->tenant->activeCompanyId();
        $canBank = $type->allowsBankDetails() && $by->can('ledger.view_sensitive');
        $existingBank = $editing?->bankDetail()->first();

        $data = $this->normalise($type, $input, $canBank);

        $rules = $this->rules($type, $data, $editing, $companyId, $canBank, $existingBank !== null);

        $validator = Validator::make($data, $rules, $this->messages(), $this->attributeNames($data));
        $validator->after(function ($validator) use ($type, $data, $companyId) {
            $this->crossChecks($validator, $type, $data, $companyId);
        });
        $validator->validate();

        return $this->finalise($data, $type);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ledger: array<string, mixed>, addresses: list<array<string, mixed>>, contacts: list<array<string, mixed>>, bank: array<string, mixed>|null}
     */
    private function normalise(LedgerType $type, array $input, bool $canBank): array
    {
        $text = fn (mixed $v): ?string => ($v = trim((string) preg_replace('/\s+/u', ' ', (string) ($v ?? '')))) === '' ? null : $v;
        $upper = fn (mixed $v): ?string => ($v = $text($v)) === null ? null : strtoupper(str_replace(' ', '', $v));
        $flag = fn (mixed $v): bool => filter_var($v, FILTER_VALIDATE_BOOLEAN);
        $number = fn (mixed $v): ?string => ($v = $text($v)) === null ? null : str_replace(',', '', $v);

        $l = (array) ($input['ledger'] ?? []);
        $party = $type->isParty();

        $ledger = [
            'code' => $upper($l['code'] ?? null),
            'name' => $text($l['name'] ?? null),
            'legal_name' => $text($l['legal_name'] ?? null),
            'account_group_id' => ($g = $text($l['account_group_id'] ?? null)) === null ? null : $g,
            'customer_kind' => $type === LedgerType::Customer ? ($text($l['customer_kind'] ?? null) !== null ? strtolower((string) $text($l['customer_kind'])) : null) : null,
            'gstin' => $upper($l['gstin'] ?? null),
            'pan' => $upper($l['pan'] ?? null),
            'email' => ($e = $text($l['email'] ?? null)) === null ? null : strtolower($e),
            'phone' => $text($l['phone'] ?? null),
            'credit_days' => $party ? $number($l['credit_days'] ?? null) : null,
            'credit_limit' => $type === LedgerType::Customer ? $number($l['credit_limit'] ?? null) : null,
            'lead_time_days' => $type === LedgerType::Supplier ? $number($l['lead_time_days'] ?? null) : null,
            'payment_preferences' => $party ? array_values(array_unique(array_filter(array_map(fn ($c) => $upper($c), (array) ($l['payment_preferences'] ?? [])), fn ($c) => $c !== null))) : null,
            'status' => $text($l['status'] ?? 'active') ?? 'active',
            'remarks' => $text($l['remarks'] ?? null),
        ];

        $addresses = [];
        foreach (array_values((array) ($input['addresses'] ?? [])) as $a) {
            $a = (array) $a;
            $addresses[] = [
                'id' => ($id = $text($a['id'] ?? null)) === null ? null : $id,
                'label' => $text($a['label'] ?? null),
                'is_billing' => $flag($a['is_billing'] ?? false),
                'is_shipping' => $flag($a['is_shipping'] ?? false),
                'is_default_billing' => $flag($a['is_default_billing'] ?? false),
                'is_default_shipping' => $flag($a['is_default_shipping'] ?? false),
                'line1' => $text($a['line1'] ?? null),
                'line2' => $text($a['line2'] ?? null),
                'city' => $text($a['city'] ?? null),
                'state_id' => $text($a['state_id'] ?? null),
                'district_id' => $text($a['district_id'] ?? null),
                'pin_code' => $upper($a['pin_code'] ?? null),
                'gstin' => $upper($a['gstin'] ?? null),
                'phone' => $text($a['phone'] ?? null),
            ];
        }

        $contacts = [];
        foreach (array_values((array) ($input['contacts'] ?? [])) as $c) {
            $c = (array) $c;
            $contacts[] = [
                'id' => ($id = $text($c['id'] ?? null)) === null ? null : $id,
                'name' => $text($c['name'] ?? null),
                'designation' => $text($c['designation'] ?? null),
                'phone' => $text($c['phone'] ?? null),
                'mobile' => $text($c['mobile'] ?? null),
                'email' => ($e = $text($c['email'] ?? null)) === null ? null : strtolower($e),
                'is_primary' => $flag($c['is_primary'] ?? false),
            ];
        }

        $bank = null;
        if ($canBank && is_array($input['bank'] ?? null)) {
            $b = $input['bank'];
            $bank = [
                'bank_name' => $text($b['bank_name'] ?? null),
                'branch' => $text($b['branch'] ?? null),
                'account_holder' => $text($b['account_holder'] ?? null),
                'account_type' => ($t = $text($b['account_type'] ?? null)) === null ? null : strtolower($t),
                'account_number' => ($n = $text($b['account_number'] ?? null)) === null ? null : str_replace([' ', '-'], '', $n),
                'ifsc' => $upper($b['ifsc'] ?? null),
            ];
        }

        return ['ledger' => $ledger, 'addresses' => $addresses, 'contacts' => $contacts, 'bank' => $bank];
    }

    /**
     * @param  array{ledger: array<string, mixed>, addresses: list<array<string, mixed>>, contacts: list<array<string, mixed>>, bank: array<string, mixed>|null}  $data
     * @return array<string, mixed>
     */
    private function rules(LedgerType $type, array $data, ?Ledger $editing, int $companyId, bool $canBank, bool $hasBank): array
    {
        $allowedGroups = array_keys($this->groupOptions($type, $editing?->account_group_id));
        $isB2b = $type === LedgerType::Customer && ($data['ledger']['customer_kind'] ?? null) === 'b2b';

        $rules = [
            'ledger.code' => ['nullable', 'regex:/^[A-Z0-9][A-Z0-9\-\/]{1,29}$/', Rule::unique('ledgers', 'code')->where('company_id', $companyId)->ignore($editing?->id)],
            'ledger.name' => ['required', 'string', 'max:200',
                Rule::unique('ledgers', 'name')->where('company_id', $companyId)->where('ledger_type', $type->value)->ignore($editing?->id)],
            'ledger.legal_name' => ['nullable', 'string', 'max:200'],
            'ledger.account_group_id' => ['required', 'integer', Rule::in($allowedGroups)],
            'ledger.customer_kind' => $type === LedgerType::Customer ? ['required', 'in:b2b,b2c'] : ['nullable'],
            'ledger.gstin' => [$isB2b ? 'required' : 'nullable', new Gstin,
                Rule::unique('ledgers', 'gstin')->where('company_id', $companyId)->where('ledger_type', $type->value)->whereNull('deleted_at')->ignore($editing?->id)],
            'ledger.pan' => ['nullable', new Pan],
            'ledger.email' => ['nullable', 'email:rfc', 'max:200'],
            'ledger.phone' => ['nullable', 'regex:/^[0-9+()\-\s]{6,20}$/'],
            'ledger.credit_days' => ['nullable', 'integer', 'between:0,365'],
            'ledger.credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'ledger.lead_time_days' => ['nullable', 'integer', 'between:0,365'],
            'ledger.payment_preferences' => ['nullable', 'array', 'max:12'],
            'ledger.payment_preferences.*' => ['string', Rule::exists('payment_methods', 'code')->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')],
            'ledger.status' => ['required', 'in:active,inactive'],
            'ledger.remarks' => ['nullable', 'string', 'max:1000'],

            'addresses' => ['array', 'max:'.self::MAX_ROWS],
            'contacts' => ['array', 'max:'.self::MAX_ROWS],
        ];

        foreach ($data['addresses'] as $i => $address) {
            $stateId = is_numeric($address['state_id']) ? (int) $address['state_id'] : 0;

            $rules["addresses.$i.id"] = ['nullable', 'integer'];
            $rules["addresses.$i.label"] = ['nullable', 'string', 'max:60'];
            $rules["addresses.$i.line1"] = ['required', 'string', 'max:255'];
            $rules["addresses.$i.line2"] = ['nullable', 'string', 'max:255'];
            $rules["addresses.$i.city"] = ['nullable', 'string', 'max:100'];
            $rules["addresses.$i.state_id"] = ['required', 'integer', Rule::exists('states', 'id')->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')];
            $rules["addresses.$i.district_id"] = ['nullable', 'integer', Rule::exists('districts', 'id')->where('company_id', $companyId)->where('state_id', $stateId)->whereNull('deleted_at')];
            $rules["addresses.$i.pin_code"] = ['required', new IndianPinCode];
            $rules["addresses.$i.gstin"] = ['nullable', new Gstin];
            $rules["addresses.$i.phone"] = ['nullable', 'regex:/^[0-9+()\-\s]{6,20}$/'];
        }

        foreach ($data['contacts'] as $i => $contact) {
            $rules["contacts.$i.id"] = ['nullable', 'integer'];
            $rules["contacts.$i.name"] = ['required', 'string', 'max:150'];
            $rules["contacts.$i.designation"] = ['nullable', 'string', 'max:100'];
            $rules["contacts.$i.phone"] = ['nullable', 'regex:/^[0-9+()\-\s]{6,20}$/'];
            $rules["contacts.$i.mobile"] = ['nullable', new IndianMobile];
            $rules["contacts.$i.email"] = ['nullable', 'email:rfc', 'max:200'];
        }

        if ($canBank && $data['bank'] !== null) {
            $rules['bank.bank_name'] = ['required', 'string', 'max:120'];
            $rules['bank.branch'] = ['nullable', 'string', 'max:120'];
            $rules['bank.account_holder'] = ['nullable', 'string', 'max:150'];
            $rules['bank.account_type'] = ['nullable', 'in:'.implode(',', array_keys(self::ACCOUNT_TYPES))];
            // A blank number keeps the one already on file; a new record needs one.
            $rules['bank.account_number'] = [$hasBank ? 'nullable' : 'required', 'regex:/^\d{9,18}$/'];
            $rules['bank.ifsc'] = ['required', new Ifsc];
        }

        return $rules;
    }

    /**
     * Rules that look across fields. Errors land on the field the person should fix.
     *
     * @param  array{ledger: array<string, mixed>, addresses: list<array<string, mixed>>, contacts: list<array<string, mixed>>, bank: array<string, mixed>|null}  $data
     */
    private function crossChecks(\Illuminate\Validation\Validator $validator, LedgerType $type, array $data, int $companyId): void
    {
        $ledger = $data['ledger'];
        $errors = $validator->errors();

        // PAN is the middle of the GSTIN: they must agree.
        $gstin = $ledger['gstin'];
        if ($gstin !== null && Gstin::hasValidFormat($gstin) && $ledger['pan'] !== null && Pan::isValid($ledger['pan']) && Gstin::pan($gstin) !== $ledger['pan']) {
            $errors->add('ledger.pan', 'The PAN does not match the PAN inside the GSTIN ('.Gstin::pan($gstin).').');
        }

        // Each address's own GSTIN must belong to the address's state, and share the PAN.
        $states = State::query()->whereIn('id', array_filter(array_column($data['addresses'], 'state_id'), 'is_numeric'))->get()->keyBy('id');
        foreach ($data['addresses'] as $i => $address) {
            $state = is_numeric($address['state_id']) ? $states->get((int) $address['state_id']) : null;

            if ($address['gstin'] !== null && Gstin::hasValidFormat($address['gstin'])) {
                if ($state !== null && Gstin::stateCode($address['gstin']) !== $state->gst_code) {
                    $errors->add("addresses.$i.gstin", 'This GSTIN is registered in state code '.Gstin::stateCode($address['gstin']).", not in {$state->name} ({$state->gst_code}).");
                }
                if ($ledger['pan'] !== null && Pan::isValid($ledger['pan']) && Gstin::pan($address['gstin']) !== $ledger['pan']) {
                    $errors->add("addresses.$i.gstin", 'The PAN inside this GSTIN does not match the ledger\'s PAN.');
                }
            }
        }

        foreach ($data['addresses'] as $i => $a) {
            if (! $a['is_billing'] && ! $a['is_shipping']) {
                $errors->add("addresses.$i.is_billing", 'Mark the address as billing, shipping or both.');
            }
        }

        // Defaults: one default billing and one default shipping address, and only among addresses of that kind.
        foreach (['billing' => 'is_billing', 'shipping' => 'is_shipping'] as $label => $flag) {
            $default = "is_default_{$label}";
            $kind = array_filter($data['addresses'], fn (array $a) => $a[$flag]);
            $defaults = array_filter($kind, fn (array $a) => $a[$default]);

            foreach ($data['addresses'] as $i => $a) {
                if ($a[$default] && ! $a[$flag]) {
                    $errors->add("addresses.$i.$default", "Only a $label address can be the default $label address.");
                }
            }

            if (count($defaults) > 1) {
                $errors->add('addresses', "Choose only one default $label address.");
            } elseif ($defaults === [] && count($kind) > 1) {
                $errors->add('addresses', "Choose which $label address is the default.");
            }
        }

        // The GSTIN names a state; the default billing address must be in it (place of supply starts from there).
        $billingAddresses = array_values(array_filter($data['addresses'], fn (array $a) => $a['is_billing']));
        $billing = collect($billingAddresses)->first(fn (array $a) => $a['is_default_billing']) ?? (count($billingAddresses) === 1 ? $billingAddresses[0] : null);
        if ($gstin !== null && Gstin::hasValidFormat($gstin) && $billing !== null && is_numeric($billing['state_id'])) {
            $state = $states->get((int) $billing['state_id']);
            if ($state !== null && Gstin::stateCode($gstin) !== $state->gst_code) {
                $errors->add('ledger.gstin', 'The GSTIN is for state code '.Gstin::stateCode($gstin).", but the billing address is in {$state->name} ({$state->gst_code}).");
            }
        }

        // A contact needs some way to be reached.
        foreach ($data['contacts'] as $i => $c) {
            if ($c['phone'] === null && $c['mobile'] === null && $c['email'] === null) {
                $errors->add("contacts.$i.mobile", 'Give a phone, mobile or e-mail so this contact can be reached.');
            }
        }

        if (count(array_filter($data['contacts'], fn (array $c) => $c['is_primary'])) > 1) {
            $errors->add('contacts', 'Choose only one primary contact.');
        }
    }

    /**
     * Fill in the obvious defaults once the data is known to be valid: a lone billing address is the default one, a
     * lone contact is the primary one.
     *
     * @param  array{ledger: array<string, mixed>, addresses: list<array<string, mixed>>, contacts: list<array<string, mixed>>, bank: array<string, mixed>|null}  $data
     * @return array{ledger: array<string, mixed>, addresses: list<array<string, mixed>>, contacts: list<array<string, mixed>>, bank: array<string, mixed>|null}
     */
    private function finalise(array $data, LedgerType $type): array
    {
        $ledger = $data['ledger'];
        $ledger['account_group_id'] = (int) $ledger['account_group_id'];
        $ledger['credit_days'] = $ledger['credit_days'] === null ? null : (int) $ledger['credit_days'];
        $ledger['lead_time_days'] = $ledger['lead_time_days'] === null ? null : (int) $ledger['lead_time_days'];
        $ledger['credit_limit'] = $ledger['credit_limit'] === null ? null : number_format((float) $ledger['credit_limit'], 2, '.', '');
        $data['ledger'] = $ledger;

        $addresses = $data['addresses'];
        foreach (['billing' => 'is_billing', 'shipping' => 'is_shipping'] as $label => $flag) {
            $kind = array_keys(array_filter($addresses, fn (array $a) => $a[$flag]));

            foreach ($addresses as $i => $a) {
                // never a default of a kind the address is not
                if (! $a[$flag]) {
                    $addresses[$i]["is_default_{$label}"] = false;
                }
            }

            if (count($kind) === 1) {
                $addresses[$kind[0]]["is_default_{$label}"] = true;
            }
        }

        foreach ($addresses as $i => $a) {
            $addresses[$i]['state_id'] = (int) $a['state_id'];
            $addresses[$i]['district_id'] = $a['district_id'] === null ? null : (int) $a['district_id'];
            $addresses[$i]['id'] = $a['id'] === null ? null : (int) $a['id'];
        }
        $data['addresses'] = $addresses;

        $contacts = $data['contacts'];
        foreach ($contacts as $i => $c) {
            $contacts[$i]['id'] = $c['id'] === null ? null : (int) $c['id'];
            $contacts[$i]['mobile'] = $c['mobile'] === null ? null : (IndianMobile::normalise($c['mobile']) ?? $c['mobile']);
        }
        if (count($contacts) === 1) {
            $contacts[0]['is_primary'] = true;
        }
        $data['contacts'] = $contacts;

        return $data;
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'ledger.gstin.required' => 'A GSTIN is required for a B2B customer.',
            'ledger.gstin.unique' => 'Another ledger of this type already uses this GSTIN.',
            'ledger.name.unique' => 'A ledger of this type with this name already exists.',
            'ledger.code.unique' => 'This account code is already in use.',
            'ledger.code.regex' => 'Use letters, digits, "-" or "/" (2–30 characters), e.g. CUS-00001.',
            'ledger.account_group_id.in' => 'Choose a group this kind of ledger may be filed under.',
            'ledger.payment_preferences.*.exists' => 'Choose only active payment methods from the list.',
            'bank.account_number.regex' => 'The account number must be 9–18 digits.',
            'bank.account_number.required' => 'Enter the account number.',
            '*.state_id.exists' => 'Choose a state from the list.',
            '*.district_id.exists' => 'Choose a district of the selected state.',
        ];
    }

    /**
     * @param  array{ledger: array<string, mixed>, addresses: list<array<string, mixed>>, contacts: list<array<string, mixed>>, bank: array<string, mixed>|null}  $data
     * @return array<string, string>
     */
    private function attributeNames(array $data): array
    {
        $names = [
            'ledger.code' => 'account code', 'ledger.name' => 'name', 'ledger.legal_name' => 'legal name',
            'ledger.account_group_id' => 'account group', 'ledger.customer_kind' => 'customer kind', 'ledger.gstin' => 'GSTIN',
            'ledger.pan' => 'PAN', 'ledger.email' => 'e-mail', 'ledger.phone' => 'phone', 'ledger.credit_days' => 'credit days',
            'ledger.credit_limit' => 'credit limit', 'ledger.lead_time_days' => 'lead time', 'ledger.payment_preferences' => 'payment preferences',
            'ledger.status' => 'status', 'ledger.remarks' => 'remarks',
            'bank.bank_name' => 'bank name', 'bank.branch' => 'branch', 'bank.account_holder' => 'account holder',
            'bank.account_type' => 'account type', 'bank.account_number' => 'account number', 'bank.ifsc' => 'IFSC',
        ];

        foreach (array_keys($data['addresses']) as $i) {
            $n = $i + 1;
            foreach (['label' => 'label', 'line1' => 'line 1', 'line2' => 'line 2', 'city' => 'city', 'state_id' => 'state', 'district_id' => 'district',
                'pin_code' => 'PIN code', 'gstin' => 'GSTIN', 'phone' => 'phone', 'is_billing' => 'address type'] as $field => $label) {
                $names["addresses.$i.$field"] = "address $n $label";
            }
        }

        foreach (array_keys($data['contacts']) as $i) {
            $n = $i + 1;
            foreach (['name' => 'name', 'designation' => 'designation', 'phone' => 'phone', 'mobile' => 'mobile', 'email' => 'e-mail'] as $field => $label) {
                $names["contacts.$i.$field"] = "contact $n $label";
            }
        }

        return $names;
    }
}
