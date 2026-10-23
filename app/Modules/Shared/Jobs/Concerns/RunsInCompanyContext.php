<?php

namespace App\Modules\Shared\Jobs\Concerns;

use App\Modules\Shared\Jobs\Middleware\InCompanyContext;

/**
 * Give every queued job that touches business data its tenant context:
 *
 *     class SyncIndiaMartLeads implements ShouldQueue
 *     {
 *         use Queueable, RunsInCompanyContext;
 *
 *         public function __construct(int $companyId) { $this->forCompany($companyId); }
 *     }
 *
 * The company (and optional branch) are plain integers on the job, so they survive serialisation.
 */
trait RunsInCompanyContext
{
    public ?int $companyId = null;

    public ?int $locationId = null;

    protected function forCompany(int $companyId, ?int $locationId = null): static
    {
        $this->companyId = $companyId;
        $this->locationId = $locationId;

        return $this;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new InCompanyContext];
    }
}
