<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Resources\DeviceResource;
use App\Modules\Api\Models\ApiToken;
use App\Modules\Api\Support\ApiResponse;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/me` — who the token belongs to, which company the request is working in, the branches they may use, and what the
 * app may offer them. The `can` map is a convenience for showing or hiding buttons; the server decides again on every call.
 */
class ProfileController extends ApiController
{
    public function __invoke(Request $request, TenantContext $tenant, SettingsService $settings): JsonResponse
    {
        $user = $this->user($request);
        $companyId = (int) $tenant->activeCompanyId();

        $companies = Company::query()->orderBy('name')->get(['id', 'code', 'name']);
        $active = $companies->firstWhere('id', $companyId);
        $token = $user->currentAccessToken();

        return ApiResponse::data([
            'user' => ['id' => $user->getKey(), 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone],
            'company' => $active === null ? null : ['id' => $active->id, 'code' => $active->code, 'name' => $active->name],
            'companies' => $companies->map(fn (Company $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->values()->all(),
            'branches' => Location::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn (Location $b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])->values()->all(),
            'can' => [
                'mark_attendance' => $user->can('attendance.create'),
                'view_route_plan' => $user->can('route_plan.view'),
                'view_leads' => $user->can('lead.view'),
                'create_leads' => $user->can('lead.create'),
                'edit_leads' => $user->can('lead.edit'),
                'delete_leads' => $user->can('lead.delete'),
                'assign_leads' => $user->can('lead.assign'),
                'view_followups' => $user->can('followup.view'),
                'create_followups' => $user->can('followup.create'),
                'edit_followups' => $user->can('followup.edit'),
                'view_items' => $user->can('item.view'),
                'see_whole_company' => $user->can('sales.scope_all'),
            ],
            'field_entry' => [
                // a person who may mark attendance enters leads from the field: with a position, against today's route plan
                'required' => $user->can('attendance.create'),
                'default_radius_m' => (int) $settings->get('geofence.radius_m', $companyId),
                'max_accuracy_m' => (int) $settings->get('geofence.max_accuracy_m', $companyId),
            ],
            'device' => $token instanceof ApiToken ? (new DeviceResource($token))->resolve($request) : null,
        ]);
    }
}
