<?php

namespace App\Modules\Api\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base of the API's resources. They only describe a record's wire form; the envelope (`data`, `meta`) is added by
 * ApiResponse, so wrapping is off here.
 */
abstract class ApiResource extends JsonResource
{
    public static $wrap = null;
}
