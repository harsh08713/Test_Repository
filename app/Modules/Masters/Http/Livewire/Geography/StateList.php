<?php

namespace App\Modules\Masters\Http\Livewire\Geography;

use App\Modules\Masters\Http\Livewire\SimpleMasterList;
use App\Modules\Masters\Models\State;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;

#[Title('States')]
class StateList extends SimpleMasterList
{
    protected function modelClass(): string
    {
        return State::class;
    }

    protected function title(): string
    {
        return 'States';
    }

    protected function description(): string
    {
        return 'States and union territories with their GST state codes (seeded for every company).';
    }

    protected function tabs(): ?string
    {
        return 'geography';
    }

    protected function columns(): array
    {
        return ['code' => 'Code', 'gst_code' => 'GST code', 'name' => 'Name'];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Short code', 'type' => 'text', 'uppercase' => true, 'placeholder' => 'e.g. GJ'],
            ['name' => 'gst_code', 'label' => 'GST state code', 'type' => 'text', 'rules' => ['required', 'regex:/^\d{2}$/'], 'placeholder' => 'e.g. 24', 'help' => 'The two-digit code used in GSTINs.'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:100']],
            $this->statusField(),
        ];
    }

    protected function extraRules(?Model $editing): array
    {
        return [
            'values.gst_code' => [
                'required', 'regex:/^\d{2}$/',
                Rule::unique('states', 'gst_code')->where('company_id', app(TenantContext::class)->activeCompanyId())->ignore($editing?->getKey()),
            ],
        ];
    }

    protected function usageBlocker(Model $model): ?string
    {
        return $model instanceof State && $model->districts()->exists()
            ? 'This state has districts. Remove or move them first, or deactivate the state instead.'
            : null;
    }
}
