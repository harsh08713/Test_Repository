<?php

namespace App\Modules\Inventory\Http\Livewire;

use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A person's own stock entry: stock found, stock lost, stock scrapped, or a transfer between two branches — each with a reason. Goods that
 * arrive from a supplier come in through a goods receipt, not here. Everything is decided by StockService; this only collects what was typed.
 */
#[Title('Stock entry')]
class StockEntryForm extends Component
{
    public const TYPES = ['adjust_in' => 'Stock found (add)', 'adjust_out' => 'Stock lost (remove)', 'scrap' => 'Scrap (write off)', 'transfer' => 'Transfer to another branch'];

    public string $type = 'adjust_out';

    public string $itemId = '';

    public string $itemLabel = '';

    public string $search = '';

    public string $locationId = '';

    public string $toLocationId = '';

    public string $batchId = '';

    public string $quantity = '';

    public string $unitCost = '';

    public string $reason = '';

    public function mount(): void
    {
        Gate::authorize('create', Batch::class);
    }

    public function pickItem(int $id): void
    {
        $item = Item::query()->where('approval_status', 'approved')->find($id);

        if ($item !== null) {
            $this->itemId = (string) $item->id;
            $this->itemLabel = trim($item->code.' · '.$item->name);
            $this->search = '';
            $this->batchId = '';
        }
    }

    public function clearItem(): void
    {
        $this->itemId = $this->itemLabel = $this->batchId = '';
    }

    public function save(StockService $stock): void
    {
        Gate::authorize('create', Batch::class);
        $this->resetErrorBag();

        $item = ctype_digit($this->itemId) ? Item::query()->where('approval_status', 'approved')->find((int) $this->itemId) : null;

        if ($item === null) {
            $this->addError('itemId', 'Choose the item.');

            return;
        }

        if (! ctype_digit($this->locationId) || ! Location::query()->whereKey((int) $this->locationId)->exists()) {
            $this->addError('locationId', 'Choose a branch you work in.');

            return;
        }

        $batch = null;

        if ($item->tracking === 'batch') {
            $batch = ctype_digit($this->batchId) ? Batch::query()->where('item_id', $item->id)->find((int) $this->batchId) : null;

            if ($batch === null) {
                $this->addError('batchId', 'Choose the batch.');

                return;
            }
        }

        $actor = Auth::user();
        $branch = (int) $this->locationId;

        try {
            match ($this->type) {
                'adjust_in' => $stock->adjustIn($item, $branch, $this->quantity, trim($this->unitCost) === '' ? null : trim($this->unitCost), $batch, $this->reason, $actor),
                'adjust_out' => $stock->adjustOut($item, $branch, $this->quantity, $batch, $this->reason, $actor),
                'scrap' => $stock->scrap($item, $branch, $this->quantity, $batch, $this->reason, $actor),
                'transfer' => $stock->transfer($item, $branch, ctype_digit($this->toLocationId) ? (int) $this->toLocationId : 0, $this->quantity, $batch, $this->reason, $actor),
                default => throw new StockException('Choose what kind of entry this is.'),
            };
        } catch (StockException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', 'The stock entry is recorded.');
        $this->redirectRoute('inventory.movements', navigate: false);
    }

    public function render(): View
    {
        $item = ctype_digit($this->itemId) ? Item::query()->with('baseUnit:id,code,name')->find((int) $this->itemId) : null;
        $branch = ctype_digit($this->locationId) ? (int) $this->locationId : null;

        return view('inventory.entry', [
            'types' => self::TYPES,
            'item' => $item,
            'results' => trim($this->search) === '' ? collect() : app(ItemLookup::class)->search($this->search, 6),
            'branches' => Location::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'batches' => $item?->tracking === 'batch' ? Batch::query()->where('item_id', $item->id)->orderBy('expiry_date')->orderBy('batch_no')->get(['id', 'batch_no', 'expiry_date', 'status']) : collect(),
            'onHand' => $item !== null && $branch !== null ? StockBalance::query()->where('item_id', $item->id)->where('location_id', $branch)->sum('quantity') : null,
        ]);
    }
}
