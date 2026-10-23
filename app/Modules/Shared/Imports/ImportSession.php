<?php

namespace App\Modules\Shared\Imports;

/** State for one pass over one file: lookups loaded once, and what earlier rows of the same file have already done. */
interface ImportSession
{
    /**
     * Called before each chunk so the importer can fetch what the chunk needs in a few queries, not one per row.
     *
     * @param  array<int, array<string, string>>  $rows  spreadsheet row number => cells keyed by column key
     */
    public function prepareChunk(array $rows): void;

    /**
     * Validate one row and, when $apply is true, write it. With $apply false nothing is written, but the outcome
     * (and the effect on later rows of the same file) is exactly what an apply would produce.
     *
     * @param  array<string, string>  $cells  trimmed, whitespace-collapsed text; empty string for a blank cell
     */
    public function process(int $rowNumber, array $cells, bool $apply, ?int $userId): RowOutcome;

    /**
     * Importer-specific totals so far, e.g. ['districts_created' => 3].
     *
     * @return array<string, int>
     */
    public function counters(): array;
}
