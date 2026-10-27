<?php

namespace App\Modules\Integrations\Http\Controllers;

use App\Modules\Api\Support\ApiException;
use App\Modules\Api\Support\ApiResponse;
use App\Modules\Integrations\Models\IntegrationConnection;
use App\Modules\Integrations\Providers\BrowserForm;
use App\Modules\Integrations\Providers\ProviderRegistry;
use App\Modules\Integrations\Services\LeadApiLogStore;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * `POST /api/webhooks/{provider}/{key}` — where a provider pushes its leads.
 *
 * What is checked, in this order, and what each failure says:
 *
 *  1. the address: a provider we know, a connection whose unguessable `key` matches, switched on and open to pushes — otherwise
 *     **404**, the same whether the address never existed or was switched off (nothing is confirmed to a guesser);
 *  2. the size: at most 64 KB and 200 records (**413**);
 *  3. the **signature**, when the company has set a signing secret: `X-FormEzy-Timestamp` (within five minutes of now) and
 *     `X-FormEzy-Signature: sha256=<hex>` where the hex is HMAC-SHA256, keyed with the secret, of `<timestamp>.<raw body>`
 *     (**401** otherwise). A signed request that has already been seen is answered as accepted and not looked at again (replay).
 *     A provider that cannot sign (IndiaMART's push, Just Dial's) relies on the key in the address alone; the settings screen says
 *     so, and a small relay that signs is the way to harden it;
 *  4. the shape: JSON, or form fields, holding one lead or a list (**400**).
 *
 * What is accepted is written to the log first, then queued for processing, and the answer is **202** straight away: a slow
 * lead creation never makes the provider time out and re-send. The same lead delivered twice is one row (it is only counted).
 */
class WebhookController
{
    public const MAX_BYTES = 65536;

    public const MAX_RECORDS = 200;

    public const TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request, string $provider, string $key, ProviderRegistry $providers, TenantContext $tenant, SettingsService $settings, LeadApiLogStore $store): JsonResponse
    {
        abort_unless($providers->has($provider) && preg_match('/^[A-Za-z0-9]{40}$/', $key) === 1, 404);

        $connection = IntegrationConnection::withoutGlobalScopes(Tenancy::SCOPES)->where('provider', $provider)->where('public_key', $key)->first();
        $adapter = $providers->get($provider);

        abort_unless($connection !== null && $connection->enabled && $connection->receivesPushes() && $adapter->canPush(), 404);

        return $tenant->withCompany((int) $connection->company_id, function () use ($request, $connection, $adapter, $settings, $store): JsonResponse {
            $raw = $request->getContent();

            if (strlen($raw) > self::MAX_BYTES) {
                throw new ApiException('payload_too_large', 'That is more than a lead feed ever needs to send (64 KB).', 413);
            }

            if ($this->replayed($request, $raw, (string) $settings->get("integrations.{$connection->provider}_webhook_secret", (int) $connection->company_id))) {
                return ApiResponse::data(['status' => 'accepted', 'received' => 0, 'new' => 0, 'already_known' => 0, 'replayed' => true], 202);
            }

            $records = $adapter->extractRecords($this->body($request, $raw));

            if ($records === []) {
                throw new ApiException('bad_request', 'No lead was found in that request.', 400);
            }

            if (count($records) > self::MAX_RECORDS) {
                throw new ApiException('payload_too_large', 'Send at most '.self::MAX_RECORDS.' leads in one request.', 413);
            }

            // a robot that filled the trap field is told it worked and nothing is written down: it learns nothing to adapt to
            if ($adapter instanceof BrowserForm) {
                $received = count($records);
                $records = array_values(array_filter($records, fn (array $record) => ! $adapter->isBot($record)));

                if ($records === []) {
                    return ApiResponse::data(['status' => 'accepted', 'received' => $received, 'new' => 0, 'already_known' => 0], 202);
                }
            }

            $new = 0;
            foreach ($records as $record) {
                [$log, $first] = $store->record($connection, $adapter, $record, 'webhook');

                if ($first) {
                    $new++;
                    $store->dispatch($log);
                }
            }

            IntegrationConnection::query()->whereKey($connection->getKey())->update(['last_received_at' => now()]);

            return ApiResponse::data(['status' => 'accepted', 'received' => count($records), 'new' => $new, 'already_known' => count($records) - $new], 202);
        });
    }

    /**
     * Checks the signature when there is a secret, and says whether this exact signed request was already seen.
     *
     * @throws ApiException when the signature is missing, wrong or too old
     */
    private function replayed(Request $request, string $raw, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $timestamp = (string) $request->header('X-FormEzy-Timestamp', '');
        $given = (string) $request->header('X-FormEzy-Signature', '');

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            throw new ApiException('stale_timestamp', 'The request is missing its X-FormEzy-Timestamp, or it is more than five minutes off.', 401);
        }

        $expected = 'sha256='.hash_hmac('sha256', "{$timestamp}.{$raw}", $secret);

        if (! hash_equals($expected, $given)) {
            throw new ApiException('invalid_signature', 'The X-FormEzy-Signature does not match this request.', 401);
        }

        // the same signed request twice within the window is the same delivery: say yes, do nothing
        return ! Cache::add('webhook-seen:'.hash('sha256', $given.$timestamp), 1, self::TOLERANCE_SECONDS * 2);
    }

    /** @return array<array-key, mixed> */
    private function body(Request $request, string $raw): array
    {
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $form = $request->request->all();

        if ($form !== []) {
            return $form;
        }

        throw new ApiException('bad_request', 'Send the lead as JSON.', 400);
    }
}
