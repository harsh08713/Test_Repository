<?php

namespace App\Modules\Integrations\Jobs;

use App\Modules\Integrations\Models\IntegrationConnection;
use App\Modules\Integrations\Services\ProviderSync;
use App\Modules\Shared\Jobs\Concerns\RunsInCompanyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One pull of one provider connection, inside its company. One at a time per connection (a second is dropped, not queued behind),
 * and no retries of its own: ProviderSync records a failure on the connection and sets when to try next, which is the retry.
 */
class PullProviderLeads implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInCompanyContext;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(int $companyId, public readonly int $connectionId)
    {
        $this->forCompany($companyId);
    }

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(ProviderSync $sync): void
    {
        $connection = IntegrationConnection::query()->find($this->connectionId);

        if ($connection !== null) {
            $sync->pull($connection);
        }
    }
}
