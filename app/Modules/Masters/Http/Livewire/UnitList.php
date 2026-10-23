<?php

namespace App\Modules\Masters\Http\Livewire;

use App\Modules\Masters\Models\Unit;
use Livewire\Attributes\Title;

#[Title('Units of measure')]
class UnitList extends SimpleMasterList
{
    protected function modelClass(): string
    {
        return Unit::class;
    }

    protected function title(): string
    {
        return 'Units of measure';
    }

    protected function description(): string
    {
        return 'Units used on items, rates and documents. Quantities may only be fractional where the unit allows decimals.';
    }

    protected function columns(): array
    {
        return ['code' => 'Code', 'name' => 'Name', 'decimal_allowed' => 'Decimals allowed'];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'uppercase' => true, 'placeholder' => 'e.g. KG', 'help' => 'Letters, digits and . _ - / only; unique within the company.'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:100']],
            ['name' => 'decimal_allowed', 'label' => 'Allow fractional quantities', 'type' => 'checkbox', 'rules' => ['boolean'], 'default' => false, 'help' => 'Tick for Kg, Metre, Litre…; leave off for Nos, Box, Set…'],
            ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:1000']],
            $this->statusField(),
        ];
    }
}
