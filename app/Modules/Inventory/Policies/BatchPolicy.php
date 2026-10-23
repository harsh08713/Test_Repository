<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

/** Batches are read with stock; they are made by receipts, never typed in. */
class BatchPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'stock';
    }
}
