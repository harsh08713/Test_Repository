<?php

namespace App\Modules\Masters\Http\Livewire;

use App\Modules\Masters\Models\PriceList;
use App\Modules\Masters\Models\Rate;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Title;

#[Title('Price lists')]
class PriceListList extends SimpleMasterList
{
    protected function modelClass(): string
    {
        return PriceList::class;
    }

    protected function title(): string
    {
        return 'Price lists';
    }

    protected function description(): string
    {
        return 'Named sets of rates for a kind of customer, such as Dealer or Project. A rate that belongs to no price list is the standard rate, which everyone gets unless their price list has a rate of its own.';
    }

    protected function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'uppercase' => true, 'placeholder' => 'e.g. DEALER', 'help' => 'Letters, digits and . _ - / only; unique within the company.'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:100']],
            ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:1000']],
            $this->statusField(),
        ];
    }

    protected function usageBlocker(Model $model): ?string
    {
        $count = Rate::query()->where('price_list_id', $model->getKey())->count();

        return $count > 0
            ? "{$count} rate(s) belong to this price list, so it cannot be deleted. Deactivate it instead."
            : null;
    }
}
