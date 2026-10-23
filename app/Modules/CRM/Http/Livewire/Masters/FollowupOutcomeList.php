<?php

namespace App\Modules\CRM\Http\Livewire\Masters;

use App\Modules\CRM\Http\Livewire\Masters\Concerns\KeepsSystemCodes;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\FollowupOutcome;
use App\Modules\Masters\Http\Livewire\SimpleMasterList;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Title;

#[Title('Follow-up outcomes')]
class FollowupOutcomeList extends SimpleMasterList
{
    use KeepsSystemCodes;

    protected function modelClass(): string
    {
        return FollowupOutcome::class;
    }

    protected function title(): string
    {
        return 'Follow-up outcomes';
    }

    protected function description(): string
    {
        return 'What can come of a call, a visit or a meeting. Two switches give an outcome its meaning: whether the person was actually reached (a new lead then becomes “contacted”), and whether it needs a date for the next follow-up.';
    }

    protected function tabs(): ?string
    {
        return 'crm-masters';
    }

    protected function columns(): array
    {
        return ['code' => 'Code', 'name' => 'Name', 'counts_as_contact' => 'Person reached', 'needs_next_followup' => 'Needs a next date'];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'uppercase' => true, 'placeholder' => 'e.g. QUOTE_REQUESTED', 'help' => 'Letters, digits and . _ - / only; unique within the company.'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:100']],
            ['name' => 'counts_as_contact', 'label' => 'The person was actually reached', 'type' => 'checkbox', 'rules' => ['boolean'], 'help' => 'Recording this outcome marks the lead as contacted now, and moves a new lead to “Contacted”.'],
            ['name' => 'needs_next_followup', 'label' => 'Needs a date for the next follow-up', 'type' => 'checkbox', 'rules' => ['boolean'], 'help' => 'The outcome cannot be recorded without planning the next step (for example “Call Back Later”).'],
            ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:1000']],
            $this->statusField(),
        ];
    }

    protected function extraRules(?Model $editing): array
    {
        return ['values.code' => $this->codeRules('followup_outcomes', $editing)];
    }

    protected function usageBlocker(Model $model): ?string
    {
        $count = Followup::query()->where('outcome_id', $model->getKey())->count();

        return $count > 0 ? "{$count} follow-up(s) ended with this outcome, so it cannot be deleted. Deactivate it instead." : null;
    }
}
