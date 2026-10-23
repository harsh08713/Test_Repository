<?php

namespace App\Modules\Reports\Models;

use App\Models\User;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One asked-for export of a report: who asked, which report and filters, its progress, and where the finished file is kept (a private disk,
 * a few days). The company, user, report, format and filters are set when it is asked for and never change; only the progress moves.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $report
 * @property string $format
 * @property string $status queued | running | ready | failed | expired
 * @property array<string, string> $filters
 * @property string|null $file_path
 * @property int|null $row_count
 * @property int|null $file_size
 * @property int|null $schedule_id the scheduled report that asked for it, if any
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $downloaded_at
 * @property Carbon $created_at
 */
class ReportExport extends Model
{
    use BelongsToCompany;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected $guarded = ['id', 'company_id', 'user_id', 'report', 'format', 'filters'];

    protected function casts(): array
    {
        return [
            'filters' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'expires_at' => 'datetime', 'downloaded_at' => 'datetime',
            'row_count' => 'integer', 'file_size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // The request is fixed once made.
        static::updating(function (self $export) {
            foreach (['company_id', 'user_id', 'report', 'format'] as $column) {
                if ($export->isDirty($column)) {
                    throw new \LogicException('An export’s owner, report and format cannot be changed.');
                }
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::READY && $this->file_path !== null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
