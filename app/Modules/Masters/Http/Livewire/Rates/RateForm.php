<?php

namespace App\Modules\Masters\Http\Livewire\Rates;

use App\Models\User;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\PriceList;
use App\Modules\Masters\Models\Rate;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Masters\Services\RateMaintenance;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Add or correct a rate while it is a draft (or was rejected). Every rule lives in RateMaintenance; this screen
 * only offers the choices the person may make — approved, active items; units the chosen item has; their branches.
 * "Copy as new" opens the form pre-filled from an existing rate (without its dates) as a new draft.
 */
#[Title('Rate')]
class RateForm extends Component
{
    #[Locked]
    public ?int $rateId = null;

    /** Has the rate been approved before? Who and where it is for is then fixed (the service enforces it). */
    #[Locked]
    public bool $approvedBefore = false;

    /** @var array<string, mixed> */
    public array $rate = [];

    /** Narrows the item choices; a few hundred items would be an unusable drop-down. */
    public string $itemSearch = '';

    public function mount(?Rate $editing = null): void
    {
        if ($editing?->exists) {
            Gate::authorize('update', $editing);

            $this->rateId = $editing->id;
            $this->approvedBefore = $editing->approvals()->where('status', 'approved')->exists();
            $this->rate = $this->valuesFrom($editing, copy: false);

            return;
        }

        Gate::authorize('create', Rate::class);

        $this->rate = ['item_id' => '', 'location_id' => '', 'unit_id' => '', 'price_list_id' => '', 'rate' => '', 'effective_from' => '', 'effective_to' => '', 'remarks' => ''];

        $copy = request()->query('copy');
        if (is_string($copy) && ctype_digit($copy)) {
            $source = Rate::query()->find((int) $copy);

            if ($source !== null) {
                Gate::authorize('view', $source);
                $this->rate = $this->valuesFrom($source, copy: true);
            }
        }
    }

    /** A different item has different units: start from its base unit. */
    public function updatedRate(mixed $value, string $key): void
    {
        if ($key !== 'item_id') {
            return;
        }

        $item = is_numeric($value) ? Item::query()->find((int) $value) : null;
        $this->rate['unit_id'] = $item === null ? '' : (string) $item->base_unit_id;
    }

    public function save(RateMaintenance $maintenance): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $editing = $this->rateId !== null ? Rate::query()->findOrFail($this->rateId) : null;
        Gate::authorize($editing ? 'update' : 'create', $editing ?? Rate::class);

        try {
            $saved = $maintenance->save($editing, ['rate' => $this->rate], $user);
        } catch (ApprovalStateException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', $editing ? 'Rate saved.' : 'Rate added as a draft. Submit it for approval when it is ready.');
        $this->redirectRoute('masters.rates.show', $saved, navigate: false);
    }

    public function render(ItemLookup $items): View
    {
        $keepItem = is_numeric($this->rate['item_id'] ?? null) ? (int) $this->rate['item_id'] : null;
        $keepBranch = is_numeric($this->rate['location_id'] ?? null) ? (int) $this->rate['location_id'] : null;
        $keepList = is_numeric($this->rate['price_list_id'] ?? null) ? (int) $this->rate['price_list_id'] : null;

        $choices = $items->search($this->itemSearch, 20);
        $selected = $keepItem === null ? null : Item::query()->with(['baseUnit', 'uoms.unit'])->find($keepItem);

        if ($selected !== null && $choices->doesntContain('id', $selected->id)) {
            $choices->prepend($selected);
        }

        $unitOptions = [];
        if ($selected !== null) {
            $unitOptions[$selected->base_unit_id] = ($selected->baseUnit->name ?? '').' ('.($selected->baseUnit->code ?? '').') — base unit';

            foreach ($selected->uoms as $row) {
                $unitOptions[$row->unit_id] = "{$row->unit->name} ({$row->unit->code}) — 1 = ".rtrim(rtrim($row->factor, '0'), '.')." {$selected->baseUnit?->name}";
            }
        }

        return view('masters.rates.form', [
            'editing' => $this->rateId !== null,
            'items' => $choices->mapWithKeys(fn (Item $i) => [$i->id => trim(($i->code ? "{$i->code} · " : '').$i->name)])->all(),
            'selectedItem' => $selected,
            'units' => $unitOptions,
            'branches' => Location::query()->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $keepBranch))->orderBy('name')->pluck('name', 'id')->all(),
            'priceLists' => PriceList::query()->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $keepList))->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    /** @return array<string, string> */
    private function valuesFrom(Rate $source, bool $copy): array
    {
        $trim = fn (string $v): string => rtrim(rtrim($v, '0'), '.');

        return [
            'item_id' => (string) $source->item_id,
            'location_id' => (string) $source->location_id,
            'unit_id' => (string) $source->unit_id,
            'price_list_id' => $source->price_list_id === null ? '' : (string) $source->price_list_id,
            'rate' => $trim((string) $source->rate),
            'effective_from' => $copy ? '' : $source->effective_from->toDateString(),
            'effective_to' => $copy ? '' : ($source->effective_to?->toDateString() ?? ''),
            'remarks' => $copy ? '' : (string) ($source->remarks ?? ''),
        ];
    }
}
