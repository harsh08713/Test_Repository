<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A paper kept on an employee's file. The file is on the private disk; the path is never sent to the browser.
 *
 * @property int $employee_id
 * @property string $doc_type
 * @property string $title
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property Carbon|null $expires_on
 */
#[Fillable(['doc_type', 'title', 'expires_on'])]
class EmployeeDocument extends TenantModel
{
    public const TYPES = ['id_proof' => 'ID proof', 'address_proof' => 'Address proof', 'offer_letter' => 'Offer letter', 'contract' => 'Contract', 'certificate' => 'Certificate', 'other' => 'Other'];

    protected $attributes = [];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['expires_on' => 'date'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
