<?php

namespace App\Modules\Api\Http\Requests;

use App\Modules\Api\Support\ApiTime;
use App\Modules\Api\Support\IsoDateTime;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of the API's Form Requests. A request checks the **shape** of what arrived — types, lengths, bounds — so nothing odd
 * reaches the services; whether a value is *allowed* (this lead, this branch, this price) is decided by the services and the
 * policies, never here. Authorisation is not done in the request either: the controllers ask the policies, after the record has
 * been found through the person's own scope.
 */
abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The bounded paging parameters every list takes.
     *
     * @return array<string, mixed>
     */
    protected function pagingRules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('formezy.api.max_per_page', 100)],
            'updated_since' => ['nullable', new IsoDateTime],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?: config('formezy.api.per_page', 20));
    }

    /** Only what changed at or after this moment — what a client syncing since its last visit asks for. */
    public function since(): ?CarbonInterface
    {
        return ApiTime::parse($this->input('updated_since'));
    }
}
