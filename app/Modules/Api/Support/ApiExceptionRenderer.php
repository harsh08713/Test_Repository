<?php

namespace App\Modules\Api\Support;

use App\Modules\CRM\Exceptions\AttendanceRefused;
use App\Modules\CRM\Exceptions\FieldPositionRefused;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\DeadlockException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns whatever went wrong inside an `/api/*` request into the API's error envelope, with the right status:
 *
 *   400 bad_request, idempotency_key_*      401 unauthenticated, invalid_credentials, account_inactive
 *   403 forbidden, api_access_denied, …     404 not_found      405 method_not_allowed
 *   409 state_conflict, already_marked, …   422 validation_failed, <geofence reason>, idempotency_key_reused
 *   429 too_many_requests                   500 server_error   503 busy
 *
 * Nothing about the server's insides ever reaches the client: an unexpected error is a generic message plus the request
 * id; the details go to the log (the framework reports the exception before this renders it).
 */
final class ApiExceptionRenderer
{
    public static function isApi(Request $request): bool
    {
        return $request->is('api/*');
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::isApi($request)) {
            return null;
        }

        // Errors raised before the route's middleware ran (an unknown path) have no correlation id yet.
        if (! $request->attributes->has('request_id')) {
            $request->attributes->set('request_id', (string) Str::ulid());
        }

        $response = self::map($e);
        $response->headers->set('X-Request-Id', (string) $request->attributes->get('request_id'));

        return $response;
    }

    private static function map(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => ApiResponse::error($e->errorCode, $e->getMessage(), $e->status, $e->fields, $e->details, $e->headers),

            $e instanceof ValidationException => ApiResponse::error('validation_failed', (string) collect($e->errors())->flatten()->first() ?: 'The request could not be processed.', 422, self::fields($e)),

            $e instanceof AuthenticationException => ApiResponse::error('unauthenticated', 'Sign in again: the device token is missing, expired or has been revoked.', 401, headers: ['WWW-Authenticate' => 'Bearer']),

            // a geofence / route-plan refusal: 422, and the attempt is already in the violation log
            $e instanceof FieldPositionRefused => ApiResponse::error($e->reason->value, $e->getMessage(), 422, details: [
                'reason_label' => $e->reason->label(), 'violation_id' => $e->violationId, 'attempt' => $e->attemptType,
            ]),

            $e instanceof AttendanceRefused => $e->reason === null
                ? ApiResponse::error('already_marked', $e->getMessage(), 409)
                : ApiResponse::error($e->reason->value, $e->getMessage(), 422, details: ['reason_label' => $e->reason->label(), 'violation_id' => $e->violationId, 'attempt' => 'attendance']),

            // a business rule says no because of the state things are in (a lost lead, a follow-up already done, a deal already in approval)
            $e instanceof FieldRuleException, $e instanceof ApprovalStateException => ApiResponse::error('state_conflict', $e->getMessage(), 409),

            $e instanceof DeadlockException => ApiResponse::error('busy', 'The server was busy with another change to the same records. Send the request again.', 503, headers: ['Retry-After' => '1']),

            $e instanceof HttpExceptionInterface => self::http($e),

            default => ApiResponse::error('server_error', 'Something went wrong on our side. Report it with the request id.', 500),
        };
    }

    private static function http(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        $headers = array_filter($e->getHeaders(), fn ($value) => is_string($value) || is_int($value));
        $headers = array_map('strval', $headers);

        [$code, $message] = match ($status) {
            400 => ['bad_request', 'The request could not be read. Send a valid JSON body.'],
            401 => ['unauthenticated', 'Sign in again.'],
            403 => ['forbidden', $e->getMessage() !== '' ? $e->getMessage() : 'You are not allowed to do this.'],
            404 => ['not_found', 'There is nothing here, or you are not allowed to see it.'],
            405 => ['method_not_allowed', 'That method is not allowed on this address.'],
            413 => ['payload_too_large', 'The request is too large.'],
            415 => ['unsupported_media_type', 'Send the body as application/json.'],
            429 => ['too_many_requests', 'Too many requests. Wait a little and try again.'],
            default => $status >= 500 ? ['server_error', 'Something went wrong on our side. Report it with the request id.'] : ['request_refused', 'The request was refused.'],
        };

        return ApiResponse::error($code, $message, $status, headers: $headers);
    }

    /**
     * The services name their fields after the form they were written for ("lead.mobile", "plan.scheduled_at"); the API
     * names them plainly ("mobile", "scheduled_at").
     *
     * @return array<string, list<string>>
     */
    private static function fields(ValidationException $e): array
    {
        $fields = [];

        foreach ($e->errors() as $key => $messages) {
            $fields[(string) preg_replace('/^(lead|plan|done|log|deal)\./', '', (string) $key)] = array_values($messages);
        }

        return $fields;
    }
}
