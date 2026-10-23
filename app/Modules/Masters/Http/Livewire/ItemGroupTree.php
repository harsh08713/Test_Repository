<?php

namespace App\Modules\Masters\Http\Livewire;

use App\Modules\Masters\Models\ItemGroup;
use Livewire\Attributes\Title;

#[Title('Item groups')]
class ItemGroupTree extends HierarchicalMasterTree
{
    protected function modelClass(): string
    {
        return ItemGroup::class;
    }

    protected function title(): string
    {
        return 'Item groups';
    }

    protected function description(): string
    {
        return 'How items are grouped (Raw material, Finished goods, Consumables…), to any depth.';
    }
}
