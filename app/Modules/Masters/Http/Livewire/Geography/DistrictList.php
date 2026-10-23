<?php

namespace App\Modules\Masters\Http\Livewire\Geography;

use App\Modules\Masters\Http\Livewire\SimpleMasterList;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\PinCode;
use App\Modules\Masters\Models\State;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;

#[Title('Districts')]
class DistrictList extends SimpleMasterList
{
    protected function modelClass(): string
    {
        return District::class;
    }

    protected function title(): string
    {
        return 'Districts';
    }

    protected function description(): string
    {
        return 'Districts of each state. They are also created automatically by the PIN code import.';
    }

    protected function usesCode(): bool
    {
        return false;
    }

    protected function tabs(): ?string
    {
        return 'geography';
    }

    protected function columns(): array
    {
        return ['name' => 'District', 'state' => 'State'];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'state_id', 'label' => 'State', 'type' => 'select', 'rules' => ['required']],
            ['name' => 'name', 'label' => 'District', 'type' => 'text', 'rules' => ['required', 'string', 'max:150']],
            $this->statusField(),
        ];
    }

    protected function options(string $field): array
    {
        return in_array($field, ['state_id', 'filter'], true)
            ? State::query()->orderBy('name')->pluck('name', 'id')->all()
            : parent::options($field);
    }

    protected function filterLabel(): ?string
    {
        return 'State';
    }

    protected function applyFilter(Builder $query, string $value): void
    {
        $query->where('state_id', (int) $value);
    }

    protected function listQuery(Builder $query): Builder
    {
        return $query->with('state')->orderBy('name');
    }

    protected function cell(Model $model, string $column): string
    {
        return $column === 'state' && $model instanceof District ? (string) $model->state?->name : parent::cell($model, $column);
    }

    protected function extraRules(?Model $editing): array
    {
        $companyId = app(TenantContext::class)->activeCompanyId();

        return [
            'values.state_id' => ['required', 'integer', Rule::exists('states', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'values.name' => [
                'required', 'string', 'max:150',
                Rule::unique('districts', 'name')->where('company_id', $companyId)
                    ->where('state_id', (int) ($this->values['state_id'] ?? 0))->ignore($editing?->getKey()),
            ],
        ];
    }

    protected function usageBlocker(Model $model): ?string
    {
        if (! $model instanceof District) {
            return null;
        }

        return $model->blocks()->exists() || PinCode::query()->where('district_id', $model->id)->exists()
            ? 'This district has blocks or PIN codes. Remove them first, or deactivate the district instead.'
            : null;
    }
}
