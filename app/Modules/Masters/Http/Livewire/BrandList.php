<?php

namespace App\Modules\Masters\Http\Livewire;

use App\Modules\Masters\Models\Brand;
use Livewire\Attributes\Title;

#[Title('Brands')]
class BrandList extends SimpleMasterList
{
    protected function modelClass(): string
    {
        return Brand::class;
    }

    protected function title(): string
    {
        return 'Brands';
    }

    protected function description(): string
    {
        return 'The brands of your products. Revenue and profit can be ranked by brand once items are linked to one.';
    }

    protected function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'uppercase' => true, 'placeholder' => 'e.g. ACME', 'help' => 'Letters, digits and . _ - / only; unique within the company.'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:150']],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:1000']],
            $this->statusField(),
        ];
    }
}
