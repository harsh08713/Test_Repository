<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\CRM\Enums\TargetPeriod;
use App\Modules\CRM\Policies\SalesTargetPolicy;
use App\Modules\Masters\Models\ItemGroup;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a salesperson or a team is aiming for over a month or a quarter: an order value (net of tax), a quantity (in
 * each item's base unit), or both, optionally for one item group and/or one branch.
 *
 * Whoever a target is for, two targets with the same dimensions may not cover the same days unless the second one is
 * explicitly allowed to overlap (TargetService). What was achieved comes from approved sales orders: TargetAchievement.
 *
 * @property int|null $user_id
 * @property int|null $sales_team_id
 * @property TargetPeriod $period_type
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string|null $target_value
 * @property string|null $target_quantity
 * @property int|null $item_group_id
 * @property int|null $location_id
 * @property bool $allow_overlap
 * @property string|null $slot_key
 */
#[Fillable(['period_type', 'period_start', 'period_end', 'target_value', 'target_quantity', 'allow_overlap', 'status', 'remarks'])]
#[UsePolicy(SalesTargetPolicy::class)]
class SalesTarget extends TenantModel
{
    use BelongsToLocation, ValidatesParentCompany;

    protected function casts(): array
    {
        return [
            'period_type' => TargetPeriod::class,
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'target_value' => 'decimal:2',
            'target_quantity' => 'decimal:3',
            'allow_overlap' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<SalesTeam, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(SalesTeam::class, 'sales_team_id');
    }

    /** @return BelongsTo<ItemGroup, $this> */
    public function itemGroup(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class);
    }

    /** Has the period ended? A target of a finished period is history and no longer changes. */
    public function isOver(?string $today = null): bool
    {
        return $this->period_end->toDateString() < ($today ?? now()->toDateString());
    }

    /** Who it is for, for people: the salesperson's or the team's name. */
    public function subjectName(): string
    {
        return $this->user_id !== null ? (string) ($this->user->name ?? 'Unknown user') : (string) ($this->team->name ?? 'Unknown team');
    }

    protected function companyScopedParents(): array
    {
        return ['sales_team_id' => SalesTeam::class, 'item_group_id' => ItemGroup::class];
    }

    protected function auditLogName(): string
    {
        return 'sales_targets';
    }
}
