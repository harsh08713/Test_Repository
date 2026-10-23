<?php

namespace App\Modules\Masters\Http\Livewire;

use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\PaymentMethod;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;

#[Title('Payment methods')]
class PaymentMethodList extends SimpleMasterList
{
    protected function modelClass(): string
    {
        return PaymentMethod::class;
    }

    protected function title(): string
    {
        return 'Payment methods';
    }

    protected function description(): string
    {
        return 'The ways customers and suppliers can pay or be paid. Ledgers record which of these a party prefers. A code that ledgers already use cannot be renamed.';
    }

    protected function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'uppercase' => true, 'placeholder' => 'e.g. NEFT', 'help' => 'Letters, digits and . _ - / only; unique within the company.'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:100']],
            ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:1000']],
            $this->statusField(),
        ];
    }

    protected function usageBlocker(Model $model): ?string
    {
        $count = $this->ledgersUsing($model);

        return $count > 0
            ? "{$count} ledger(s) list this payment method as a preference, so it cannot be deleted. Deactivate it instead."
            : null;
    }

    protected function extraRules(?Model $editing): array
    {
        // The usual code rules, plus: once ledgers refer to a code it is fixed (they store the code, not an id).
        return [
            'values.code' => [
                'required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9._\-\/]*$/',
                Rule::unique('payment_methods', 'code')->where('company_id', app(TenantContext::class)->activeCompanyId())->ignore($editing?->getKey()),
                function (string $attribute, mixed $value, \Closure $fail) use ($editing) {
                    if ($editing instanceof PaymentMethod && $value !== $editing->code && $this->ledgersUsing($editing) > 0) {
                        $fail('Ledgers already use this code, so it cannot be changed.');
                    }
                },
            ],
        ];
    }

    private function ledgersUsing(Model $method): int
    {
        return Ledger::query()->whereJsonContains('payment_preferences', $method->getAttribute('code'))->count();
    }
}
