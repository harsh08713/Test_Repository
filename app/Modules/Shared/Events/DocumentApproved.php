<?php

namespace App\Modules\Shared\Events;

use App\Models\User;
use App\Modules\Shared\Contracts\ApprovableDocument;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once, after the commit, when the final approval level approves a document.
 * Downstream modules listen for this (e.g. an approved Sales Order generates its Sales Invoice).
 */
class DocumentApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly ApprovableDocument&Model $document,
        public readonly User $approver,
        public readonly ?string $comment = null,
    ) {}
}
