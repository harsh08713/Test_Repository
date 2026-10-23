<?php

namespace App\Modules\Masters\Http\Livewire\Items;

use App\Models\User;
use App\Modules\Industry\Models\CompanyIndustryTemplate;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Masters\Models\Brand;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\ItemGroup;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Services\ItemService;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One screen, two departments: it edits whichever part of the item is *this person's turn*.
 *
 * Purchase gets the Purchase part (name, group, units, tolerance, HSN, GST) while the item is in the Purchase stage;
 * Inventory gets its part (code, aliases, stock levels per branch) while the item is in the Inventory stage; anyone
 * else, or the same person at the wrong moment, is refused (403). Which section is shown is decided on the server
 * and locked; every rule lives in ItemService.
 */
#[Title('Item')]
class ItemForm extends Component
{
    #[Locked]
    public ?int $itemId = null;

    /** 'purchase' or 'inventory': the part this screen edits. */
    #[Locked]
    public string $section = 'purchase';

    /** Has the item been approved before? Its base unit, code and existing conversions are then fixed (the service enforces it). */
    #[Locked]
    public bool $approvedBefore = false;

    /** @var array<string, mixed> */
    public array $item = [];

    /** @var list<array<string, mixed>> */
    public array $uoms = [];

    /** @var array<string, mixed> */
    public array $inventory = [];

    /** @var list<array<string, mixed>> */
    public array $aliases = [];

    /** @var list<array<string, mixed>> */
    public array $locations = [];

    public function mount(?Item $editing = null): void
    {
        if ($editing?->exists) {
            Gate::authorize('update', $editing);

            $this->itemId = $editing->id;
            $this->section = Gate::allows('editPurchase', $editing) ? 'purchase' : 'inventory';
            $this->approvedBefore = $editing->everApproved();
            $this->loadFrom($editing);

            return;
        }

        Gate::authorize('create', Item::class);

        $this->item = [
            'name' => '', 'description' => '', 'item_group_id' => '', 'brand_id' => '', 'industry_template_id' => '', 'standard_cost' => '', 'base_unit_id' => '',
            'po_tolerance_percent' => '0', 'hsn_code' => '', 'gst_rate' => '',
        ];
    }

    // ---- repeaters ------------------------------------------------------------------------------------------

    public function addUom(): void
    {
        if ($this->section === 'purchase' && count($this->uoms) < ItemService::MAX_UOMS) {
            $this->uoms[] = ['key' => Str::random(6), 'id' => '', 'unit_id' => '', 'factor' => ''];
        }
    }

    public function removeUom(int $index): void
    {
        if ($this->section === 'purchase') {
            unset($this->uoms[$index]);
            $this->uoms = array_values($this->uoms);
        }
    }

    public function addAlias(): void
    {
        if ($this->section === 'inventory' && count($this->aliases) < ItemService::MAX_ALIASES) {
            $this->aliases[] = ['key' => Str::random(6), 'id' => '', 'alias' => ''];
        }
    }

    public function removeAlias(int $index): void
    {
        if ($this->section === 'inventory') {
            unset($this->aliases[$index]);
            $this->aliases = array_values($this->aliases);
        }
    }

    public function addLocation(): void
    {
        if ($this->section === 'inventory' && count($this->locations) < ItemService::MAX_LOCATIONS) {
            $this->locations[] = ['key' => Str::random(6), 'id' => '', 'location_id' => '', 'minimum_stock' => '0', 'reorder_level' => '0', 'maximum_stock' => '0'];
        }
    }

    public function removeLocation(int $index): void
    {
        if ($this->section === 'inventory') {
            unset($this->locations[$index]);
            $this->locations = array_values($this->locations);
        }
    }

    public function suggestCode(ItemService $service): void
    {
        if ($this->section === 'inventory' && trim((string) ($this->inventory['code'] ?? '')) === '') {
            $this->inventory['code'] = $service->suggestCode();
        }
    }

    // ---- save -----------------------------------------------------------------------------------------------

    public function savePurchase(ItemService $service): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $this->section === 'purchase', 403);

        $editing = $this->itemId !== null ? Item::query()->findOrFail($this->itemId) : null;
        Gate::authorize($editing ? 'editPurchase' : 'create', $editing ?? Item::class);

        try {
            $saved = $service->savePurchaseStage($editing, ['item' => $this->item, 'uoms' => $this->uoms], $user);
        } catch (ApprovalStateException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', $editing ? 'Purchase details saved.' : 'Item started. When the Purchase details are complete, send it to Inventory.');
        $this->redirectRoute('masters.items.show', $saved, navigate: false);
    }

    public function saveInventory(ItemService $service): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $this->section === 'inventory' && $this->itemId !== null, 403);

        $item = Item::query()->findOrFail($this->itemId);
        Gate::authorize('editInventory', $item);

        try {
            $saved = $service->saveInventoryStage($item, ['inventory' => $this->inventory, 'aliases' => $this->aliases, 'locations' => $this->locations], $user);
        } catch (ApprovalStateException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', 'Inventory details saved. When the item code is assigned, submit it for approval.');
        $this->redirectRoute('masters.items.show', $saved, navigate: false);
    }

    public function render(ItemService $service): View
    {
        $groups = ItemGroup::query()->get();
        $byId = $groups->keyBy('id');
        $keepGroup = is_numeric($this->item['item_group_id'] ?? null) ? (int) $this->item['item_group_id'] : null;
        $keepUnit = is_numeric($this->item['base_unit_id'] ?? null) ? (int) $this->item['base_unit_id'] : null;
        $usedLocations = collect($this->locations)->pluck('location_id')->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->all();

        $baseUnit = $keepUnit === null ? null : Unit::query()->find($keepUnit);

        return view('masters.items.form', [
            'editing' => $this->itemId !== null,
            'groups' => $groups->filter(fn (ItemGroup $g) => $g->status === 'active' || $g->id === $keepGroup)
                ->mapWithKeys(function (ItemGroup $g) use ($byId) {
                    $path = [];
                    for ($node = $g, $guard = 0; $node !== null && $guard < 20; $node = $byId->get($node->parent_id), $guard++) {
                        array_unshift($path, $node->name);
                    }

                    return [$g->id => implode(' › ', $path)];
                })->sort()->all(),
            'units' => Unit::query()->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $keepUnit))->orderBy('name')->get(['id', 'code', 'name'])
                ->mapWithKeys(fn (Unit $u) => [$u->id => "{$u->name} ({$u->code})"])->all(),
            'brands' => Brand::query()->where(fn ($q) => $q->where('status', 'active')->orWhere('id', is_numeric($this->item['brand_id'] ?? null) ? (int) $this->item['brand_id'] : 0))
                ->orderBy('name')->pluck('name', 'id')->all(),
            'industries' => IndustryTemplate::query()
                ->where(fn ($q) => $q->whereIn('id', CompanyIndustryTemplate::query()->select('industry_template_id'))->orWhere('id', is_numeric($this->item['industry_template_id'] ?? null) ? (int) $this->item['industry_template_id'] : 0))
                ->orderBy('name')->pluck('name', 'id')->all(),
            'baseUnit' => $baseUnit,
            'gstRates' => array_map(fn ($rate) => rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.'), (array) config('formezy.gst_rates')),
            'branches' => Location::query()->where(fn ($q) => $q->where('status', 'active')->orWhereIn('id', $usedLocations))->orderBy('name')->pluck('name', 'id')->all(),
            'suggestedCode' => $this->section === 'inventory' ? $service->suggestCode() : null,
            'summary' => $this->itemId !== null ? Item::query()->with(['itemGroup', 'baseUnit', 'uoms.unit'])->find($this->itemId) : null,
            'maxRows' => ['uoms' => ItemService::MAX_UOMS, 'aliases' => ItemService::MAX_ALIASES, 'locations' => ItemService::MAX_LOCATIONS],
        ]);
    }

    // ---- state ----------------------------------------------------------------------------------------------

    private function loadFrom(Item $item): void
    {
        $str = fn (mixed $v): string => $v === null ? '' : (string) $v;

        $this->item = [
            'name' => $item->name, 'description' => $str($item->description), 'item_group_id' => (string) $item->item_group_id,
            'brand_id' => $str($item->brand_id), 'industry_template_id' => $str($item->industry_template_id), 'standard_cost' => $item->standard_cost === null ? '' : (rtrim(rtrim((string) $item->standard_cost, '0'), '.') ?: '0'),
            'base_unit_id' => (string) $item->base_unit_id, 'po_tolerance_percent' => (string) $item->po_tolerance_percent,
            'hsn_code' => $str($item->hsn_code), 'gst_rate' => $item->gst_rate === null ? '' : rtrim(rtrim((string) $item->gst_rate, '0'), '.'),
        ];

        $this->uoms = $item->uoms()->get()->map(fn ($u) => [
            'key' => Str::random(6), 'id' => (string) $u->id, 'unit_id' => (string) $u->unit_id, 'factor' => rtrim(rtrim($u->factor, '0'), '.'),
        ])->all();

        $this->inventory = ['code' => $str($item->code), 'tracking' => (string) $item->tracking, 'has_expiry' => (bool) $item->has_expiry, 'shelf_life_days' => $str($item->shelf_life_days)];

        $this->aliases = $item->aliases()->get()->map(fn ($a) => ['key' => Str::random(6), 'id' => (string) $a->id, 'alias' => $a->alias])->all();

        // Only the branches this person works in: the others are left untouched by a save.
        $visible = Location::query()->pluck('id')->all();
        $this->locations = $item->locationParameters()->whereIn('location_id', $visible)->get()->map(fn ($p) => [
            'key' => Str::random(6), 'id' => (string) $p->id, 'location_id' => (string) $p->location_id,
            'minimum_stock' => rtrim(rtrim($p->minimum_stock, '0'), '.') ?: '0',
            'reorder_level' => rtrim(rtrim($p->reorder_level, '0'), '.') ?: '0',
            'maximum_stock' => rtrim(rtrim($p->maximum_stock, '0'), '.') ?: '0',
        ])->all();
    }
}
