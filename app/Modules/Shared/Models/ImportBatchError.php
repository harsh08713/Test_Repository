<?php

namespace App\Modules\Shared\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A spreadsheet row that was rejected, with the reasons and the cells as uploaded. Reached only through its
 * batch (which is tenant-scoped), so it carries no company of its own.
 *
 * @property int $id
 * @property int $import_batch_id
 * @property int $row_number
 * @property string $phase
 * @property list<string> $messages
 * @property array<string, string> $raw
 */
#[Fillable(['import_batch_id', 'row_number', 'phase', 'messages', 'raw'])]
class ImportBatchError extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['messages' => 'array', 'raw' => 'array'];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
