<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Resources\DeviceResource;
use App\Modules\Api\Models\ApiToken;
use App\Modules\Api\Services\DeviceTokenService;
use App\Modules\Api\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A person's own signed-in devices: list them, and sign one out (a lost phone). Never anyone else's. */
class DeviceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $devices = $user->tokens()->orderByRaw('coalesce(last_used_at, created_at) desc')->orderByDesc('id')->get();

        return ApiResponse::data(DeviceResource::collection($devices)->resolve($request));
    }

    public function destroy(Request $request, string $device, DeviceTokenService $devices): JsonResponse
    {
        $user = $this->user($request);

        $token = ctype_digit($device) ? $user->tokens()->whereKey((int) $device)->first() : null;

        abort_unless($token instanceof ApiToken, 404);

        $devices->revoke($user, $token, 'signed out from the device list');

        return ApiResponse::data(['revoked' => true, 'id' => (int) $device]);
    }
}
