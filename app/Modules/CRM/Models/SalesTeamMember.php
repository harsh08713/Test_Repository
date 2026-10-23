<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\CRM\Policies\SalesTeamMemberPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person's time on a team. Leaving sets `left_on` (the last day, inclusive); rows are never deleted, so who counted
 * towards a team in a past month stays answerable.
 *
 * @property int $sales_team_id
 * @property int $user_id
 * @property Carbon $joined_on
 * @property Carbon|null $left_on
 */
#[Fillable(['joined_on', 'left_on', 'remarks'])]
#[UsePolicy(SalesTeamMemberPolicy::class)]
class SalesTeamMember extends TenantModel
{
    use ValidatesParentCompany;

    /** This table has no active/inactive status (a membership ends with left_on), so the base model's default does not apply. */
    protected $attributes = [];

    protected function casts(): array
    {
        return [
            'joined_on' => 'date:Y-m-d',
            'left_on' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<SalesTeam, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(SalesTeam::class, 'sales_team_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Was this person on the team on $date (both ends inclusive)? */
    public function coversDate(string $date): bool
    {
        return $this->joined_on->toDateString() <= $date && ($this->left_on === null || $this->left_on->toDateString() >= $date);
    }

    protected function companyScopedParents(): array
    {
        return ['sales_team_id' => SalesTeam::class];
    }

    protected function auditLogName(): string
    {
        return 'sales_team_members';
    }
}
