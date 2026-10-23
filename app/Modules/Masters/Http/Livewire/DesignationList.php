<?php

namespace App\Modules\Masters\Http\Livewire;

use App\Modules\Masters\Models\Designation;
use Livewire\Attributes\Title;

#[Title('Designations')]
class DesignationList extends SimpleMasterList
{
    protected function modelClass(): string
    {
        return Designation::class;
    }

    protected function title(): string
    {
        return 'Designations';
    }

    protected function description(): string
    {
        return 'Job titles used across the company (Manager, Executive, Field Salesperson…).';
    }

    protected function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'uppercase' => true, 'placeholder' => 'e.g. MGR', 'help' => 'Letters, digits and . _ - / only; unique within the company.'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:150']],
            ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:1000']],
            $this->statusField(),
        ];
    }
}
