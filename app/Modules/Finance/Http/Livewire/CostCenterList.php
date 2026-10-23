<?php

namespace App\Modules\Finance\Http\Livewire;

use App\Modules\Finance\Models\Budget;
use App\Modules\Finance\Models\CostCenter;
use App\Modules\Masters\Http\Livewire\SimpleMasterList;
use App\Modules\Masters\Models\Department;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;

#[Title('Cost centres')]
class CostCenterList extends SimpleMasterList
{
    protected function modelClass(): string
    {
        return CostCenter::class;
    }

    protected function title(): string
    {
        return 'Cost centres';
    }

    protected function description(): string
    {
        return 'Where costs are collected: a plant, a department, a production line. Budgets are set for a cost centre, and a loss incident can be charged to one.';
    }

    protected function columns(): array
    {
        return ['code' => 'Code', 'name' => 'Name', 'parent_id' => 'Under', 'location_id' => 'Branch', 'department_id' => 'Department'];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'uppercase' => true, 'placeholder' => 'e.g. PLANT1-PRESS', 'help' => 'Letters, digits and . _ - / only; unique within the company.'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:150']],
            ['name' => 'parent_id', 'label' => 'Under', 'type' => 'select', 'placeholder' => 'Top level', 'rules' => ['nullable', 'integer']],
            ['name' => 'location_id', 'label' => 'Branch', 'type' => 'select', 'placeholder' => 'Not tied to a branch', 'rules' => ['nullable', 'integer']],
            ['name' => 'department_id', 'label' => 'Department', 'type' => 'select', 'placeholder' => 'Not tied to a department', 'rules' => ['nullable', 'integer']],
            ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:1000']],
            $this->statusField(),
        ];
    }

    protected function options(string $field): array
    {
        return match ($field) {
            'parent_id' => CostCenter::query()->when($this->editingId !== null, fn (Builder $q) => $q->whereKeyNot($this->editingId))->orderBy('name')->pluck('name', 'id')->all(),
            'location_id' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'department_id' => Department::query()->orderBy('name')->pluck('name', 'id')->all(),
            default => parent::options($field),
        };
    }

    protected function extraRules(?Model $editing): array
    {
        $company = app(TenantContext::class)->activeCompanyId();
        $exists = fn (string $table) => ['nullable', Rule::exists($table, 'id')->where('company_id', $company)->whereNull('deleted_at')];

        return [
            'values.parent_id' => [...$exists('cost_centers'), Rule::notIn([$editing?->getKey()])],
            'values.location_id' => $exists('locations'),
            'values.department_id' => $exists('departments'),
        ];
    }

    protected function cell(Model $model, string $column): string
    {
        return match ($column) {
            'parent_id' => (string) ($model->getAttribute('parent_id') ? CostCenter::query()->whereKey($model->getAttribute('parent_id'))->value('name') : ''),
            'location_id' => (string) ($model->getAttribute('location_id') ? Location::query()->whereKey($model->getAttribute('location_id'))->value('name') : ''),
            'department_id' => (string) ($model->getAttribute('department_id') ? Department::query()->whereKey($model->getAttribute('department_id'))->value('name') : ''),
            default => parent::cell($model, $column),
        };
    }

    protected function usageBlocker(Model $model): ?string
    {
        if (CostCenter::query()->where('parent_id', $model->getKey())->exists()) {
            return 'Other cost centres sit under this one, so it cannot be deleted. Deactivate it instead.';
        }

        if (Budget::query()->where('cost_center_id', $model->getKey())->exists() || DB::table('loss_incidents')->where('cost_center_id', $model->getKey())->exists()) {
            return 'Budgets or loss incidents use this cost centre, so it cannot be deleted. Deactivate it instead.';
        }

        return null;
    }
}
