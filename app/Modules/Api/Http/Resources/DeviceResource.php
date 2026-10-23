<?php

namespace App\Modules\Api\Http\Resources;

use App\Modules\Api\Models\ApiToken;
use App\Modules\Api\Support\ApiTime;
use Illuminate\Http\Request;

/**
 * A signed-in device, as its owner sees it in their list. Never the token — only a hash of it is stored.
 *
 * @mixin ApiToken
 */
class DeviceResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ApiToken $token */
        $token = $this->resource;
        $current = $request->user()?->currentAccessToken();

        return [
            'id' => $token->getKey(),
            'name' => $token->name,
            'device_id' => $token->device_id,
            'scopes' => $token->abilities ?? [],
            'ip_address' => $token->ip_address,
            'app_version' => $token->app_version,
            'created_at' => ApiTime::iso($token->created_at),
            'last_used_at' => ApiTime::iso($token->last_used_at),
            'expires_at' => ApiTime::iso($token->expires_at),
            'current' => $current instanceof ApiToken && $current->getKey() === $token->getKey(),
        ];
    }
}
