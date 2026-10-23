<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\ChecklistTemplateItem;
use App\Modules\Masters\Http\Livewire\SimpleMasterList;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Title;

#[Title('Onboarding and exit lists')]
class ChecklistTemplateList extends SimpleMasterList
{
    protected function modelClass(): string
    {
        return ChecklistTemplateItem::class;
    }

    protected function title(): string
    {
        return 'Onboarding and exit lists';
    }

    protected function description(): string
    {
        return 'The company’s standard things to do when someone joins or gives notice. A person’s list is a copy made when it starts (a new hire, or notice recorded); changing this list later does not change lists already started.';
    }

    protected function columns(): array
    {
        return ['code' => 'Code', 'name' => 'To do', 'kind' => 'List', 'responsible' => 'Who', 'due_days' => 'Days after start'];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'uppercase' => true, 'placeholder' => 'e.g. LAPTOP'],
            ['name' => 'name', 'label' => 'What is to be done', 'type' => 'text', 'rules' => ['required', 'string', 'max:150']],
            ['name' => 'kind', 'label' => 'List', 'type' => 'select', 'default' => 'onboarding', 'rules' => ['required', 'in:onboarding,exit']],
            ['name' => 'responsible', 'label' => 'Who does it', 'type' => 'select', 'default' => 'hr', 'rules' => ['required', 'in:hr,manager,employee']],
            ['name' => 'due_days', 'label' => 'Due, days after the list starts', 'type' => 'number', 'default' => '3', 'rules' => ['required', 'integer', 'min:0', 'max:180']],
            ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:1000']],
            $this->statusField(),
        ];
    }

    protected function options(string $field): array
    {
        return match ($field) {
            'kind' => ChecklistTemplateItem::KINDS,
            'responsible' => ChecklistTemplateItem::RESPONSIBLE,
            default => parent::options($field),
        };
    }

    protected function cell(Model $model, string $column): string
    {
        return match ($column) {
            'kind' => ChecklistTemplateItem::KINDS[$model->getAttribute('kind')] ?? (string) $model->getAttribute('kind'),
            'responsible' => ChecklistTemplateItem::RESPONSIBLE[$model->getAttribute('responsible')] ?? (string) $model->getAttribute('responsible'),
            default => parent::cell($model, $column),
        };
    }
}
