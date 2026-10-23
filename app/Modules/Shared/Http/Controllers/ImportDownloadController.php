<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Modules\Shared\Exports\ImportErrorReportExport;
use App\Modules\Shared\Exports\ImportTemplateExport;
use App\Modules\Shared\Imports\ImporterRegistry;
use App\Modules\Shared\Models\ImportBatch;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Template and error-report downloads for imports. Each is authorised against the importer's own permission. */
class ImportDownloadController
{
    public function __construct(private readonly ImporterRegistry $importers) {}

    public function template(string $importer): BinaryFileResponse
    {
        $importer = $this->importers->get($importer);

        Gate::authorize($importer->permission());

        return Excel::download(new ImportTemplateExport($importer), Str::slug($importer->key()).'-import-template.xlsx');
    }

    public function errors(ImportBatch $batch): BinaryFileResponse
    {
        Gate::authorize('view', $batch);

        $importer = $this->importers->get($batch->importer);

        return Excel::download(new ImportErrorReportExport($batch, $importer), Str::slug($importer->key()).'-import-errors-'.$batch->id.'.xlsx');
    }
}
