<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Policies\BrandPolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

/**
 * A brand of the company's products (Loss Intelligence: profitability by brand).
 *
 * @property string $name
 * @property string|null $description
 */
#[Fillable(['code', 'name', 'description', 'status', 'remarks'])]
#[UsePolicy(BrandPolicy::class)]
class Brand extends TenantModel {}
