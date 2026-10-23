<?php

namespace App\Modules\CRM\Http\Livewire\Masters;

use App\Modules\CRM\Http\Livewire\Masters\Concerns\KeepsSystemCodes;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\Masters\Http\Livewire\SimpleMasterList;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Title;

#[Title('Lead sources')]
class LeadSourceList extends SimpleMasterList
{
    use KeepsSystemCodes;

    protected function modelClass(): string
    {
        return LeadSource::class;
    }

    protected function title(): string
    {
        return 'Lead sources';
    }

    protected function description(): string
    {
        return 'Where leads come from. The standard sources are used by the Excel import and the marketplace integrations, so they can be renamed or switched off but not deleted; add your own beside them.';
    }

    protected function tabs(): ?string
    {
        return 'crm-masters';
    }

    protected function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'uppercase' => true, 'placeholder' => 'e.g. EXHIBITION', 'help' => 'Letters, digits and . _ - / only; unique within the company.'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:100']],
            ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:1000']],
            $this->statusField(),
        ];
    }

    protected function extraRules(?Model $editing): array
    {
        return ['values.code' => $this->codeRules('lead_sources', $editing)];
    }

    protected function usageBlocker(Model $model): ?string
    {
        $count = Lead::query()->where('lead_source_id', $model->getKey())->count();

        return $count > 0 ? "{$count} lead(s) came from this source, so it cannot be deleted. Deactivate it instead." : null;
    }
}
