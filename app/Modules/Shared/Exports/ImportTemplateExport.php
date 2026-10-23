<?php

namespace App\Modules\Shared\Exports;

use App\Modules\Shared\Imports\Importer;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The downloadable template: a "Data" sheet holding only the headings (nothing to delete before pasting data)
 * and an "Instructions" sheet that explains every column, the rules and a few worked examples.
 */
final class ImportTemplateExport implements Export, WithMultipleSheets
{
    public function __construct(private readonly Importer $importer) {}

    /** @return list<Export> */
    public function sheets(): array
    {
        return [new ImportTemplateDataSheet($this->importer), new ImportTemplateInstructionsSheet($this->importer)];
    }
}
