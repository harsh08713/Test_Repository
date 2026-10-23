<?php

namespace App\Modules\Shared\Events;

use App\Modules\Shared\Contracts\ApprovableDocument;
use App\Modules\Shared\Models\Approval;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** A level is now waiting for its approvers (on submission, and again when the previous level approves). */
class ApprovalRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly ApprovableDocument&Model $document,
        public readonly Approval $approval,
    ) {}
}
