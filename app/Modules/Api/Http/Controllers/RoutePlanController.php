<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Resources\RoutePlanResource;
use App\Modules\Api\Support\ApiResponse;
use App\Modules\CRM\Models\RoutePlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RoutePlanController extends ApiController
{
    /**
     * `GET /api/v1/route-plan/today` — the signed-in person's own plan for today (server date, company timezone), or `null`
     * with `meta.reason = "no_route_plan"`. A plan that is still a draft or was cancelled is returned as such: the app shows why
     * attendance and field entry are not available.
     */
    public function today(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', RoutePlan::class);

        $plan = RoutePlan::query()
            ->with(['location:id,code,name', 'stops', 'attendance.stop:id,label'])
            ->where('user_id', $this->user($request)->getKey())
            ->where('plan_date', now()->toDateString())
            ->first();

        if ($plan === null) {
            return ApiResponse::data(null, 200, ['reason' => 'no_route_plan', 'message' => 'You have no route plan for today. Ask your manager to plan your day.']);
        }

        return ApiResponse::data(RoutePlanResource::make($plan)->resolve($request));
    }
}
