<?php

namespace App\Modules\Masters\Http\Livewire\Geography;

use App\Modules\Masters\Http\Livewire\SimpleMasterList;
use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\PinCode;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;

#[Title('Blocks')]
class BlockList extends SimpleMasterList
{
    protected function modelClass(): string
    {
        return Block::class;
    }

    protected function title(): string
    {
        return 'Blocks';
    }

    protected function description(): string
    {
        return 'Blocks (talukas / tehsils) within a district. Normally loaded from the Tent Studio import.';
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
        return ['name' => 'Block', 'district' => 'District', 'state' => 'State'];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'district_id', 'label' => 'District', 'type' => 'select', 'rules' => ['required']],
            ['name' => 'name', 'label' => 'Block', 'type' => 'text', 'rules' => ['required', 'string', 'max:150']],
            $this->statusField(),
        ];
    }

    protected function options(string $field): array
    {
        if (! in_array($field, ['district_id', 'filter'], true)) {
            return parent::options($field);
        }

        return District::query()->with('state')->orderBy('name')->get()
            ->mapWithKeys(fn (District $d) => [$d->id => $d->name.' — '.$d->state?->name])->all();
    }

    protected function filterLabel(): ?string
    {
        return 'District';
    }

    protected function applyFilter(Builder $query, string $value): void
    {
        $query->where('district_id', (int) $value);
    }

    protected function listQuery(Builder $query): Builder
    {
        return $query->with('district.state')->orderBy('name');
    }

    protected function cell(Model $model, string $column): string
    {
        if (! $model instanceof Block) {
            return parent::cell($model, $column);
        }

        return match ($column) {
            'district' => (string) $model->district?->name,
            'state' => (string) $model->district?->state?->name,
            default => parent::cell($model, $column),
        };
    }

    protected function extraRules(?Model $editing): array
    {
        $companyId = app(TenantContext::class)->activeCompanyId();

        return [
            'values.district_id' => ['required', 'integer', Rule::exists('districts', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'values.name' => [
                'required', 'string', 'max:150',
                Rule::unique('blocks', 'name')->where('company_id', $companyId)
                    ->where('district_id', (int) ($this->values['district_id'] ?? 0))->ignore($editing?->getKey()),
            ],
        ];
    }

    protected function usageBlocker(Model $model): ?string
    {
        return $model instanceof Block && PinCode::query()->where('block_id', $model->id)->exists()
            ? 'PIN codes refer to this block. Move them first, or deactivate the block instead.'
            : null;
    }
}
