<?php

namespace App\Modules\Api\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Removes remembered idempotency keys whose time is up. (Expired device tokens are removed by `sanctum:prune-expired`.) */
class PruneApiData extends Command
{
    protected $signature = 'api:prune';

    protected $description = 'Delete expired API idempotency keys';

    public function handle(): int
    {
        $removed = DB::table('api_idempotency_keys')->where('expires_at', '<', now())->delete();

        $this->components->info("Removed {$removed} expired idempotency key(s).");

        return self::SUCCESS;
    }
}
