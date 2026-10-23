<?php

namespace App\Modules\Shared\Exports;

use App\Modules\Shared\Imports\ImportColumn;
use App\Modules\Shared\Imports\Importer;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** What each column means, the rules, and worked examples that are explicitly not imported. */
final class ImportTemplateInstructionsSheet implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    public function __construct(private readonly Importer $importer) {}

    public function title(): string
    {
        return 'Instructions';
    }

    /** @return list<list<string>> */
    public function array(): array
    {
        $rows = [[$this->importer->label()], [$this->importer->description()], [], ['Columns'], ['Column', 'Required?', 'What to enter', 'Example']];

        foreach ($this->importer->columns() as $column) {
            $rows[] = [$column->heading, $column->required ? 'Yes' : 'No', $column->description, $column->example];
        }

        $rows[] = [];
        $rows[] = ['Rules'];
        foreach ($this->importer->rules() as $rule) {
            $rows[] = ['• '.$rule];
        }

        if ($this->importer->examples() !== []) {
            $rows[] = [];
            $rows[] = ['Examples (copy the pattern to the Data sheet; these rows are not imported)'];
            $rows[] = array_map(fn (ImportColumn $c) => $c->heading, $this->importer->columns());

            foreach ($this->importer->examples() as $example) {
                $rows[] = array_map(fn (ImportColumn $c) => (string) ($example[$c->key] ?? ''), $this->importer->columns());
            }
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true, 'size' => 14]]];
    }
}
