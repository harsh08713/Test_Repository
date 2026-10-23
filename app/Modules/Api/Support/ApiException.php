<?php

namespace App\Modules\Api\Support;

use RuntimeException;

/**
 * A refusal the API reports in its own words: an HTTP status, a stable machine-readable `code` the app can switch on, a
 * message meant for the person, and optionally the fields at fault and extra `details` (a violation id, the current
 * version of a record). Rendered by ApiExceptionRenderer; see docs/api.md for the list of codes.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $fields
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly array $fields = [],
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self('invalid_credentials', 'These credentials do not match an active account.', 401);
    }

    public static function accountInactive(): self
    {
        return new self('account_inactive', 'Your account has been deactivated. Ask an administrator.', 401);
    }

    public static function apiAccessDenied(): self
    {
        return new self('api_access_denied', 'Your account is not allowed to use the mobile app. Ask an administrator for the “api.access” permission.', 403);
    }

    public static function scopeMissing(string $scope): self
    {
        return new self('scope_missing', "This device was signed in with read-only access, so it cannot change data (needs the “{$scope}” scope).", 403);
    }

    public static function companyNotAccessible(): self
    {
        return new self('company_not_accessible', 'You do not have access to the company named in X-Company-Id.', 403);
    }

    public static function noCompany(): self
    {
        return new self('no_company', 'Your account is not assigned to any company. Ask an administrator.', 403);
    }

    public static function idempotencyKeyRequired(): self
    {
        return new self('idempotency_key_required', 'Send an Idempotency-Key header (a fresh UUID per action) so a retry cannot create the record twice.', 400);
    }

    public static function idempotencyKeyInvalid(): self
    {
        return new self('idempotency_key_invalid', 'The Idempotency-Key must be 8 to 80 characters: letters, digits, and - _ : .', 400);
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('idempotency_key_reused', 'This Idempotency-Key was already used for a different request. Use a new key for a new action.', 422);
    }

    public static function requestInProgress(): self
    {
        return new self('request_in_progress', 'The same request is still being processed. Wait a moment and send it again with the same Idempotency-Key.', 409, headers: ['Retry-After' => '1']);
    }

    /** @param  array<string, mixed>  $details */
    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 409, details: $details);
    }
}
