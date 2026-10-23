<?php

namespace App\Modules\Shared\Jobs;

use App\Modules\Shared\Imports\ImportRunner;
use App\Modules\Shared\Jobs\Concerns\RunsInCompanyContext;
use App\Modules\Shared\Models\ImportBatch;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Checks or applies one uploaded spreadsheet, inside the company it belongs to. `phase` is "check" (a dry run)
 * or "apply". Safe to deliver twice: the runner only starts a phase from the exact state that allows it.
 */
class ProcessImportBatch implements ShouldQueue
{
    use Dispatchable, Queueable, RunsInCompanyContext;

    /** A large file takes a while; a retry would be refused anyway (the batch has already left its starting state). */
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $batchId, int $companyId, public string $phase)
    {
        $this->forCompany($companyId);
    }

    public function handle(ImportRunner $runner): void
    {
        $batch = ImportBatch::query()->find($this->batchId);

        if ($batch !== null) {
            $runner->process($batch, $this->phase);
        }
    }

    /** The worker timed out or died: don't leave the batch looking busy forever. */
    public function failed(Throwable $exception): void
    {
        $batch = ImportBatch::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($this->batchId)->where('company_id', $this->companyId)->first();

        if ($batch !== null && $batch->status->isBusy()) {
            app(ImportRunner::class)->recordFailure($batch, $this->phase, 'The import did not finish (the background worker stopped). Nothing from the failed step was half-saved; you can retry it.');
        }
    }
}
