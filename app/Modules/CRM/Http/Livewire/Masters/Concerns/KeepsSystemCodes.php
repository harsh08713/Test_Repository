<?php

namespace App\Modules\CRM\Http\Livewire\Masters\Concerns;

use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * For the CRM masters that ship with standard rows other code relies on (the lead source "EXCEL", the outcome
 * "CALL_BACK_LATER"): such a row can be renamed or switched off, but its code stays what it is.
 */
trait KeepsSystemCodes
{
    /**
     * The usual code rules, plus: the code of a standard row is fixed.
     *
     * @return list<mixed>
     */
    protected function codeRules(string $table, ?Model $editing): array
    {
        return [
            'required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9._\-\/]*$/',
            Rule::unique($table, 'code')->where('company_id', app(TenantContext::class)->activeCompanyId())->ignore($editing?->getKey()),
            function (string $attribute, mixed $value, \Closure $fail) use ($editing) {
                if ($editing !== null && (bool) $editing->getAttribute('is_system') && $value !== $editing->getAttribute('code')) {
                    $fail('This is a standard entry the system relies on, so its code cannot be changed. You can rename it or switch it off.');
                }
            },
        ];
    }
}
