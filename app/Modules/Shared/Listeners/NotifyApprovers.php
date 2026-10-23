<?php

namespace App\Modules\Shared\Listeners;

use App\Modules\Shared\Events\ApprovalRequested;
use App\Modules\Shared\Notifications\ApprovalRequestedNotification;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;

/** In-app + e-mail alert to everyone who can decide the level that just became pending. */
class NotifyApprovers
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(ApprovalRequested $event): void
    {
        // The submitter's session may not see the approvers' rows, so look them up as the system.
        $approvers = $this->tenant->withoutTenancy(fn () => $this->approvals->eligibleApprovers($event->approval));

        if ($approvers->isEmpty()) {
            return;
        }

        Notification::send($approvers, new ApprovalRequestedNotification(
            title: $event->document->approvalTitle(),
            url: $event->document->approvalUrl(),
            requestedBy: $event->approval->submitter->name ?? 'Someone',
            level: $event->approval->level,
        ));
    }
}
