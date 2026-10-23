<?php

namespace App\Modules\Procurement\Console;

use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Services\GrnPoster;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/** The retry for an approved goods receipt whose stock could not be posted at the time; the poster posts each receipt once however often this runs. */
class PostGrnsCommand extends Command
{
    protected $signature = 'procurement:post-grns';

    protected $description = 'Post the stock of approved goods receipts that have not been posted yet';

    public function handle(GrnPoster $poster): int
    {
        $done = 0;
        $failed = 0;

        $ids = Grn::withoutGlobalScopes(Tenancy::SCOPES)->where('approval_status', 'approved')->whereNull('posted_at')->orderBy('id')->limit(200)->pluck('id');

        foreach ($ids as $id) {
            try {
                $poster->post((int) $id) && $done++;
            } catch (Throwable) {
                $failed++;   // the reason is on the receipt
            }
        }

        $this->info("Receipts posted: {$done}");
        $failed > 0 && $this->warn("Receipts that still could not be posted: {$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
