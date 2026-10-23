<?php

namespace App\Modules\Reports\Exports;

use App\Modules\Reports\Support\ReportColumn;
use Generator;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * A report as a worksheet. Numbers stay numbers; everything else is written as plain text, so a name such as `=HYPERLINK(...)` typed into a
 * lead arrives in the file as inert text, never as a live formula (spreadsheet formula injection).
 */
final class ReportSheet extends StringValueBinder implements FromGenerator, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithTitle
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function __construct(private readonly string $title, private readonly array $columns, private readonly iterable $rows)
    {
        $this->setNumericConversion(true);
    }

    public function title(): string
    {
        return mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $this->title) ?? 'Report', 0, 31);
    }

    /** @return list<string> */
    public function headings(): array
    {
        return array_map(fn (ReportColumn $c) => $c->label, $this->columns);
    }

    public function generator(): Generator
    {
        foreach ($this->rows as $row) {
            yield array_map(fn (ReportColumn $c) => $c->cell($row[$c->key] ?? null), $this->columns);
        }
    }
}
