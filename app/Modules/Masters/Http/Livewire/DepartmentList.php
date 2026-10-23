<?php

namespace App\Modules\Masters\Http\Livewire;

use App\Modules\Masters\Models\Department;
use Livewire\Attributes\Title;

#[Title('Departments')]
class DepartmentList extends SimpleMasterList
{
    protected function modelClass(): string
    {
        return Department::class;
    }

    protected function title(): string
    {
        return 'Departments';
    }

    protected function description(): string
    {
        return 'The departments of the company (Purchase, Inventory, Accounts, Sales…).';
    }

    protected function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'uppercase' => true, 'placeholder' => 'e.g. PUR', 'help' => 'Letters, digits and . _ - / only; unique within the company.'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:150']],
            ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:1000']],
            $this->statusField(),
        ];
    }
}
