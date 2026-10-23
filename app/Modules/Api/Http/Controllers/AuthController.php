<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Requests\LoginRequest;
use App\Modules\Api\Http\Resources\DeviceResource;
use App\Modules\Api\Models\ApiToken;
use App\Modules\Api\Services\DeviceTokenService;
use App\Modules\Api\Support\ApiResponse;
use App\Modules\Api\Support\ApiTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;

class AuthController extends ApiController
{
    /** Sign a device in. Returns the token once: it is never shown again, and only a hash of it is stored. */
    public function login(LoginRequest $request, DeviceTokenService $devices): JsonResponse
    {
        ['token' => $token, 'user' => $user] = $devices->signIn($request->email(), (string) $request->input('password'), $request->device(), $request->ip(), $request->userAgent());

        return ApiResponse::data([...$this->tokenPayload($token, $request), 'user' => ['id' => $user->getKey(), 'name' => $user->name, 'email' => $user->email]], 201);
    }

    /** Swap the device's token for a fresh one (same device, same scopes); the old one stops working at once. */
    public function refresh(Request $request, DeviceTokenService $devices): JsonResponse
    {
        $user = $this->user($request);
        $current = $user->currentAccessToken();

        abort_unless($current instanceof ApiToken, 401);

        return ApiResponse::data($this->tokenPayload($devices->renew($user, $current, $request->ip(), $request->userAgent()), $request), 201);
    }

    /** Sign this device out: its token is deleted. */
    public function logout(Request $request, DeviceTokenService $devices): JsonResponse
    {
        $user = $this->user($request);
        $current = $user->currentAccessToken();

        abort_unless($current instanceof ApiToken, 401);

        $devices->revoke($user, $current, 'signed out by the device');

        return ApiResponse::data(['signed_out' => true]);
    }

    /** @return array<string, mixed> */
    private function tokenPayload(NewAccessToken $token, Request $request): array
    {
        /** @var ApiToken $access */
        $access = $token->accessToken;

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => ApiTime::iso($access->expires_at),
            'scopes' => $access->abilities ?? [],
            'device' => (new DeviceResource($access))->resolve($request),
        ];
    }
}
