<?php

namespace App\Modules\Masters\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

class PaymentMethodPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'payment_method';
    }
}
