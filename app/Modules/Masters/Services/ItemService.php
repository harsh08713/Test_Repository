<?php

namespace App\Modules\Masters\Services;

use App\Models\User;
use App\Modules\Masters\Enums\ItemStage;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\ItemAlias;
use App\Modules\Masters\Models\ItemLocationParameter;
use App\Modules\Masters\Models\ItemUom;
use App\Modules\Masters\Models\Unit;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates and changes items, one department's stage at a time.
 *
 * It is the single gate for item data: the forms, the demo seeder and any future import share its validation. It
 * does not decide who may do it (ItemPolicy does) and does not move the item between stages (ItemWorkflow does);
 * it does refuse to touch a stage the item is not in.
 *
 * Stage 1 (Purchase)   ['item' => [name, item_group_id, base_unit_id, po_tolerance_percent, hsn_code, gst_rate, description],
 *                       'uoms' => [[id?, unit_id, factor], …]]
 * Stage 2 (Inventory)  ['inventory' => [code], 'aliases' => [[id?, alias], …],
 *                       'locations' => [[id?, location_id, minimum_stock, reorder_level, maximum_stock], …]]
 */
final class ItemService
{
    public const MAX_UOMS = 10;

    public const MAX_ALIASES = 20;

    public const MAX_LOCATIONS = 100;

    public function __construct(private readonly TenantContext $tenant) {}

    /** A code nobody has used yet, e.g. ITM-00007. Not reserved: a clash at save time is reported like any duplicate. */
    public function suggestCode(): string
    {
        $last = (int) Item::withTrashed()
            ->where('code', 'like', 'ITM-%')
            ->whereRaw('code regexp ?', ['^ITM-[0-9]+$'])
            ->max(DB::raw('cast(substring(code, 5) as unsigned)'));

        return sprintf('ITM-%05d', $last + 1);
    }

    // ---- stage 1: Purchase ------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws ApprovalStateException when the item is no longer in the Purchase stage
     */
    public function savePurchaseStage(?Item $item, array $input, User $by): Item
    {
        if ($item !== null && ($item->workflow_stage !== ItemStage::PurchaseDraft || ! $item->isEditableByWorkflow())) {
            throw ApprovalStateException::notReady('This item is not in the Purchase stage, so its Purchase details cannot be changed now.');
        }

        $data = $this->validatePurchase($input, $item);

        return DB::transaction(function () use ($data, $item, $by) {
            $model = $item ?? new Item;
            $model->fill($data['item']);

            if ($item === null) {
                $model->forceFill(['company_id' => (int) $this->tenant->activeCompanyId(), 'created_by' => $by->getKey()]);
                $model->stageActor = $by;
            }

            $model->forceFill(['updated_by' => $by->getKey()])->save();

            $this->syncUoms($model, $data['uoms'], $by);

            return $model->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{item: array<string, mixed>, uoms: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function validatePurchase(array $input, ?Item $editing): array
    {
        $companyId = (int) $this->tenant->activeCompanyId();
        $data = $this->normalisePurchase($input);
        $locked = $editing !== null && $editing->everApproved();

        $usable = fn (string $table, ?int $keep) => Rule::exists($table, 'id')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('status', 'active')->when($keep !== null, fn ($q) => $q->orWhere('id', $keep)));

        $rules = [
            'item.name' => ['required', 'string', 'max:200', Rule::unique('items', 'name')->where('company_id', $companyId)->ignore($editing?->id)],
            'item.description' => ['nullable', 'string', 'max:1000'],
            'item.item_group_id' => ['required', 'integer', $usable('item_groups', $editing?->item_group_id)],
            'item.brand_id' => ['nullable', 'integer', $usable('brands', $editing?->brand_id)],
            'item.industry_template_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($editing) {
                // an industry template the company has switched on (or the one the item already has)
                $on = DB::table('company_industry_templates')->where('company_id', $this->tenant->activeCompanyId())->pluck('industry_template_id')->all();
                if (! in_array((int) $value, [...$on, (int) ($editing->industry_template_id ?? 0)], true)) {
                    $fail('Choose an industry your company uses.');
                }
            }],
            'item.standard_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'item.base_unit_id' => ['required', 'integer', $usable('units', $editing?->base_unit_id)],
            'item.po_tolerance_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'item.hsn_code' => ['nullable', 'regex:/^\d{4}(\d{2}(\d{2})?)?$/'],
            'item.gst_rate' => ['nullable', 'numeric', function (string $attribute, mixed $value, \Closure $fail) {
                $allowed = array_map(fn ($rate) => round((float) $rate, 2), (array) config('formezy.gst_rates'));

                if (! in_array(round((float) $value, 2), $allowed, true)) {
                    $fail('The GST rate must be one of: '.implode('%, ', array_map(fn ($r) => rtrim(rtrim(number_format($r, 2, '.', ''), '0'), '.'), $allowed)).'%.');
                }
            }],
            'uoms' => ['array', 'max:'.self::MAX_UOMS],
        ];

        foreach ($data['uoms'] as $i => $row) {
            $rules["uoms.$i.id"] = ['nullable', 'integer'];
            // a unit deactivated since the row was made is still fine on that row
            $rules["uoms.$i.unit_id"] = ['required', 'integer', $usable('units', $this->existingUnitFor($editing, $row['id']))];
            $rules["uoms.$i.factor"] = ['required', 'numeric', 'gt:0', 'max:9999999999.999999', 'decimal:0,6'];
        }

        $validator = Validator::make($data, $rules, $this->purchaseMessages(), $this->purchaseAttributes($data));
        $validator->after(fn ($v) => $this->purchaseCrossChecks($v, $data, $editing, $locked));
        $validator->validate();

        return $this->finalisePurchase($data);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{item: array<string, mixed>, uoms: list<array<string, mixed>>}
     */
    private function normalisePurchase(array $input): array
    {
        $text = fn (mixed $v): ?string => ($v = trim((string) preg_replace('/\s+/u', ' ', (string) ($v ?? '')))) === '' ? null : $v;
        $i = (array) ($input['item'] ?? []);

        $item = [
            'name' => $text($i['name'] ?? null),
            'description' => $text($i['description'] ?? null),
            'item_group_id' => $text($i['item_group_id'] ?? null),
            'brand_id' => $text($i['brand_id'] ?? null),
            'industry_template_id' => $text($i['industry_template_id'] ?? null),
            'standard_cost' => $text($i['standard_cost'] ?? null),
            'base_unit_id' => $text($i['base_unit_id'] ?? null),
            'po_tolerance_percent' => $text($i['po_tolerance_percent'] ?? null) ?? '0',
            'hsn_code' => ($hsn = $text($i['hsn_code'] ?? null)) === null ? null : preg_replace('/[\s.\-]/', '', $hsn),
            'gst_rate' => $text($i['gst_rate'] ?? null),
        ];

        $uoms = [];
        foreach (array_values((array) ($input['uoms'] ?? [])) as $row) {
            $row = (array) $row;
            $uoms[] = ['id' => $text($row['id'] ?? null), 'unit_id' => $text($row['unit_id'] ?? null), 'factor' => $text($row['factor'] ?? null)];
        }

        return ['item' => $item, 'uoms' => $uoms];
    }

    /** The unit an existing conversion row already uses (so a unit deactivated since is still accepted on its own row). */
    private function existingUnitFor(?Item $item, ?string $rowId): ?int
    {
        if ($item === null || $rowId === null || ! ctype_digit($rowId)) {
            return null;
        }

        return $item->uoms()->whereKey((int) $rowId)->value('unit_id');
    }

    /**
     * @param  array{item: array<string, mixed>, uoms: list<array<string, mixed>>}  $data
     */
    private function purchaseCrossChecks(\Illuminate\Validation\Validator $validator, array $data, ?Item $editing, bool $locked): void
    {
        $errors = $validator->errors();
        $baseId = is_numeric($data['item']['base_unit_id']) ? (int) $data['item']['base_unit_id'] : null;
        $base = $baseId === null ? null : Unit::query()->find($baseId);

        // An item that has been approved may already be on documents: its base unit and conversions are what those quantities mean.
        if ($locked && $editing !== null && $baseId !== $editing->base_unit_id) {
            $errors->add('item.base_unit_id', 'The base unit of an item that has been approved cannot be changed.');
        }

        $seen = [];
        foreach ($data['uoms'] as $i => $row) {
            $unitId = is_numeric($row['unit_id']) ? (int) $row['unit_id'] : null;

            if ($unitId !== null && $unitId === $baseId) {
                $errors->add("uoms.$i.unit_id", 'This is the base unit. Choose a different unit to convert from.');
            }

            if ($unitId !== null && isset($seen[$unitId])) {
                $errors->add("uoms.$i.unit_id", 'Each unit can have only one conversion.');
            }
            $seen[$unitId] = true;

            // "1 Box = 2.5 Nos" would mean half a piece: a base unit that cannot be split needs whole-number factors.
            if ($base !== null && ! $base->decimal_allowed && is_numeric($row['factor']) && bccomp(bcadd((string) $row['factor'], '0', 0), (string) $row['factor'], 6) !== 0) {
                $errors->add("uoms.$i.factor", "The base unit {$base->name} cannot be split, so a conversion must be a whole number of {$base->name}.");
            }
        }

        if ($locked && $editing !== null) {
            $payload = collect($data['uoms'])->filter(fn ($r) => $r['id'] !== null)->keyBy(fn ($r) => (int) $r['id']);

            foreach ($editing->uoms()->get() as $existing) {
                $row = $payload->get($existing->id);

                if ($row === null) {
                    $errors->add('uoms', "The {$existing->unit->name} conversion cannot be removed: the item has been approved and may already be on documents.");
                } elseif ((int) $row['unit_id'] !== $existing->unit_id || ! is_numeric($row['factor']) || bccomp((string) $row['factor'], $existing->factor, 6) !== 0) {
                    $errors->add('uoms', "The {$existing->unit->name} conversion cannot be changed: the item has been approved and may already be on documents. You can add new units.");
                }
            }
        }
    }

    /**
     * @param  array{item: array<string, mixed>, uoms: list<array<string, mixed>>}  $data
     * @return array{item: array<string, mixed>, uoms: list<array<string, mixed>>}
     */
    private function finalisePurchase(array $data): array
    {
        $item = $data['item'];
        $item['item_group_id'] = (int) $item['item_group_id'];
        $item['base_unit_id'] = (int) $item['base_unit_id'];
        $item['brand_id'] = $item['brand_id'] === null ? null : (int) $item['brand_id'];
        $item['industry_template_id'] = $item['industry_template_id'] === null ? null : (int) $item['industry_template_id'];
        $item['standard_cost'] = $item['standard_cost'] === null ? null : bcadd((string) $item['standard_cost'], '0', 4);
        $item['po_tolerance_percent'] = number_format((float) $item['po_tolerance_percent'], 2, '.', '');
        $item['gst_rate'] = $item['gst_rate'] === null ? null : number_format((float) $item['gst_rate'], 2, '.', '');
        $data['item'] = $item;

        foreach ($data['uoms'] as $i => $row) {
            $data['uoms'][$i] = [
                'id' => $row['id'] === null ? null : (int) $row['id'],
                'unit_id' => (int) $row['unit_id'],
                'factor' => bcadd((string) $row['factor'], '0', 6),
            ];
        }

        return $data;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncUoms(Item $item, array $rows, User $by): void
    {
        $this->syncChildren($item, 'uoms', ItemUom::class, $rows, ['unit_id', 'factor'], $by);
    }

    // ---- stage 2: Inventory -----------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws ApprovalStateException when the item is no longer in the Inventory stage
     */
    public function saveInventoryStage(Item $item, array $input, User $by): Item
    {
        if ($item->workflow_stage !== ItemStage::InventoryPending || ! $item->isEditableByWorkflow()) {
            throw ApprovalStateException::notReady('This item is not in the Inventory stage, so its Inventory details cannot be changed now.');
        }

        $data = $this->validateInventory($input, $item, $by);

        return DB::transaction(function () use ($data, $item, $by) {
            $item->forceFill([
                'code' => $data['inventory']['code'], 'tracking' => $data['inventory']['tracking'], 'has_expiry' => $data['inventory']['has_expiry'],
                'shelf_life_days' => $data['inventory']['shelf_life_days'], 'updated_by' => $by->getKey(),
            ])->save();

            $this->syncChildren($item, 'aliases', ItemAlias::class, $data['aliases'], ['alias'], $by);
            $this->syncLocations($item, $data['locations'], $by);

            return $item->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{inventory: array{code: string|null, tracking: string, has_expiry: bool, shelf_life_days: string|null}, aliases: list<array<string, mixed>>, locations: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function validateInventory(array $input, Item $item, User $by): array
    {
        $companyId = (int) $this->tenant->activeCompanyId();
        $data = $this->normaliseInventory($input);
        $codeFrozen = $item->code !== null && $item->everApproved();

        $rules = [
            'inventory.code' => ['nullable', 'regex:/^[A-Z0-9][A-Z0-9._\-\/]{1,29}$/', Rule::unique('items', 'code')->where('company_id', $companyId)->ignore($item->id)],
            'inventory.tracking' => ['required', 'in:none,batch'],
            'inventory.has_expiry' => ['boolean'],
            'inventory.shelf_life_days' => ['nullable', 'integer', 'between:1,36500'],
            'aliases' => ['array', 'max:'.self::MAX_ALIASES],
            'locations' => ['array', 'max:'.self::MAX_LOCATIONS],
        ];

        foreach ($data['aliases'] as $i => $row) {
            $rules["aliases.$i.id"] = ['nullable', 'integer'];
            $rules["aliases.$i.alias"] = ['required', 'string', 'min:2', 'max:150'];
        }

        foreach ($data['locations'] as $i => $row) {
            $rules["locations.$i.id"] = ['nullable', 'integer'];
            $rules["locations.$i.location_id"] = ['required', 'integer', Rule::exists('locations', 'id')->where('company_id', $companyId)->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('status', 'active')->orWhereIn('id', $item->locationParameters()->pluck('location_id')))];
            foreach (['minimum_stock', 'reorder_level', 'maximum_stock'] as $field) {
                $rules["locations.$i.$field"] = ['required', 'numeric', 'min:0', 'max:999999999999.999', 'decimal:0,3'];
            }
        }

        $validator = Validator::make($data, $rules, $this->inventoryMessages(), $this->inventoryAttributes($data));
        $validator->after(fn ($v) => $this->inventoryCrossChecks($v, $data, $item, $by, $codeFrozen));
        $validator->validate();

        foreach ($data['aliases'] as $i => $row) {
            $data['aliases'][$i]['id'] = $row['id'] === null ? null : (int) $row['id'];
        }

        foreach ($data['locations'] as $i => $row) {
            $data['locations'][$i] = [
                'id' => $row['id'] === null ? null : (int) $row['id'],
                'location_id' => (int) $row['location_id'],
                'minimum_stock' => bcadd((string) $row['minimum_stock'], '0', 3),
                'reorder_level' => bcadd((string) $row['reorder_level'], '0', 3),
                'maximum_stock' => bcadd((string) $row['maximum_stock'], '0', 3),
            ];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{inventory: array{code: string|null, tracking: string, has_expiry: bool, shelf_life_days: string|null}, aliases: list<array<string, mixed>>, locations: list<array<string, mixed>>}
     */
    private function normaliseInventory(array $input): array
    {
        $text = fn (mixed $v): ?string => ($v = trim((string) preg_replace('/\s+/u', ' ', (string) ($v ?? '')))) === '' ? null : $v;
        $inv = (array) ($input['inventory'] ?? []);
        $code = $text($inv['code'] ?? null);

        $aliases = [];
        foreach (array_values((array) ($input['aliases'] ?? [])) as $row) {
            $row = (array) $row;
            $aliases[] = ['id' => $text($row['id'] ?? null), 'alias' => $text($row['alias'] ?? null)];
        }

        $locations = [];
        foreach (array_values((array) ($input['locations'] ?? [])) as $row) {
            $row = (array) $row;
            $locations[] = [
                'id' => $text($row['id'] ?? null), 'location_id' => $text($row['location_id'] ?? null),
                'minimum_stock' => $text($row['minimum_stock'] ?? null), 'reorder_level' => $text($row['reorder_level'] ?? null), 'maximum_stock' => $text($row['maximum_stock'] ?? null),
            ];
        }

        $inventory = [
            'code' => $code === null ? null : strtoupper(str_replace(' ', '', $code)),
            'tracking' => $text($inv['tracking'] ?? null) ?? 'none',
            'has_expiry' => filter_var($inv['has_expiry'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'shelf_life_days' => $text($inv['shelf_life_days'] ?? null),
        ];

        return ['inventory' => $inventory, 'aliases' => $aliases, 'locations' => $locations];
    }

    /**
     * @param  array{inventory: array{code: string|null, tracking: string, has_expiry: bool, shelf_life_days: string|null}, aliases: list<array<string, mixed>>, locations: list<array<string, mixed>>}  $data
     */
    private function inventoryCrossChecks(\Illuminate\Validation\Validator $validator, array $data, Item $item, User $by, bool $codeFrozen): void
    {
        $errors = $validator->errors();
        $item->loadMissing('baseUnit');

        if ($data['inventory']['has_expiry'] && $data['inventory']['tracking'] !== 'batch') {
            $errors->add('inventory.tracking', 'An item that expires has to be tracked by batch: the expiry date belongs to a batch.');
        }

        if ($data['inventory']['shelf_life_days'] !== null && ! $data['inventory']['has_expiry']) {
            $errors->add('inventory.shelf_life_days', 'A shelf life only makes sense for an item that expires.');
        }

        // Once stock has moved, what a quantity means must not change under it.
        if ($data['inventory']['tracking'] !== $item->tracking && DB::table('stock_movements')->where('item_id', $item->id)->exists()) {
            $errors->add('inventory.tracking', 'Stock has already moved for this item, so the way it is tracked cannot be changed.');
        }

        // Once approved, an item's code is how people refer to it on paper: it stays.
        if ($codeFrozen && $data['inventory']['code'] !== $item->code) {
            $errors->add('inventory.code', 'The code of an item that has been approved cannot be changed.');
        }

        // Aliases: no repeats, never the item's own name or code, and never anything another item already answers to.
        $seen = [];
        foreach ($data['aliases'] as $i => $row) {
            $alias = $row['alias'];

            if ($alias === null) {
                continue;
            }

            $key = mb_strtolower($alias);

            if (isset($seen[$key])) {
                $errors->add("aliases.$i.alias", 'This alias is listed twice.');
            }
            $seen[$key] = true;

            if (strcasecmp($alias, (string) $item->name) === 0 || ($item->code !== null && strcasecmp($alias, $item->code) === 0)
                || ($data['inventory']['code'] !== null && strcasecmp($alias, $data['inventory']['code']) === 0)) {
                $errors->add("aliases.$i.alias", 'This is already the item\'s own name or code.');

                continue;
            }

            $clash = Item::query()->whereKeyNot($item->id)->where(fn ($q) => $q->where('name', $alias)->orWhere('code', $alias))->first(['id', 'name']);
            $other = $clash ?? ItemAlias::query()->where('alias', $alias)->where('item_id', '!=', $item->id)->first()?->item()->first(['id', 'name']);

            if ($other !== null) {
                $errors->add("aliases.$i.alias", "\"{$alias}\" already identifies another item ({$other->name}).");
            }
        }

        // Locations: one row per branch, only branches this person works in, quantities that make sense for the unit.
        $accessible = $this->tenant->accessibleLocationIdsFor($by, (int) $this->tenant->activeCompanyId());
        $own = $item->locationParameters()->pluck('location_id')->all();
        $seenLocations = [];
        $whole = ! $item->baseUnit->decimal_allowed;

        foreach ($data['locations'] as $i => $row) {
            $locationId = is_numeric($row['location_id']) ? (int) $row['location_id'] : null;

            if ($locationId !== null) {
                if (isset($seenLocations[$locationId])) {
                    $errors->add("locations.$i.location_id", 'This branch is listed twice. Each branch has one set of stock levels.');
                }
                $seenLocations[$locationId] = true;

                if ($accessible !== null && ! in_array($locationId, $accessible, true) && ! in_array($locationId, $own, true)) {
                    $errors->add("locations.$i.location_id", 'You do not work in this branch.');
                }
            }

            $values = array_map(fn ($f) => $row[$f], ['minimum_stock', 'reorder_level', 'maximum_stock']);

            if (in_array(null, $values, true) || array_filter($values, fn ($v) => ! is_numeric($v)) !== []) {
                continue;   // the field rules already said what is wrong
            }

            [$min, $reorder, $max] = $values;

            if ($whole) {
                foreach (['minimum_stock', 'reorder_level', 'maximum_stock'] as $field) {
                    if (bccomp(bcadd((string) $row[$field], '0', 0), (string) $row[$field], 3) !== 0) {
                        $errors->add("locations.$i.$field", "The base unit {$item->baseUnit->name} cannot be split, so stock levels must be whole numbers.");
                    }
                }
            }

            if (bccomp((string) $min, (string) $reorder, 3) > 0) {
                $errors->add("locations.$i.minimum_stock", 'The minimum stock cannot be more than the reorder level.');
            }

            if (bccomp((string) $reorder, (string) $max, 3) > 0) {
                $errors->add("locations.$i.reorder_level", 'The reorder level cannot be more than the maximum stock.');
            }
        }
    }

    /**
     * Branch rows follow the branches the person works in: rows for branches they cannot see are left exactly as they
     * are, so editing from one branch never wipes another branch's stock levels.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncLocations(Item $item, array $rows, User $by): void
    {
        $accessible = $this->tenant->accessibleLocationIdsFor($by, $item->company_id);

        $this->syncChildren(
            $item, 'locationParameters', ItemLocationParameter::class, $rows, ['location_id', 'minimum_stock', 'reorder_level', 'maximum_stock'], $by,
            fn (Model $existing) => $accessible === null || in_array($existing->getAttribute('location_id'), $accessible, true),
        );
    }

    // ---- shared plumbing ----------------------------------------------------------------------------------------

    /**
     * Make the item's child rows match the submitted list: update those it names, add the new ones, remove the
     * rest. A row id that is not the item's own is a tampered request. Removed rows are deleted outright (their
     * unique keys must be reusable); the audit log keeps what they held.
     *
     * @param  class-string<Model>  $class
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fields
     * @param  (callable(Model): bool)|null  $manageable  which existing rows this save may touch (default: all)
     */
    private function syncChildren(Item $item, string $relation, string $class, array $rows, array $fields, User $by, ?callable $manageable = null): void
    {
        $existing = $item->{$relation}()->get()->keyBy('id');
        $payloadIds = [];

        foreach ($rows as $index => $row) {
            if ($row['id'] !== null) {
                if (! $existing->has($row['id'])) {
                    throw ValidationException::withMessages(["{$relation}.{$index}.id" => 'This row does not belong to the item.']);
                }
                $payloadIds[] = $row['id'];
            }
        }

        // Remove first, so a unit, alias or branch can leave one row and come back on another in the same save.
        foreach ($existing as $id => $model) {
            if (! in_array($id, $payloadIds, true) && ($manageable === null || $manageable($model))) {
                $model->forceDelete();
            }
        }

        try {
            foreach ($rows as $row) {
                $model = $row['id'] !== null ? $existing->get($row['id']) : new $class;
                $model->fill(Arr::only($row, $fields));

                if (! $model->exists) {
                    $model->forceFill(['company_id' => $item->company_id, 'item_id' => $item->id, 'created_by' => $by->getKey()]);
                }

                $model->forceFill(['updated_by' => $by->getKey()])->save();
            }
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([$relation => 'Two rows cannot be the same: each unit, alias or branch can appear once.']);
        }
    }

    // ---- wording ------------------------------------------------------------------------------------------------

    /** @return array<string, string> */
    private function purchaseMessages(): array
    {
        return [
            'item.name.unique' => 'An item with this name already exists.',
            'item.item_group_id.exists' => 'Choose an active item group from the list.',
            'item.base_unit_id.exists' => 'Choose an active unit from the list.',
            'item.hsn_code.regex' => 'An HSN/SAC code is 4, 6 or 8 digits.',
            'item.po_tolerance_percent.between' => 'The PO tolerance is a percentage from 0 to 100.',
            '*.unit_id.exists' => 'Choose an active unit from the list.',
            '*.factor.gt' => 'A conversion must be more than zero.',
            '*.factor.decimal' => 'A conversion can have at most 6 decimal places.',
        ];
    }

    /**
     * @param  array{item: array<string, mixed>, uoms: list<array<string, mixed>>}  $data
     * @return array<string, string>
     */
    private function purchaseAttributes(array $data): array
    {
        $names = [
            'item.name' => 'name', 'item.description' => 'description', 'item.item_group_id' => 'item group', 'item.base_unit_id' => 'base unit',
            'item.po_tolerance_percent' => 'PO tolerance', 'item.brand_id' => 'brand', 'item.industry_template_id' => 'industry', 'item.standard_cost' => 'standard cost', 'item.hsn_code' => 'HSN code', 'item.gst_rate' => 'GST rate',
        ];

        foreach (array_keys($data['uoms']) as $i) {
            $n = $i + 1;
            $names["uoms.$i.unit_id"] = "conversion $n unit";
            $names["uoms.$i.factor"] = "conversion $n quantity";
        }

        return $names;
    }

    /** @return array<string, string> */
    private function inventoryMessages(): array
    {
        return [
            'inventory.code.unique' => 'This item code is already in use.',
            'inventory.code.regex' => 'Use letters, digits and . _ - / (2–30 characters), e.g. ITM-00001.',
            '*.location_id.exists' => 'Choose an active branch from the list.',
            '*.minimum_stock.decimal' => 'Stock levels can have at most 3 decimal places.',
            '*.reorder_level.decimal' => 'Stock levels can have at most 3 decimal places.',
            '*.maximum_stock.decimal' => 'Stock levels can have at most 3 decimal places.',
        ];
    }

    /**
     * @param  array{inventory: array{code: string|null, tracking: string, has_expiry: bool, shelf_life_days: string|null}, aliases: list<array<string, mixed>>, locations: list<array<string, mixed>>}  $data
     * @return array<string, string>
     */
    private function inventoryAttributes(array $data): array
    {
        $names = ['inventory.code' => 'item code', 'inventory.tracking' => 'tracking', 'inventory.shelf_life_days' => 'shelf life'];

        foreach (array_keys($data['aliases']) as $i) {
            $names["aliases.$i.alias"] = 'alias '.($i + 1);
        }

        foreach (array_keys($data['locations']) as $i) {
            $n = $i + 1;
            $names["locations.$i.location_id"] = "branch $n";
            $names["locations.$i.minimum_stock"] = "minimum stock (row $n)";
            $names["locations.$i.reorder_level"] = "reorder level (row $n)";
            $names["locations.$i.maximum_stock"] = "maximum stock (row $n)";
        }

        return $names;
    }
}
