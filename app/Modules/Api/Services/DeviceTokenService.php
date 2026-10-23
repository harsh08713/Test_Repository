<?php

namespace App\Modules\Api\Services;

use App\Models\User;
use App\Modules\Api\Models\ApiToken;
use App\Modules\Api\Support\ApiException;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

/**
 * Signs a device in and out. A device is a Sanctum token: revocable one by one, expiring, and listed for the person who owns it.
 *
 *  - **Sign in** checks the password (the same account rules as the web: active users only; the unknown-user path does the
 *    same work as the known one, so timing does not reveal which e-mail addresses exist), then that the person may use the API
 *    at all, and issues a token carrying only the scopes asked for (`read`, `write`; both by default).
 *  - A device that signs in again with the same `device_id` **replaces** its old token; beyond `max_devices` the device used
 *    least recently is signed out. So a lost phone's token cannot pile up unnoticed, and a person's list stays short.
 *  - **Renew** swaps the token for a new one (same device, same scopes) and revokes the old.
 *  - Issuing and revoking are audited — who, which device, from where — and **never** the token itself.
 */
final class DeviceTokenService
{
    /** The bcrypt hash of a password nobody has, checked when the e-mail address is unknown so the work done is the same. */
    private const DUMMY_HASH = '$2y$12$YPRSEi165LX80mYjx7cPVuP3ewanEjDe26CEPgPPOuYVxjsdyIFRi';

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array{name: string, device_id?: string|null, app_version?: string|null, scopes?: list<string>|null}  $device
     * @return array{token: NewAccessToken, user: User}
     *
     * @throws ApiException
     */
    public function signIn(string $email, string $password, array $device, ?string $ip, ?string $userAgent): array
    {
        $user = User::query()->where('email', $email)->first();

        $matches = Hash::check($password, $user === null ? self::DUMMY_HASH : (string) $user->password);

        if ($user === null || ! $matches || ! $user->is_active) {
            throw ApiException::invalidCredentials();
        }

        if (! $user->can('api.access')) {
            throw ApiException::apiAccessDenied();
        }

        if ($this->tenant->accessibleCompanyIds($user) === []) {
            throw ApiException::noCompany();
        }

        $token = $this->issue($user, $device, $ip, $userAgent);

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return ['token' => $token, 'user' => $user];
    }

    /**
     * Replace the token a request arrived with by a fresh one for the same device.
     *
     * @throws ApiException
     */
    public function renew(User $user, ApiToken $current, ?string $ip, ?string $userAgent): NewAccessToken
    {
        return DB::transaction(function () use ($user, $current, $ip, $userAgent) {
            $scopes = array_values(array_intersect(['read', 'write'], (array) $current->abilities));

            $token = $this->issue($user, [
                'name' => $current->name, 'device_id' => $current->device_id, 'app_version' => $current->app_version, 'scopes' => $scopes,
            ], $ip, $userAgent, replacing: $current);

            return $token;
        });
    }

    public function revoke(User $user, ApiToken $token, string $why): void
    {
        $this->audit($user, 'token_revoked', $token, ['why' => $why]);
        $token->delete();
    }

    /**
     * @param  array{name: string, device_id?: string|null, app_version?: string|null, scopes?: list<string>|null}  $device
     */
    private function issue(User $user, array $device, ?string $ip, ?string $userAgent, ?ApiToken $replacing = null): NewAccessToken
    {
        return DB::transaction(function () use ($user, $device, $ip, $userAgent, $replacing) {
            $deviceId = $device['device_id'] ?? null;

            // The same device signing in again, or renewing, takes the place of its old token.
            $replacing?->delete();

            if ($deviceId !== null) {
                $user->tokens()->where('device_id', $deviceId)->delete();
            }

            // Keep the list short: sign out the device used least recently.
            $max = max(1, (int) config('formezy.api.max_devices', 5));
            $excess = $user->tokens()->count() - ($max - 1);

            if ($excess > 0) {
                foreach ($user->tokens()->orderByRaw('coalesce(last_used_at, created_at)')->orderBy('id')->limit($excess)->get() as $old) {
                    if ($old instanceof ApiToken) {
                        $this->revoke($user, $old, 'signed out to stay within the device limit');
                    }
                }
            }

            $scopes = array_values(array_intersect(['read', 'write'], $device['scopes'] ?? ['read', 'write']));
            $expires = now()->addMinutes((int) config('formezy.api.token_ttl_minutes', 20160));

            $new = $user->createToken(mb_substr(trim($device['name']), 0, 100), $scopes === [] ? ['read', 'write'] : $scopes, $expires);

            /** @var ApiToken $model */
            $model = $new->accessToken;
            $model->forceFill([
                'device_id' => $deviceId, 'ip_address' => $ip, 'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
                'app_version' => isset($device['app_version']) ? mb_substr((string) $device['app_version'], 0, 40) : null,
            ])->save();

            $this->audit($user, $replacing === null ? 'token_issued' : 'token_renewed', $model, ['scopes' => $model->abilities]);

            return $new;
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(User $user, string $event, ApiToken $token, array $extra = []): void
    {
        // Signing in happens before anyone is authenticated, so say whose company this belongs to: the user's own.
        $companyId = $this->tenant->accessibleCompanyIds($user)[0] ?? null;

        activity('api')
            ->causedBy($user)
            ->performedOn($user)
            ->event($event)
            ->withProperties(['device' => $token->name, 'device_id' => $token->device_id, 'token_id' => $token->getKey(), 'ip' => $token->ip_address, ...$extra])
            ->tap(function (ActivityLog $activity) use ($companyId) {
                $activity->company_id = $companyId;
            })
            ->log($event);
    }
}
