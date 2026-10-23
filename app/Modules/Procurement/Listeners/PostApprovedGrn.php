<?php

namespace App\Modules\Procurement\Listeners;

use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Services\GrnPoster;
use App\Modules\Shared\Events\DocumentApproved;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When a goods receipt is approved (DocumentApproved, fired after the approval has been committed): put its accepted goods into stock.
 *
 * A failure cannot undo the approval and is not lost: it is written on the receipt (`post_error`) and retried by `procurement:post-grns`; the
 * poster is safe to ask any number of times, so a replayed event posts nothing twice.
 */
class PostApprovedGrn
{
    public function __construct(private readonly GrnPoster $poster) {}

    public function handle(DocumentApproved $event): void
    {
        if (! $event->document instanceof Grn) {
            return;
        }

        try {
            $this->poster->post($event->document);
        } catch (Throwable $e) {
            Log::error('The stock for an approved goods receipt could not be posted; it will be retried.', ['grn' => $event->document->getKey(), 'error' => $e->getMessage()]);
        }
    }
}
