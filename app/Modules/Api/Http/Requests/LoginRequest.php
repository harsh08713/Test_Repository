<?php

namespace App\Modules\Api\Http\Requests;

use Illuminate\Support\Str;

class LoginRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:200'],
            'device_name' => ['required', 'string', 'min:2', 'max:100'],
            'device_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._\-:]{8,64}$/'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'scopes' => ['nullable', 'array', 'min:1', 'max:2'],
            'scopes.*' => ['string', 'in:read,write'],
        ];
    }

    public function email(): string
    {
        return Str::lower(trim((string) $this->input('email')));
    }

    /** @return array{name: string, device_id: string|null, app_version: string|null, scopes: list<string>|null} */
    public function device(): array
    {
        /** @var list<string>|null $scopes */
        $scopes = $this->input('scopes') === null ? null : array_values(array_unique((array) $this->input('scopes')));

        return [
            'name' => trim((string) $this->input('device_name')),
            'device_id' => $this->filled('device_id') ? (string) $this->input('device_id') : null,
            'app_version' => $this->filled('app_version') ? (string) $this->input('app_version') : null,
            'scopes' => $scopes,
        ];
    }
}
