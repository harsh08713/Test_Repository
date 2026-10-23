<?php

namespace App\Modules\Shared\Models;

use App\Models\User;
use App\Modules\Shared\Enums\ImportStatus;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use App\Modules\Shared\Policies\ImportBatchPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One uploaded spreadsheet and what became of it. Whatever the file contains (geography now; leads and
 * route plans later), the same record carries its progress, counts and every rejected row.
 *
 * @property int $id
 * @property int $company_id
 * @property string $importer
 * @property string $original_filename
 * @property string $stored_path
 * @property ImportStatus $status
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $processed_rows
 * @property int $created_count
 * @property int $updated_count
 * @property int $unchanged_count
 * @property int $error_count
 * @property array<string, mixed>|null $summary
 * @property string|null $failure_reason
 * @property int|null $created_by
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 */
#[Fillable(['importer', 'original_filename', 'stored_path'])]
#[UsePolicy(ImportBatchPolicy::class)]
class ImportBatch extends Model
{
    use BelongsToCompany;

    protected $attributes = [
        'status' => 'uploaded',
        'total_rows' => 0,
        'valid_rows' => 0,
        'processed_rows' => 0,
        'created_count' => 0,
        'updated_count' => 0,
        'unchanged_count' => 0,
        'error_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ImportBatchError, $this> */
    public function errors(): HasMany
    {
        return $this->hasMany(ImportBatchError::class)->orderBy('row_number')->orderBy('id');
    }

    /** Percentage of rows handled so far, for the progress bar. */
    public function progress(): int
    {
        return $this->total_rows > 0 ? (int) min(100, floor($this->processed_rows / $this->total_rows * 100)) : 0;
    }

    /** @return mixed one value of the free-form summary */
    public function summaryValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->summary, $key, $default);
    }
}
