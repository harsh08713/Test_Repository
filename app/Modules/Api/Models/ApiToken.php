<?php

namespace App\Modules\Api\Models;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One signed-in device of one user (Sanctum's personal access token, plus what is needed to recognise the device in a
 * list and to see where it came from). Deleting the row revokes the device immediately. Only a hash of the token is kept.
 *
 * @property int $id
 * @property string $name
 * @property string|null $device_id
 * @property list<string>|null $abilities
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $app_version
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 */
class ApiToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    /** Which of the coarse scopes the token carries: `read` (GET) and/or `write` (everything that changes data). */
    public function hasScope(string $scope): bool
    {
        return $this->can($scope);
    }
}
