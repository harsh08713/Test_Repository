<?php

namespace App\Modules\Integrations\Console;

use App\Modules\Integrations\Jobs\PullProviderLeads;
use App\Modules\Integrations\Models\IntegrationConnection;
use App\Modules\Integrations\Providers\ProviderRegistry;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Console\Command;

/** Queues a pull for every enabled connection whose turn has come (the scheduler runs this every five minutes; each connection's own interval decides). */
class PullLeads extends Command
{
    protected $signature = 'integrations:pull {--connection= : Only this connection id, whether or not it is due}';

    protected $description = 'Pull new leads from the marketplaces that have a pull API';

    public function handle(ProviderRegistry $providers): int
    {
        $due = IntegrationConnection::withoutGlobalScopes(Tenancy::SCOPES)
            ->where('enabled', true)->whereIn('mode', ['pull', 'both'])
            ->when($this->option('connection') !== null, fn ($q) => $q->whereKey((int) $this->option('connection')))
            ->when($this->option('connection') === null, fn ($q) => $q->where(fn ($w) => $w->whereNull('next_pull_at')->orWhere('next_pull_at', '<=', now())))
            ->get()
            ->filter(fn (IntegrationConnection $c) => $providers->has($c->provider) && $providers->get($c->provider)->canPull());

        foreach ($due as $connection) {
            PullProviderLeads::dispatch((int) $connection->company_id, (int) $connection->getKey());
        }

        $this->components->info("Queued {$due->count()} pull(s).");

        return self::SUCCESS;
    }
}
