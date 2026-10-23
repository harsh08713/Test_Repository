<?php

namespace App\Modules\Shared\Tenancy;

use App\Models\User;
use App\Modules\Shared\Exceptions\TenantAccessException;
use App\Modules\Shared\Models\ActivityLog;
use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for "which company / branches may the current actor see?".
 *
 * Resolution order for the active company:
 *   1. an explicit override (jobs: withCompany())
 *   2. the request's choice (session key, or the X-Company-Id header for the API) — only if the user may access it
 *   3. the user's default company
 *   4. the first company the user belongs to
 *
 * Fail-closed rule used by the global scopes:
 *   - authenticated user          -> scoped to what that user may access
 *   - no user, HTTP request       -> sees nothing
 *   - no user, console (jobs/seed)-> unrestricted, unless a company override is active
 *
 * Registered as a scoped singleton, so state never leaks between requests (Octane-safe).
 */
final class TenantContext
{
    public const SESSION_COMPANY = 'tenant.company_id';

    public const SESSION_LOCATION = 'tenant.location_id';

    private bool $bypass = false;

    private ?int $forcedCompanyId = null;

    private ?int $selectedCompanyId = null;

    private ?int $selectedLocationId = null;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        private readonly AuthManager $auth,
        private readonly Application $app,
    ) {}

    public function user(): ?User
    {
        $user = $this->auth->user();

        return $user instanceof User ? $user : null;
    }

    public function isUnrestricted(): bool
    {
        if ($this->bypass) {
            return true;
        }

        return $this->forcedCompanyId === null
            && $this->user() === null
            && $this->app->runningInConsole();
    }

    public function activeCompanyId(): ?int
    {
        if ($this->forcedCompanyId !== null) {
            return $this->forcedCompanyId;
        }

        $user = $this->user();
        if ($user === null) {
            return null;
        }

        $accessible = $this->accessibleCompanyIds($user);

        $requested = $this->requestedCompanyId();
        if ($requested !== null && in_array($requested, $accessible, true)) {
            return $requested;
        }

        if ($user->default_company_id !== null && in_array((int) $user->default_company_id, $accessible, true)) {
            return (int) $user->default_company_id;
        }

        return $accessible[0] ?? null;
    }

    /**
     * Companies the given (or current) user may work in.
     *
     * @return list<int>
     */
    public function accessibleCompanyIds(?User $user = null): array
    {
        $user ??= $this->user();

        if ($user === null) {
            return $this->forcedCompanyId !== null ? [$this->forcedCompanyId] : [];
        }

        return $this->memo['companies:'.$user->getKey()] ??= $this->loadCompanyIds($user);
    }

    /**
     * Branches the current user may see inside the active company. `null` means "all branches".
     *
     * @return list<int>|null
     */
    public function accessibleLocationIds(): ?array
    {
        $companyId = $this->activeCompanyId();
        if ($companyId === null) {
            return [];
        }

        $user = $this->user();
        if ($user === null) {
            return null; // job running as a company: every branch of it
        }

        return $this->accessibleLocationIdsFor($user, $companyId);
    }

    /**
     * Branches $user may see inside $companyId; null means all of them. Unlike accessibleLocationIds() this is
     * about an explicit user and company, which is what policies need when they are asked about someone.
     *
     * @return list<int>|null
     */
    public function accessibleLocationIdsFor(User $user, int $companyId): ?array
    {
        // array_key_exists, not ??=, because null is a meaningful cached value ("all branches").
        $key = "locations:{$user->getKey()}:{$companyId}";
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $this->userCanAccessCompany($user, $companyId) ? $this->loadLocationIds($user, $companyId) : [];
        }

        return $this->memo[$key];
    }

    /** The branch new documents default to; null when the user has several and has not picked one. */
    public function activeLocationId(): ?int
    {
        $accessible = $this->accessibleLocationIds();
        $requested = $this->requestedLocationId();

        if ($requested !== null && $this->locationBelongsToActiveCompany($requested)
            && ($accessible === null || in_array($requested, $accessible, true))) {
            return $requested;
        }

        if ($accessible !== null && count($accessible) === 1) {
            return $accessible[0];
        }

        return null;
    }

    public function canAccessCompany(int $companyId): bool
    {
        if ($this->isUnrestricted()) {
            return true;
        }

        return in_array($companyId, $this->accessibleCompanyIds(), true);
    }

    public function canAccessLocation(int $locationId): bool
    {
        if ($this->isUnrestricted()) {
            return true;
        }

        if (! $this->locationBelongsToActiveCompany($locationId)) {
            return false;
        }

        $accessible = $this->accessibleLocationIds();

        return $accessible === null || in_array($locationId, $accessible, true);
    }

    public function userCanAccessCompany(User $user, int $companyId): bool
    {
        return in_array($companyId, $this->accessibleCompanyIds($user), true);
    }

    public function switchCompany(int $companyId): void
    {
        if (! in_array($companyId, $this->accessibleCompanyIds(), true)) {
            throw TenantAccessException::company($companyId);
        }

        // Kept in memory as well as in the session so the choice also holds where there is no session (API, console).
        $this->selectedCompanyId = $companyId;
        $this->selectedLocationId = null;

        $session = $this->session();
        $session?->put(self::SESSION_COMPANY, $companyId);
        $session?->forget(self::SESSION_LOCATION);
        $this->flush();

        // Entering a company you do not belong to is a privileged act: leave a trace in that company's audit log.
        $user = $this->user();
        if ($user !== null && ! $this->isMember($user, $companyId)) {
            activity('tenant')
                ->causedBy($user)
                ->event('cross_company_access')
                ->withProperties(['company_id' => $companyId, 'via' => 'tenant.access_all'])
                ->tap(function (ActivityLog $activity) use ($companyId) {
                    $activity->company_id = $companyId;
                })
                ->log('cross_company_access');
        }
    }

    public function switchLocation(?int $locationId): void
    {
        if ($locationId !== null && ! $this->canAccessLocation($locationId)) {
            throw TenantAccessException::location($locationId);
        }

        $this->selectedLocationId = $locationId;

        $locationId === null
            ? $this->session()?->forget(self::SESSION_LOCATION)
            : $this->session()?->put(self::SESSION_LOCATION, $locationId);
    }

    /** Run a callback (queued job, scheduler) restricted to one company with access to all of its branches. */
    public function withCompany(int $companyId, Closure $callback): mixed
    {
        $previous = $this->forcedCompanyId;
        $this->forcedCompanyId = $companyId;

        try {
            return $callback();
        } finally {
            $this->forcedCompanyId = $previous;
        }
    }

    /** Escape hatch for system tasks that must see every company (seeders, cross-company notifications). */
    public function withoutTenancy(Closure $callback): mixed
    {
        $previous = $this->bypass;
        $this->bypass = true;

        try {
            return $callback();
        } finally {
            $this->bypass = $previous;
        }
    }

    /** Drop memoised lookups (call after membership changes within a request). */
    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * Reach beyond one's own memberships is an explicit permission (`tenant.access_all`, held by the
     * Super Admin role), never an implicit role-name bypass. Memoised per user for the request.
     */
    public function canAccessEverything(User $user): bool
    {
        return $this->memo['all:'.$user->getKey()] ??= $user->can('tenant.access_all');
    }

    private function isMember(User $user, int $companyId): bool
    {
        return DB::table('company_user')->where('user_id', $user->getKey())->where('company_id', $companyId)->exists();
    }

    /** @return list<int> */
    private function loadCompanyIds(User $user): array
    {
        $query = DB::table('companies')->whereNull('companies.deleted_at')->orderBy('companies.id');

        if (! $this->canAccessEverything($user)) {
            $query->join('company_user', 'company_user.company_id', '=', 'companies.id')
                ->where('company_user.user_id', $user->getKey());
        }

        return $query->pluck('companies.id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int>|null */
    private function loadLocationIds(User $user, int $companyId): ?array
    {
        if ($this->canAccessEverything($user)) {
            return null;
        }

        $membership = DB::table('company_user')
            ->where('company_id', $companyId)
            ->where('user_id', $user->getKey())
            ->first();

        if ($membership === null) {
            return [];
        }

        if ($membership->all_locations) {
            return null;
        }

        return DB::table('location_user')
            ->join('locations', 'locations.id', '=', 'location_user.location_id')
            ->where('location_user.user_id', $user->getKey())
            ->where('locations.company_id', $companyId)
            ->whereNull('locations.deleted_at')
            ->pluck('locations.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function locationBelongsToActiveCompany(int $locationId): bool
    {
        $companyId = $this->activeCompanyId();

        return $companyId !== null && DB::table('locations')
            ->where('id', $locationId)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function requestedCompanyId(): ?int
    {
        if ($this->selectedCompanyId !== null) {
            return $this->selectedCompanyId;
        }

        $fromSession = $this->session()?->get(self::SESSION_COMPANY);
        if ($fromSession !== null) {
            return (int) $fromSession;
        }

        $header = $this->app->bound('request') ? $this->app->make('request')->header('X-Company-Id') : null;

        return is_numeric($header) ? (int) $header : null;
    }

    private function requestedLocationId(): ?int
    {
        if ($this->selectedLocationId !== null) {
            return $this->selectedLocationId;
        }

        $value = $this->session()?->get(self::SESSION_LOCATION);

        return $value !== null ? (int) $value : null;
    }

    private function session(): ?Session
    {
        if (! $this->app->bound('request')) {
            return null;
        }

        $request = $this->app->make('request');

        return $request->hasSession() ? $request->session() : null;
    }
}
