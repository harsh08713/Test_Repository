<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Requests\AttendanceRequest;
use App\Modules\Api\Http\Requests\PagedRequest;
use App\Modules\Api\Http\Resources\AttendanceResource;
use App\Modules\Api\Support\ApiResponse;
use App\Modules\CRM\Models\AttendanceRecord;
use App\Modules\CRM\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AttendanceController extends ApiController
{
    /** The signed-in person's own attendance, newest day first. */
    public function index(PagedRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', AttendanceRecord::class);

        $page = AttendanceRecord::query()->with('stop:id,label')
            ->where('user_id', $this->user($request)->getKey())
            ->when($request->filled('from'), fn ($q) => $q->where('attendance_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('attendance_date', '<=', $request->input('to')))
            ->orderByDesc('attendance_date')->orderByDesc('id')
            ->paginate($request->perPage());

        return ApiResponse::page($page, AttendanceResource::collection($page->getCollection())->resolve($request));
    }

    /**
     * Mark today's attendance from where the device is. The device sends only a position and its accuracy; AttendanceService
     * works out everything else — today's plan, the distance, the radius, the verdict, the time — and a refusal is written to the
     * violation log and answered 422 (`no_route_plan`, `plan_not_published`, `invalid_gps`, `low_accuracy`, `outside_geofence`), or
     * 409 `already_marked`.
     */
    public function store(AttendanceRequest $request, AttendanceService $service): JsonResponse
    {
        Gate::authorize('create', AttendanceRecord::class);

        $record = $service->mark(
            $this->user($request),
            $request->input('position.latitude'), $request->input('position.longitude'), $request->input('position.accuracy_m'),
            'mobile', $request->userAgent(),
        );

        return ApiResponse::data(AttendanceResource::make($record->load('stop:id,label'))->resolve($request), 201);
    }
}
