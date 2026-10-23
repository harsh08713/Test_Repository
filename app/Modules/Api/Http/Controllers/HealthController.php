<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Support\ApiResponse;
use App\Modules\Api\Support\ApiTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `GET /api/v1/health` — is the API up and can it reach its database? Open to anyone (a load balancer or the app's start-up
 * check calls it), so it says nothing about the installation: no versions, no environment, no configuration, no errors.
 */
class HealthController
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'unavailable';
        }

        $ok = $database === 'ok';

        return ApiResponse::data([
            'status' => $ok ? 'ok' : 'degraded',
            'api' => 'v1',
            'time' => ApiTime::iso(now()),
            'checks' => ['database' => $database],
        ], $ok ? 200 : 503);
    }
}
