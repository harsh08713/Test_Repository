<?php

namespace App\Modules\Shared\Events;

use App\Models\User;
use App\Modules\Shared\Contracts\ApprovableDocument;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class DocumentRejected implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly ApprovableDocument&Model $document,
        public readonly User $rejector,
        public readonly string $comment,
    ) {}
}
