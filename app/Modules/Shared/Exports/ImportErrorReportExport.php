<?php

namespace App\Modules\Shared\Exports;

use App\Modules\Shared\Imports\ImportColumn;
use App\Modules\Shared\Imports\Importer;
use App\Modules\Shared\Models\ImportBatch;
use App\Modules\Shared\Models\ImportBatchError;
use Generator;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * Every rejected row with the reasons and the cells exactly as uploaded, so it can be corrected and re-uploaded.
 *
 * Everything is written as plain text: a cell such as `=HYPERLINK(...)` in the uploaded file must arrive in this
 * report as inert text, never as a live formula (spreadsheet formula injection).
 */
final class ImportErrorReportExport extends StringValueBinder implements FromGenerator, ShouldAutoSize, WithCustomValueBinder, WithHeadings
{
    public function __construct(private readonly ImportBatch $batch, private readonly Importer $importer) {}

    /** @return list<string> */
    public function headings(): array
    {
        return ['Row', 'Problem', ...array_map(fn (ImportColumn $c) => $c->heading, $this->importer->columns())];
    }

    public function generator(): Generator
    {
        // Rows are recorded in spreadsheet order, so id order is row order.
        foreach (ImportBatchError::query()->where('import_batch_id', $this->batch->id)->lazyById(500) as $error) {
            yield [
                (string) $error->row_number,
                implode(' ', $error->messages),
                ...array_map(fn (ImportColumn $c) => (string) ($error->raw[$c->key] ?? ''), $this->importer->columns()),
            ];
        }
    }
}
