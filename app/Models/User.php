<?php

namespace App\Models;

use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Policies\UserPolicy;
use App\Modules\Shared\Support\PruneUnloadedAttributes;
use App\Modules\Shared\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property int|null $default_company_id
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 */
#[Fillable(['name', 'email', 'phone', 'password', 'default_company_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
#[UsePolicy(UserPolicy::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable;

    public const SUPER_ADMIN_ROLE = 'Super Admin';

    protected static function booted(): void
    {
        PruneUnloadedAttributes::register(static::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Companies this user belongs to; pivot `all_locations` lifts the branch restriction for that company.
     *
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withPivot('all_locations')->withTimestamps();
    }

    /**
     * Branches the user is restricted to (when the company membership is not `all_locations`).
     *
     * @return BelongsToMany<Location, $this>
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class)->withTimestamps();
    }

    /** @return BelongsTo<Company, $this> */
    public function defaultCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'default_company_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::SUPER_ADMIN_ROLE);
    }

    /**
     * Users $actor is allowed to administer: everyone for a Super Admin; otherwise users who share
     * one of the actor's companies (or belong to none yet), never a Super Admin. Mirrors UserPolicy.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeManagedBy(Builder $query, self $actor): Builder
    {
        $tenant = app(TenantContext::class);

        if ($tenant->canAccessEverything($actor)) {
            return $query;
        }

        $companyIds = $tenant->accessibleCompanyIds($actor);

        return $query
            ->whereDoesntHave('roles', fn (Builder $roles) => $roles->where('name', self::SUPER_ADMIN_ROLE))
            ->where(function (Builder $q) use ($companyIds) {
                $q->whereIn('users.id', DB::table('company_user')->whereIn('company_id', $companyIds)->select('user_id'))
                    ->orWhereNotIn('users.id', DB::table('company_user')->select('user_id'));
            });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('user')
            ->logOnly(['name', 'email', 'phone', 'is_active', 'default_company_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
