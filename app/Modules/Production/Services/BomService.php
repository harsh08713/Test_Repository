<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomItem;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes and changes bills of materials — the one door for their data.
 *
 * A bill is made for an **approved, active item** from **approved, active components** (ItemLookup), all in base units: `quantity` of each component makes `output_quantity` of the
 * finished item, and `scrap_percent` is the wastage to allow on top. A bill may not contain its own item, directly or through another bill (a loop would never finish exploding).
 * A bill that is waiting for approval or approved never changes (the model refuses): a change is a new bill, and the old one is made inactive.
 *
 * Input: ['code', 'item_id', 'output_quantity', 'remarks', 'items' => [['item_id', 'quantity', 'scrap_percent'], …]]
 */
final class BomService
{
    public const MAX_LINES = 60;

    /** How many levels of sub-assemblies are followed when exploding or checking for a loop. */
    public const MAX_DEPTH = 8;

    public function __construct(private readonly TenantContext $tenant, private readonly ItemLookup $items) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input, User $actor): Bom
    {
        $data = $this->validated($input, null);

        return DB::transaction(function () use ($data, $actor) {
            $bom = new Bom;
            $bom->forceFill(['company_id' => (int) $this->tenant->activeCompanyId(), 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey()] + $data['header']);
            $bom->save();
            $this->writeLines($bom, $data['lines']);

            activity('boms')->causedBy($actor)->performedOn($bom)->event('created')->log('created');

            return $bom->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function update(Bom $bom, array $input, User $actor): Bom
    {
        return DB::transaction(function () use ($bom, $input, $actor) {
            $fresh = Bom::query()->lockForUpdate()->findOrFail($bom->getKey());

            if (! $fresh->isEditableByWorkflow()) {
                throw new FieldRuleException('A bill of materials that is waiting for approval or approved cannot be changed: make a new one.');
            }

            $data = $this->validated($input, $fresh);

            $fresh->components()->get()->each->forceDelete();   // outright: the old lines must free their unique keys (line number, component)
            $fresh->forceFill(['updated_by' => $actor->getKey()] + $data['header'])->save();
            $this->writeLines($fresh, $data['lines']);

            activity('boms')->causedBy($actor)->performedOn($fresh)->event('updated')->log('updated');

            return $fresh->refresh();
        });
    }

    /** Make an approved bill inactive (no new orders) or active again. Orders already made keep what they copied. */
    public function setStatus(Bom $bom, bool $active, User $actor): Bom
    {
        $fresh = Bom::query()->lockForUpdate()->findOrFail($bom->getKey());

        if ($fresh->isPendingApproval()) {
            throw new FieldRuleException('This bill is waiting for approval: wait for the decision first.');
        }

        $fresh->forceFill(['status' => $active ? 'active' : 'inactive', 'updated_by' => $actor->getKey()])->save();
        activity('boms')->causedBy($actor)->performedOn($fresh)->event($active ? 'activated' : 'deactivated')->log($active ? 'activated' : 'deactivated');

        return $fresh->refresh();
    }

    /**
     * Everything the bill needs, down to raw materials: a component that has a bill of its own (the latest approved, active one) is replaced by what it takes to make it.
     *
     * @param  numeric-string|null  $quantity  how much of the finished item to make (default: the bill's own output)
     * @return array<int, array{item: Item, quantity: string}> item id => the leaf item and the quantity needed (base units)
     */
    public function explode(Bom $bom, ?string $quantity = null): array
    {
        $out = [];
        $this->expand($bom, $quantity ?? (string) $bom->output_quantity, $out, [(int) $bom->item_id], 0);

        return $out;
    }

    /** Why a bill cannot be sent for approval right now, or null. */
    public function problemBeforeSubmit(Bom $bom): ?string
    {
        if ($this->items->usable((int) $bom->item_id) === null) {
            return 'The finished item is no longer an approved, active item.';
        }

        foreach ($bom->components()->get() as $line) {
            if ($this->items->usable((int) $line->item_id) === null) {
                return "Component {$line->line_no} is no longer an approved, active item.";
            }
        }

        return null;
    }

    // ---- internals ------------------------------------------------------------------------------------------------

    /**
     * @param  array<int, array{item: Item, quantity: string}>  $out
     * @param  list<int>  $path  the items above this level (a loop is cut here, not followed)
     */
    private function expand(Bom $bom, string $quantity, array &$out, array $path, int $depth): void
    {
        $factor = bcdiv($quantity, (string) $bom->output_quantity, 12);

        foreach ($bom->components()->with('item')->get() as $line) {
            $needed = bcadd(bcmul(bcmul((string) $line->quantity, bcadd('1', bcdiv((string) $line->scrap_percent, '100', 8), 8), 8), $factor, 8), '0.0005', 3);
            $sub = $depth < self::MAX_DEPTH && ! in_array((int) $line->item_id, $path, true) ? $this->usableFor((int) $line->item_id) : null;

            if ($sub !== null) {
                $this->expand($sub, $needed, $out, [...$path, (int) $line->item_id], $depth + 1);

                continue;
            }

            if ($line->item === null) {
                continue;
            }

            $id = (int) $line->item_id;
            $out[$id] = ['item' => $line->item, 'quantity' => bcadd($out[$id]['quantity'] ?? '0', $needed, 3)];
        }
    }

    /** The latest approved, active bill that makes this item, if any. */
    private function usableFor(int $itemId): ?Bom
    {
        return Bom::query()->where('item_id', $itemId)->usable()->orderByDesc('id')->first();
    }

    /**
     * Can $target be reached from $start through the components of any bill (a draft counts: it may be approved tomorrow)?
     *
     * @param  array<int, bool>  $seen
     */
    private function reaches(int $start, int $target, int $depth, array $seen = []): bool
    {
        if ($start === $target) {
            return true;
        }

        if ($depth >= self::MAX_DEPTH || isset($seen[$start])) {
            return false;
        }

        $seen[$start] = true;

        foreach (BomItem::query()->whereIn('bom_id', Bom::query()->where('item_id', $start)->select('id'))->pluck('item_id')->unique() as $component) {
            if ($this->reaches((int) $component, $target, $depth + 1, $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{header: array<string, mixed>, lines: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    private function validated(array $input, ?Bom $editing): array
    {
        $errors = [];
        $companyId = (int) $this->tenant->activeCompanyId();

        $code = strtoupper(trim((string) ($input['code'] ?? '')));

        if (preg_match('/^[A-Z0-9][A-Z0-9\-_\/]{1,29}$/', $code) !== 1) {
            $errors['code'] = 'Give the bill a code of 2 to 30 letters, digits, dashes or slashes (for example BOM-BOLT-01).';
        } elseif (Bom::query()->where('code', $code)->when($editing !== null, fn ($q) => $q->whereKeyNot($editing?->getKey()))->exists()) {
            $errors['code'] = 'There is already a bill with that code.';
        }

        $finished = is_numeric($input['item_id'] ?? null) ? $this->items->usable((int) $input['item_id']) : null;

        if ($finished === null) {
            $errors['item_id'] = 'Choose an approved, active item to make.';
        }

        $output = trim((string) ($input['output_quantity'] ?? ''));

        if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $output) !== 1 || bccomp($output, '0', 3) <= 0) {
            $errors['output_quantity'] = 'Enter how much the bill makes: greater than zero, up to 3 decimals.';
        }

        $remarks = trim((string) ($input['remarks'] ?? ''));

        if (mb_strlen($remarks) > 1000) {
            $errors['remarks'] = 'Remarks can be at most 1000 characters.';
        }

        $rows = array_values((array) ($input['items'] ?? []));
        $lines = [];
        $seen = [];

        if ($rows === []) {
            $errors['items'] = 'Add at least one component.';
        } elseif (count($rows) > self::MAX_LINES) {
            $errors['items'] = 'A bill can have at most '.self::MAX_LINES.' components.';
        }

        foreach (array_slice($rows, 0, self::MAX_LINES) as $i => $row) {
            $row = (array) $row;
            $key = "items.$i";
            $item = is_numeric($row['item_id'] ?? null) ? $this->items->usable((int) $row['item_id']) : null;

            if ($item === null) {
                $errors["$key.item_id"] = 'Choose an approved, active item.';

                continue;
            }

            if ($finished !== null && $item->getKey() === $finished->getKey()) {
                $errors["$key.item_id"] = 'An item cannot be a component of itself.';

                continue;
            }

            if (isset($seen[$item->getKey()])) {
                $errors["$key.item_id"] = 'This component is already on the bill: add its quantity to the first line.';

                continue;
            }

            $seen[$item->getKey()] = true;
            $quantity = trim((string) ($row['quantity'] ?? ''));
            $scrap = trim((string) ($row['scrap_percent'] ?? '')) === '' ? '0' : trim((string) $row['scrap_percent']);

            if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $quantity) !== 1 || bccomp($quantity, '0', 3) <= 0) {
                $errors["$key.quantity"] = 'Enter a quantity greater than zero, with up to 3 decimals.';
            }

            if (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $scrap) !== 1 || bccomp($scrap, '50', 2) > 0) {
                $errors["$key.scrap_percent"] = 'Wastage must be from 0 to 50 percent.';
            }

            if (isset($errors["$key.quantity"]) || isset($errors["$key.scrap_percent"])) {
                continue;
            }

            if ($finished !== null && $this->reaches((int) $item->getKey(), (int) $finished->getKey(), 0)) {
                $errors["$key.item_id"] = "{$item->name} is itself made from {$finished->name}, so this bill would go round in a circle.";

                continue;
            }

            $lines[] = ['item_id' => $item->getKey(), 'quantity' => bcadd($quantity, '0', 3), 'scrap_percent' => bcadd($scrap, '0', 2)];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'header' => ['code' => $code, 'item_id' => $finished->getKey(), 'output_quantity' => bcadd($output, '0', 3), 'remarks' => $remarks === '' ? null : $remarks],
            'lines' => $lines,
        ];
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function writeLines(Bom $bom, array $lines): void
    {
        foreach ($lines as $n => $line) {
            $row = new BomItem;
            $row->forceFill(['company_id' => $bom->company_id, 'bom_id' => $bom->getKey(), 'line_no' => $n + 1] + $line)->save();
        }
    }
}
