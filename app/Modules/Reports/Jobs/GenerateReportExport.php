<?php

namespace App\Modules\Reports\Jobs;

use App\Modules\Reports\Models\ReportExport;
use App\Modules\Reports\Services\ReportExporter;
use App\Modules\Shared\Jobs\Concerns\RunsInCompanyContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Makes one report export's file, inside its company and as the person who asked. Safe to deliver twice: only a queued export starts. */
class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, Queueable, RunsInCompanyContext;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $exportId, int $companyId)
    {
        $this->forCompany($companyId);
    }

    public function handle(ReportExporter $exporter): void
    {
        $export = ReportExport::query()->find($this->exportId);

        if ($export !== null) {
            $exporter->generate($export);
        }
    }

    /** The worker timed out or died: don't leave the export looking busy forever. */
    public function failed(Throwable $exception): void
    {
        $export = ReportExport::withoutGlobalScopes()->whereKey($this->exportId)->where('company_id', $this->companyId)->first();

        if ($export !== null && in_array($export->status, [ReportExport::QUEUED, ReportExport::RUNNING], true)) {
            app(ReportExporter::class)->fail($export, $exception);
        }
    }
}
