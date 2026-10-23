<?php

namespace App\Modules\Shared\Models;

use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Concerns\Auditable;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;

/**
 * One counter per company + branch + document type + financial year. Issue numbers via NumberSeriesService only.
 *
 * @property int $id
 * @property int $company_id
 * @property int $location_id
 * @property DocumentType $document_type
 * @property string $financial_year
 * @property string $prefix
 * @property int $padding
 * @property int $last_number
 * @property-read Location|null $location
 */
#[Fillable(['company_id', 'location_id', 'document_type', 'financial_year', 'prefix', 'padding'])]
class NumberSeries extends Model
{
    use Auditable, BelongsToCompany;

    protected $table = 'number_series';

    protected $attributes = [
        'padding' => 5,
        'last_number' => 0,
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'padding' => 'integer',
            'last_number' => 'integer',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** last_number changes on every issued number; auditing it would flood the log, so only config is tracked. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('number_series')
            ->logOnly(['prefix', 'padding'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function format(int $number, string $locationCode): string
    {
        return sprintf('%s/%s/%s/%s', $this->prefix, $locationCode, $this->financial_year, str_pad((string) $number, $this->padding, '0', STR_PAD_LEFT));
    }
}
