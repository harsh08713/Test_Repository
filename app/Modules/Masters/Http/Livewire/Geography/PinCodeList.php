<?php

namespace App\Modules\Masters\Http\Livewire\Geography;

use App\Modules\Masters\Http\Livewire\SimpleMasterList;
use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\PinCode;
use App\Modules\Masters\Models\State;
use App\Modules\Shared\Rules\IndianPinCode;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;

#[Title('PIN codes')]
class PinCodeList extends SimpleMasterList
{
    protected function modelClass(): string
    {
        return PinCode::class;
    }

    protected function title(): string
    {
        return 'PIN codes';
    }

    protected function description(): string
    {
        return 'PIN codes with their state, district, block and locality. A PIN can have several localities. Used to auto-fill addresses.';
    }

    protected function usesCode(): bool
    {
        return false;
    }

    protected function searchColumns(): array
    {
        return ['pin_code', 'area'];
    }

    protected function tabs(): ?string
    {
        return 'geography';
    }

    protected function columns(): array
    {
        return ['pin_code' => 'PIN code', 'area' => 'Locality', 'block' => 'Block', 'district' => 'District', 'state' => 'State'];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'pin_code', 'label' => 'PIN code', 'type' => 'text', 'rules' => ['required', new IndianPinCode], 'placeholder' => '380001'],
            ['name' => 'district_id', 'label' => 'District', 'type' => 'select', 'rules' => ['required'], 'live' => true],
            ['name' => 'block_id', 'label' => 'Block', 'type' => 'select', 'rules' => ['nullable'], 'placeholder' => '— none —'],
            ['name' => 'area', 'label' => 'Locality / post office', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:150'], 'help' => 'Leave empty when the row describes the whole PIN code.'],
            $this->statusField(),
        ];
    }

    protected function options(string $field): array
    {
        return match ($field) {
            'district_id' => District::query()->with('state')->orderBy('name')->get()
                ->mapWithKeys(fn (District $d) => [$d->id => $d->name.' — '.$d->state?->name])->all(),
            // Only the blocks of the chosen district: the dependent select.
            'block_id' => ($district = (int) ($this->values['district_id'] ?? 0)) > 0
                ? Block::query()->where('district_id', $district)->orderBy('name')->pluck('name', 'id')->all()
                : [],
            'filter' => State::query()->orderBy('name')->pluck('name', 'id')->all(),
            default => parent::options($field),
        };
    }

    protected function filterLabel(): ?string
    {
        return 'State';
    }

    protected function applyFilter(Builder $query, string $value): void
    {
        $query->where('state_id', (int) $value);
    }

    protected function afterFieldUpdated(string $field): void
    {
        if ($field === 'district_id') {
            $this->values['block_id'] = '';
        }
    }

    protected function listQuery(Builder $query): Builder
    {
        return $query->with(['state', 'district', 'block'])->orderBy('pin_code')->orderBy('area');
    }

    protected function cell(Model $model, string $column): string
    {
        if (! $model instanceof PinCode) {
            return parent::cell($model, $column);
        }

        return match ($column) {
            'block' => (string) $model->block?->name,
            'district' => (string) $model->district?->name,
            'state' => (string) $model->state?->name,
            default => parent::cell($model, $column),
        };
    }

    protected function extraRules(?Model $editing): array
    {
        $companyId = app(TenantContext::class)->activeCompanyId();
        $districtId = (int) ($this->values['district_id'] ?? 0);

        return [
            'values.district_id' => ['required', 'integer', Rule::exists('districts', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            // The block must belong to the chosen district — never trust a block id from the browser.
            'values.block_id' => ['nullable', 'integer', Rule::exists('blocks', 'id')->where('company_id', $companyId)->where('district_id', $districtId)->whereNull('deleted_at')],
            'values.pin_code' => [
                'required', new IndianPinCode,
                Rule::unique('pin_codes', 'pin_code')->where('company_id', $companyId)->where('district_id', $districtId)
                    ->where('area', (string) ($this->values['area'] ?? ''))->ignore($editing?->getKey()),
            ],
        ];
    }

    protected function beforeSave(array $attributes): array
    {
        // The state is whatever the district belongs to, so the two can never disagree.
        $attributes['state_id'] = District::query()->whereKey((int) $attributes['district_id'])->value('state_id');
        $attributes['area'] = (string) ($attributes['area'] ?? '');

        return $attributes;
    }
}
