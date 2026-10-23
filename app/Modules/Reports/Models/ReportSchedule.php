<?php

namespace App\Modules\Reports\Models;

use App\Models\User;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A report a person has asked to be sent to them on a schedule: which report, in which format, the filters (with a **relative** date range), how often, and when it is next due. It runs as
 * that person — their access narrows it exactly as it narrows the screen — and pauses itself, saying why, if they can no longer export the report.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $report_key
 * @property string $format
 * @property string $frequency daily|weekly|monthly
 * @property int|null $weekday 1 (Monday) to 7
 * @property int|null $month_day 1 to 28
 * @property int $send_hour
 * @property string $date_range a key of RelativeRange::RANGES
 * @property array<string, string> $filters
 * @property Carbon $next_run_at
 * @property Carbon|null $last_run_at
 * @property bool $active
 * @property string|null $paused_reason
 */
class ReportSchedule extends Model
{
    use BelongsToCompany;

    public const FREQUENCIES = ['daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month'];

    public const MAX_PER_PERSON = 10;

    protected $guarded = ['id', 'company_id', 'user_id'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'next_run_at' => 'datetime', 'last_run_at' => 'datetime', 'active' => 'boolean', 'weekday' => 'integer', 'month_day' => 'integer', 'send_hour' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
