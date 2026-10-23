<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Policies\DepartmentPolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

/**
 * @property string $name
 */
#[Fillable(['code', 'name', 'status', 'remarks'])]
#[UsePolicy(DepartmentPolicy::class)]
class Department extends TenantModel {}
