<?php

namespace App\Modules\CRM\Http\Livewire\Concerns;

use App\Modules\CRM\Services\LeadService;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Services\ItemLookup;
use Illuminate\Support\Str;

/**
 * The rows of item lines a form edits — an inquiry's items, a deal's lines: add and remove a row, find an item and pick it.
 * What the screen holds is only what was typed: the service behind the form checks every row again whatever was sent, and
 * only approved, active items can be picked (ItemLookup).
 *
 * The form names the public array that holds its rows (`rowsProperty()`) after the path its service reports errors under
 * (`interests` for an inquiry, `items` for a deal), so a message lands on the field that caused it. A row that came from a
 * line of the lead carries `lead_interest_id`: it may be adjusted, but not removed, and a lead's item may not be swapped.
 */
trait EditsItemRows
{
    public const MAX_SEARCH_RESULTS = 8;

    /** The name of the public array this form keeps its rows in. */
    abstract protected function rowsProperty(): string;

    /** @return list<array<string, mixed>> */
    protected function rows(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->{$this->rowsProperty()};

        return $rows;
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    protected function setRows(array $rows): void
    {
        $this->{$this->rowsProperty()} = array_values($rows);
    }

    /** @return array<string, mixed> */
    protected function blankRow(): array
    {
        return [
            'key' => Str::random(6), 'id' => '', 'lead_interest_id' => '', 'locked_item' => false, 'item_id' => '', 'item_label' => '',
            'description' => '', 'unit_id' => '', 'quantity' => '', 'expected_rate' => '', 'search' => '',
        ];
    }

    public function addRow(): void
    {
        $rows = $this->rows();

        if (count($rows) < LeadService::MAX_INTERESTS) {
            $rows[] = $this->blankRow();
            $this->setRows($rows);
        }
    }

    public function removeRow(int $index): void
    {
        $rows = $this->rows();

        // a line the lead listed has to come across to its deal: it is adjusted, never dropped here
        if (! isset($rows[$index]) || ($rows[$index]['lead_interest_id'] ?? '') !== '') {
            return;
        }

        unset($rows[$index]);
        $this->setRows($rows);
    }

    /** Pick an item from the search results. Only approved, active items can be picked. */
    public function pickItem(int $index, int $id): void
    {
        $rows = $this->rows();

        if (! isset($rows[$index]) || ($rows[$index]['locked_item'] ?? false)) {
            return;
        }

        $item = app(ItemLookup::class)->usable($id);

        if ($item !== null) {
            $rows[$index] = [
                ...$rows[$index],
                'item_id' => (string) $item->id, 'item_label' => trim($item->code.' · '.$item->name), 'unit_id' => (string) $item->base_unit_id, 'search' => '',
            ];
            $this->setRows($rows);
        }
    }

    public function clearItem(int $index): void
    {
        $rows = $this->rows();

        if (isset($rows[$index]) && ! ($rows[$index]['locked_item'] ?? false)) {
            $rows[$index] = [...$rows[$index], 'item_id' => '', 'item_label' => '', 'unit_id' => '', 'search' => ''];
            $this->setRows($rows);
        }
    }

    /**
     * What the rows' view needs: the units each picked item is sold in, and what each open search found.
     *
     * @param  list<int>  $alreadyOnTheRecord  item ids that are on the saved record and so stay pickable even if switched off since
     * @return array{itemUnits: array<int, array<int, string>>, results: array<int, list<array{id: int, label: string}>>}
     */
    protected function rowHelp(array $alreadyOnTheRecord = []): array
    {
        $lookup = app(ItemLookup::class);
        $itemUnits = [];
        $results = [];

        foreach ($this->rows() as $i => $row) {
            $itemId = ctype_digit((string) ($row['item_id'] ?? '')) ? (int) $row['item_id'] : null;

            if ($itemId !== null) {
                $item = $lookup->usable($itemId) ?? (in_array($itemId, $alreadyOnTheRecord, true) ? Item::query()->with(['baseUnit', 'uoms.unit'])->find($itemId) : null);

                $units = [];
                if ($item !== null) {
                    $units[(int) $item->base_unit_id] = $item->baseUnit->name.' ('.$item->baseUnit->code.')';
                    foreach ($item->uoms as $uom) {
                        $units[(int) $uom->unit_id] = $uom->unit->name.' ('.$uom->unit->code.')';
                    }
                }
                $itemUnits[$i] = $units;

                continue;
            }

            $term = trim((string) ($row['search'] ?? ''));
            if ($term !== '') {
                $results[$i] = $lookup->search($term, self::MAX_SEARCH_RESULTS)->map(fn (Item $item) => ['id' => (int) $item->id, 'label' => trim($item->code.' · '.$item->name)])->all();
            }
        }

        return ['itemUnits' => $itemUnits, 'results' => $results];
    }
}
