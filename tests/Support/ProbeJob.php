<?php

namespace Tests\Support;

use App\Modules\Shared\Jobs\Concerns\RunsInCompanyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Records what a queued job can see, to prove the company context is applied (or missing). */
class ProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable, RunsInCompanyContext;

    /** @var list<string> */
    public static array $seen = [];

    public function __construct(?int $companyId = null, ?int $locationId = null)
    {
        if ($companyId !== null) {
            $this->forCompany($companyId, $locationId);
        }
    }

    public function handle(): void
    {
        self::$seen = TestDocument::query()->orderBy('name')->pluck('name')->all();
    }
}
