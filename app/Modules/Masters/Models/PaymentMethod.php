<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Policies\PaymentMethodPolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

/**
 * A way of paying (CASH, CHEQUE, NEFT, UPI, …). Customers and suppliers list the ones they prefer, by code.
 *
 * @property string $name
 */
#[Fillable(['code', 'name', 'status', 'remarks'])]
#[UsePolicy(PaymentMethodPolicy::class)]
class PaymentMethod extends TenantModel {}
