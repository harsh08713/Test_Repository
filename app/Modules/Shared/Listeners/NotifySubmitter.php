<?php

namespace App\Modules\Shared\Listeners;

use App\Models\User;
use App\Modules\Shared\Events\DocumentApproved;
use App\Modules\Shared\Events\DocumentRejected;
use App\Modules\Shared\Models\Approval;
use App\Modules\Shared\Notifications\DocumentDecisionNotification;

/** Tells whoever submitted the document how it was decided. */
class NotifySubmitter
{
    public function handle(DocumentApproved|DocumentRejected $event): void
    {
        $document = $event->document;

        $submitterId = Approval::withoutGlobalScopes()
            ->where('approvable_type', $document->getMorphClass())
            ->where('approvable_id', $document->getKey())
            ->orderByDesc('cycle')
            ->value('submitted_by');

        $submitter = $submitterId ? User::query()->where('is_active', true)->find($submitterId) : null;

        if ($submitter === null) {
            return;
        }

        $approved = $event instanceof DocumentApproved;

        $submitter->notify(new DocumentDecisionNotification(
            title: $document->approvalTitle(),
            url: $document->approvalUrl(),
            approved: $approved,
            decidedBy: ($approved ? $event->approver : $event->rejector)->name,
            comment: $event->comment,
        ));
    }
}
