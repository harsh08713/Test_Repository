<?php

namespace App\Modules\Api\Http\Requests;

use App\Modules\Api\Support\Scalar;

/**
 * The device's reading. It is only a claim: whether it is a real position, accurate enough, and inside a stop's geofence is
 * decided (and a refusal logged) by the server. So a nonsense number passes this shape check on purpose — it is the geofence's
 * job to refuse it and write it down — while a missing, nested or enormous value is just a malformed request.
 */
class AttendanceRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'position' => ['required', 'array'],
            'position.latitude' => ['required', new Scalar],
            'position.longitude' => ['required', new Scalar],
            'position.accuracy_m' => ['required', new Scalar],
        ];
    }
}
