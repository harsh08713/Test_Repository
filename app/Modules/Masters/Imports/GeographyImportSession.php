<?php

namespace App\Modules\Masters\Imports;

use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\PinCode;
use App\Modules\Masters\Models\State;
use App\Modules\Shared\Imports\ImportSession;
use App\Modules\Shared\Imports\RowOutcome;
use App\Modules\Shared\Rules\IndianPinCode;
use Normalizer;

/**
 * One pass over a geography file for one company.
 *
 * The existing districts and blocks are loaded once, the PIN rows a chunk mentions are loaded per chunk, and every
 * row then resolves against those in-memory maps. A dry run adds "virtual" records (negative ids) to the maps, so
 * later rows of the same file see what earlier rows would have created, exactly as they will in the real apply.
 *
 * A row is planned completely before anything is written, and the maps are only updated once its writes have
 * succeeded, so a row that fails halfway leaves both the database and the maps as they were.
 */
final class GeographyImportSession implements ImportSession
{
    /** @var array<string, array{id: int, name: string}> */
    private array $statesByName = [];

    /** @var array<string, array{id: int, name: string}> */
    private array $statesByCode = [];

    /** @var array<string, array{id: int, name: string}> */
    private array $statesByGst = [];

    /** @var array<string, array{id: int, status: string, trashed: bool}> keyed "stateId|name" */
    private array $districts = [];

    /** @var array<string, array{id: int, status: string, trashed: bool}> keyed "districtId|name" */
    private array $blocks = [];

    /** @var array<string, array{id: int, block_id: int|null, status: string, trashed: bool, row: int|null}> keyed "pin|districtId|locality" */
    private array $pins = [];

    /** @var array<string, true> */
    private array $loadedPins = [];

    private int $virtualId = 0;

    /** @var array<string, int> */
    private array $counters = [
        'districts_created' => 0, 'blocks_created' => 0, 'pins_created' => 0, 'pins_updated' => 0, 'records_restored' => 0, 'repeated_rows' => 0,
    ];

    public function __construct(private readonly int $companyId)
    {
        foreach (State::query()->where('company_id', $companyId)->get(['id', 'code', 'gst_code', 'name']) as $state) {
            $entry = ['id' => $state->id, 'name' => $state->name];
            $this->statesByName[$this->identity($state->name)] = $entry;
            $this->statesByCode[strtoupper($state->code)] = $entry;
            $this->statesByGst[$state->gst_code] = $entry;
        }

        foreach (District::withTrashed()->where('company_id', $companyId)->get(['id', 'state_id', 'name', 'status', 'deleted_at']) as $district) {
            $this->districts[$district->state_id.'|'.$this->identity($district->name)] = ['id' => $district->id, 'status' => $district->status, 'trashed' => $district->trashed()];
        }

        foreach (Block::withTrashed()->where('company_id', $companyId)->get(['id', 'district_id', 'name', 'status', 'deleted_at']) as $block) {
            $this->blocks[$block->district_id.'|'.$this->identity($block->name)] = ['id' => $block->id, 'status' => $block->status, 'trashed' => $block->trashed()];
        }
    }

    public function prepareChunk(array $rows): void
    {
        $wanted = [];
        foreach ($rows as $cells) {
            $pin = $this->pin($cells['pin_code'] ?? '');
            if ($pin !== '' && ! isset($this->loadedPins[$pin])) {
                $wanted[$pin] = true;
            }
        }

        if ($wanted === []) {
            return;
        }

        foreach (PinCode::withTrashed()->where('company_id', $this->companyId)->whereIn('pin_code', array_keys($wanted))
            ->get(['id', 'pin_code', 'district_id', 'block_id', 'area', 'status', 'deleted_at']) as $row) {
            $this->pins[$row->pin_code.'|'.$row->district_id.'|'.$this->identity($row->area)] = [
                'id' => $row->id, 'block_id' => $row->block_id, 'status' => $row->status, 'trashed' => $row->trashed(), 'row' => null,
            ];
        }

        foreach (array_keys($wanted) as $pin) {
            $this->loadedPins[$pin] = true;
        }
    }

    public function process(int $rowNumber, array $cells, bool $apply, ?int $userId): RowOutcome
    {
        $stateText = $cells['state'] ?? '';
        $districtName = $cells['district'] ?? '';
        $blockName = $cells['block'] ?? '';
        $pin = $this->pin($cells['pin_code'] ?? '');
        $locality = $cells['locality'] ?? '';

        // ---- 1. validate the row on its own ------------------------------------------------------------------
        $errors = [];
        $state = null;

        if ($stateText === '') {
            $errors[] = 'State is required.';
        } elseif (($state = $this->findState($stateText)) === null) {
            $errors[] = "State “{$stateText}” is not one of this company's states. Use the names under Masters → Geography → States.";
        }

        foreach ([['District', $districtName, 255], ['Block', $blockName, 255], ['Locality', $locality, 150]] as [$label, $value, $max]) {
            if (mb_strlen($value) > $max) {
                $errors[] = "{$label} must be {$max} characters or fewer.";
            }
        }

        if ($districtName === '') {
            $errors[] = 'District is required.';
        }

        if ($pin !== '' && ! IndianPinCode::isValid($pin)) {
            $errors[] = "PIN Code “{$pin}” is not a valid 6-digit PIN code (it cannot start with 0).";
        }

        if ($blockName === '' && $pin === '') {
            $errors[] = 'Give a Block, a PIN Code, or both.';
        }

        if ($locality !== '' && $pin === '') {
            $errors[] = 'A Locality needs a PIN Code.';
        }

        if ($errors !== []) {
            return RowOutcome::error($errors);
        }

        // ---- 2. plan against what exists (database + earlier rows of this file) ------------------------------
        $stateId = $state['id'];
        $districtKey = $stateId.'|'.$this->identity($districtName);
        $district = $this->districts[$districtKey] ?? null;

        if ($district !== null && $district['status'] !== 'active') {
            return RowOutcome::error("District “{$districtName}” is inactive. Reactivate it under Masters → Geography → Districts, or remove the row.");
        }

        $blockKey = null;
        $block = null;
        if ($blockName !== '') {
            $blockKey = $district !== null ? $district['id'].'|'.$this->identity($blockName) : null;
            $block = $blockKey !== null ? ($this->blocks[$blockKey] ?? null) : null;

            if ($block !== null && $block['status'] !== 'active') {
                return RowOutcome::error("Block “{$blockName}” is inactive. Reactivate it under Masters → Geography → Blocks, or remove the row.");
            }
        }

        $pinRow = null;
        if ($pin !== '') {
            $pinKey = $district !== null ? $pin.'|'.$district['id'].'|'.$this->identity($locality) : null;
            $pinRow = $pinKey !== null ? ($this->pins[$pinKey] ?? null) : null;

            if ($pinRow !== null && $pinRow['status'] !== 'active') {
                return RowOutcome::error("PIN code {$pin}".($locality !== '' ? " ({$locality})" : '').' is inactive. Reactivate it under Masters → Geography → PIN codes, or remove the row.');
            }

            // The same PIN/district/locality given twice in one file must agree about the block (a block that does
            // not exist yet cannot be the one the earlier row used).
            if ($pinRow !== null && $pinRow['row'] !== null && $blockName !== '' && $pinRow['block_id'] !== null
                && ($block === null || $block['id'] !== $pinRow['block_id'])) {
                return RowOutcome::error("PIN code {$pin}".($locality !== '' ? " ({$locality})" : '')." already appears on row {$pinRow['row']} with a different block, so this row conflicts with it.");
            }
        }

        // ---- 3. write (apply) or just describe (check) -------------------------------------------------------
        $parts = [];
        $created = false;
        $updated = false;
        $counts = array_fill_keys(array_keys($this->counters), 0);
        $mapOps = [];

        $newId = fn (): int => --$this->virtualId;

        // district
        if ($district === null) {
            $districtId = $apply ? $this->create(new District(['state_id' => $stateId, 'name' => $districtName]), $userId) : $newId();
            $parts[] = "New district {$districtName}";
            $created = true;
            $counts['districts_created']++;
        } else {
            $districtId = $district['id'];

            if ($district['trashed']) {
                if ($apply) {
                    $this->restore(District::class, $districtId, $userId);
                }
                $parts[] = "Restores district {$districtName}";
                $updated = true;
                $counts['records_restored']++;
            }
        }
        $mapOps[] = fn () => $this->districts[$districtKey] = ['id' => $districtId, 'status' => 'active', 'trashed' => false];

        // block
        $blockId = null;
        if ($blockName !== '') {
            $blockKey = $districtId.'|'.$this->identity($blockName);

            if ($block === null) {
                $blockId = $apply ? $this->create(new Block(['district_id' => $districtId, 'name' => $blockName]), $userId) : $newId();
                $parts[] = "New block {$blockName}";
                $created = true;
                $counts['blocks_created']++;
            } else {
                $blockId = $block['id'];

                if ($block['trashed']) {
                    if ($apply) {
                        $this->restore(Block::class, $blockId, $userId);
                    }
                    $parts[] = "Restores block {$blockName}";
                    $updated = true;
                    $counts['records_restored']++;
                }
            }
            $mapOps[] = fn () => $this->blocks[$blockKey] = ['id' => $blockId, 'status' => 'active', 'trashed' => false];
        }

        // PIN row
        if ($pin !== '') {
            $pinKey = $pin.'|'.$districtId.'|'.$this->identity($locality);
            $label = "PIN {$pin}".($locality !== '' ? " ({$locality})" : '');

            if ($pinRow === null) {
                $pinId = $apply
                    ? $this->create(new PinCode(['pin_code' => $pin, 'state_id' => $stateId, 'district_id' => $districtId, 'block_id' => $blockId, 'area' => $locality]), $userId)
                    : $newId();
                $parts[] = "New {$label}".($blockName !== '' ? " in block {$blockName}" : '');
                $created = true;
                $counts['pins_created']++;
                $mapOps[] = fn () => $this->pins[$pinKey] = ['id' => $pinId, 'block_id' => $blockId, 'status' => 'active', 'trashed' => false, 'row' => $rowNumber];
            } else {
                $pinId = $pinRow['id'];
                $touchedEarlier = $pinRow['row'] !== null;
                // An empty block cell never erases; a block that differs from the recorded one replaces it.
                $newBlock = $blockId !== null && $blockId !== $pinRow['block_id'] ? $blockId : $pinRow['block_id'];
                $blockChanged = $newBlock !== $pinRow['block_id'];

                if ($pinRow['trashed'] || $blockChanged) {
                    if ($apply) {
                        $this->updatePin($pinId, $newBlock, $pinRow['trashed'], $userId);
                    }
                    $parts[] = ($pinRow['trashed'] ? "Restores {$label}" : "Updates {$label}").($blockChanged ? ' with its block' : '');
                    $updated = true;
                    $counts['pins_updated']++;

                    if ($pinRow['trashed']) {
                        $counts['records_restored']++;
                    }
                } elseif ($touchedEarlier) {
                    $counts['repeated_rows']++;
                    $parts[] = "{$label} repeats row {$pinRow['row']}";
                }

                $mapOps[] = fn () => $this->pins[$pinKey] = ['id' => $pinId, 'block_id' => $newBlock, 'status' => 'active', 'trashed' => false, 'row' => $pinRow['row'] ?? $rowNumber];
            }

            // the PIN is now known in this file, whichever district it landed in
            $mapOps[] = fn () => $this->loadedPins[$pin] = true;
        }

        // Only now that every write succeeded do the maps (and counters) learn about this row.
        foreach ($mapOps as $op) {
            $op();
        }
        foreach ($counts as $name => $n) {
            $this->counters[$name] += $n;
        }

        $detail = $parts === [] ? 'Already up to date' : implode(' · ', $parts);

        return match (true) {
            $created => RowOutcome::created($detail),
            $updated => RowOutcome::updated($detail),
            default => RowOutcome::unchanged($detail),
        };
    }

    public function counters(): array
    {
        return $this->counters;
    }

    // ---- writes ------------------------------------------------------------------------------------------------

    private function create(District|Block|PinCode $model, ?int $userId): int
    {
        $model->forceFill(['company_id' => $this->companyId, 'created_by' => $userId, 'updated_by' => $userId])->save();

        return (int) $model->getKey();
    }

    /** @param  class-string<District|Block>  $class */
    private function restore(string $class, int $id, ?int $userId): void
    {
        $model = $class::withTrashed()->where('company_id', $this->companyId)->findOrFail($id);
        $model->forceFill(['updated_by' => $userId])->restore();
    }

    private function updatePin(int $id, ?int $blockId, bool $trashed, ?int $userId): void
    {
        $model = PinCode::withTrashed()->where('company_id', $this->companyId)->findOrFail($id);
        $model->forceFill(['block_id' => $blockId, 'updated_by' => $userId]);

        if ($trashed) {
            $model->restore();   // restore() saves the pending attributes too
        } else {
            $model->save();
        }
    }

    // ---- lookups -----------------------------------------------------------------------------------------------

    /** @return array{id: int, name: string}|null */
    private function findState(string $value): ?array
    {
        $byName = $this->statesByName[$this->identity($value)] ?? null;

        if ($byName !== null) {
            return $byName;
        }

        if (ctype_digit($value) && strlen($value) <= 2) {
            return $this->statesByGst[str_pad($value, 2, '0', STR_PAD_LEFT)] ?? null;
        }

        return $this->statesByCode[strtoupper($value)] ?? null;
    }

    private function pin(string $value): string
    {
        return (string) preg_replace('/\s+/', '', $value);
    }

    /**
     * What counts as "the same name": ignoring capitals, accents and extra spaces, which is what the database's
     * unique indexes do too. Keeps a file from asking for two records the database would call one.
     */
    private function identity(string $value): string
    {
        $value = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));

        if (class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);

            if ($decomposed !== false) {
                $value = (string) preg_replace('/\p{Mn}/u', '', $decomposed);
            }
        }

        return $value;
    }
}
