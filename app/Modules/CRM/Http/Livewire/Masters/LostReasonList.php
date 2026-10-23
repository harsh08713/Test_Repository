<?php

namespace App\Modules\CRM\Http\Livewire\Masters;

use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadStageEntry;
use App\Modules\CRM\Models\LostReason;
use App\Modules\Masters\Http\Livewire\SimpleMasterList;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Title;

#[Title('Lost reasons')]
class LostReasonList extends SimpleMasterList
{
    protected function modelClass(): string
    {
        return LostReason::class;
    }

    protected function title(): string
    {
        return 'Lost reasons';
    }

    protected function description(): string
    {
        return 'Why business is lost. A lead cannot be marked lost without one of these, so the reasons can be counted later.';
    }

    protected function tabs(): ?string
    {
        return 'crm-masters';
    }

    protected function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'uppercase' => true, 'placeholder' => 'e.g. CREDIT_TERMS', 'help' => 'Letters, digits and . _ - / only; unique within the company.'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:100']],
            ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:1000']],
            $this->statusField(),
        ];
    }

    protected function usageBlocker(Model $model): ?string
    {
        $used = Lead::query()->where('lost_reason_id', $model->getKey())->exists()
            || LeadStageEntry::query()->where('lost_reason_id', $model->getKey())->exists();

        return $used ? 'Leads have been lost for this reason, so it cannot be deleted. Deactivate it instead.' : null;
    }
}
