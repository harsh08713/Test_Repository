<?php

namespace App\Modules\Shared\Exports;

use App\Modules\Shared\Imports\ImportColumn;
use App\Modules\Shared\Imports\Importer;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** The sheet people fill in: bold, frozen headings and nothing else. */
final class ImportTemplateDataSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithStyles, WithTitle
{
    public function __construct(private readonly Importer $importer) {}

    public function title(): string
    {
        return 'Data';
    }

    /** @return list<string> */
    public function headings(): array
    {
        return array_map(fn (ImportColumn $c) => $c->heading, $this->importer->columns());
    }

    /** @return list<list<string>> */
    public function array(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function columnFormats(): array
    {
        $formats = [];

        foreach ($this->importer->columns() as $index => $column) {
            if ($column->text) {
                $formats[Coordinate::stringFromColumnIndex($index + 1)] = NumberFormat::FORMAT_TEXT;
            }
        }

        return $formats;
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E2E8F0']]]];
    }

    /** @return array<class-string, callable> */
    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $event->sheet->getDelegate()->freezePane('A2')];
    }
}
