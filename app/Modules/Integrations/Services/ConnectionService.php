<?php

namespace App\Modules\Integrations\Services;

use App\Models\User;
use App\Modules\CRM\Services\SalesTeamService;
use App\Modules\Integrations\Models\IntegrationConnection;
use App\Modules\Integrations\Providers\ProviderRegistry;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sets a provider connection up. The single gate for connection data; it does not decide who may (the policy does) but it keeps
 * a connection from being switched on half-built:
 *
 *  - a mode the provider supports (Just Dial only pushes);
 *  - a branch the person saving works in, and — if leads are to go to a person rather than the pool — someone who works in it;
 *  - the saving person becomes who the leads are **entered as**, so they must hold `lead.create` and `lead.assign` (the rule that
 *    lets leads be left in the pool or given to someone is the same one every other lead entry obeys);
 *  - a provider that is pulled needs its API key saved first (in Company settings: the key is a secret, kept there and nowhere else).
 *
 * Input: ['mode', 'enabled', 'default_location_id', 'assigned_to', 'pull_interval_minutes', 'initial_lookback_hours'].
 */
final class ConnectionService
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly SettingsService $settings,
        private readonly TenantContext $tenant,
        private readonly SalesTeamService $teams,
    ) {}

    /** The company's connection to a provider, made (switched off) the first time it is asked for. */
    public function forProvider(string $provider): IntegrationConnection
    {
        $adapter = $this->providers->get($provider);

        $connection = IntegrationConnection::query()->where('provider', $provider)->first();

        if ($connection === null) {
            $connection = new IntegrationConnection;
            $connection->forceFill([
                'company_id' => (int) $this->tenant->activeCompanyId(),
                'provider' => $provider,
                'mode' => $adapter->canPull() ? 'pull' : 'webhook',
                'public_key' => IntegrationConnection::newPublicKey(),
            ])->save();
            $connection->refresh();
        }

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function save(IntegrationConnection $connection, array $input, User $actor): IntegrationConnection
    {
        $provider = $this->providers->get($connection->provider);
        $companyId = (int) $connection->company_id;

        $modes = array_values(array_filter([$provider->canPull() ? 'pull' : null, $provider->canPush() ? 'webhook' : null, $provider->canPull() && $provider->canPush() ? 'both' : null]));
        $enabled = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $data = Validator::make([
            'mode' => $input['mode'] ?? null,
            'default_location_id' => $this->blank($input['default_location_id'] ?? null),
            'assigned_to' => $this->blank($input['assigned_to'] ?? null),
            'pull_interval_minutes' => $this->blank($input['pull_interval_minutes'] ?? null) ?? $connection->pull_interval_minutes,
            'initial_lookback_hours' => $this->blank($input['initial_lookback_hours'] ?? null) ?? $connection->initial_lookback_hours,
        ], [
            'mode' => ['required', Rule::in($modes)],
            'default_location_id' => [$enabled ? 'required' : 'nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'],
            'pull_interval_minutes' => ['required', 'integer', 'min:'.max(5, $provider->minPullGapMinutes()), 'max:240'],
            'initial_lookback_hours' => ['required', 'integer', 'min:1', 'max:168'],
        ], [
            'mode.in' => "{$provider->label()} ".($provider->canPull() ? 'can be pulled, pushed to us, or both.' : 'sends its leads to us; there is nothing to pull.'),
            'default_location_id.required' => 'Choose the branch new leads go to before switching this on.',
        ], ['default_location_id' => 'branch', 'assigned_to' => 'owner', 'pull_interval_minutes' => 'how often to pull', 'initial_lookback_hours' => 'first pull looks back'])
            ->after(function ($validator) use ($actor, $enabled, $connection, $provider, $companyId, $input) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->checkPeople($validator, $actor, $companyId, $input);

                $mode = (string) ($input['mode'] ?? '');

                if ($enabled && in_array($mode, ['pull', 'both'], true) && ! $this->settings->isSet("integrations.{$connection->provider}_api_key", $companyId)) {
                    $validator->errors()->add('mode', "Save the {$provider->label()} API key under Company settings → Integrations before switching pulling on.");
                }
            })->validate();

        return DB::transaction(function () use ($connection, $data, $enabled, $actor) {
            $locked = IntegrationConnection::query()->lockForUpdate()->findOrFail($connection->getKey());

            $locked->forceFill([
                'mode' => $data['mode'],
                'enabled' => $enabled,
                'default_location_id' => $data['default_location_id'],
                'assigned_to' => $data['assigned_to'],
                'actor_user_id' => $actor->getKey(),
                'pull_interval_minutes' => (int) $data['pull_interval_minutes'],
                'initial_lookback_hours' => (int) $data['initial_lookback_hours'],
                'updated_by' => $actor->getKey(),
            ]);

            // switched on again after a spell off, or changed from one mode to another: start from a clean slate
            if ($locked->isDirty('enabled') && $enabled) {
                $locked->forceFill(['next_pull_at' => null, 'consecutive_failures' => 0, 'failure_kind' => null, 'last_error' => null]);
            }

            $locked->save();

            return $locked->refresh();
        });
    }

    /** A new, unguessable webhook address; the old one stops working at once. Audited. */
    public function regenerateAddress(IntegrationConnection $connection, User $actor): IntegrationConnection
    {
        $connection->forceFill(['public_key' => IntegrationConnection::newPublicKey(), 'updated_by' => $actor->getKey()])->save();

        activity('integrations')->causedBy($actor)->performedOn($connection)->event('address_regenerated')
            ->withProperties(['provider' => $connection->provider])->log('address_regenerated');

        return $connection->refresh();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function checkPeople(\Illuminate\Validation\Validator $validator, User $actor, int $companyId, array $input): void
    {
        $locationId = $this->blank($input['default_location_id'] ?? null);

        if ($locationId !== null) {
            $reach = $this->tenant->accessibleLocationIdsFor($actor, $companyId);
            $branch = Location::query()->find((int) $locationId);

            if ($branch === null || $branch->status !== 'active' || ($reach !== null && ! in_array((int) $branch->getKey(), $reach, true))) {
                $validator->errors()->add('default_location_id', 'Choose one of your active branches.');

                return;
            }
        }

        $ownerId = $this->blank($input['assigned_to'] ?? null);

        if ($ownerId !== null) {
            $owners = $locationId === null ? $this->teams->staff() : $this->teams->staffInBranch((int) $locationId);

            if (! array_key_exists((int) $ownerId, $owners)) {
                $validator->errors()->add('assigned_to', 'Choose an active user who works in that branch, or leave it for the pool.');
            }
        }

        if (! $actor->can('lead.create') || ! $actor->can('lead.assign')) {
            $validator->errors()->add('mode', 'Leads are entered as the person who saves this, so it must be someone who may create and hand out leads. Ask a manager or administrator.');
        }
    }

    private function blank(mixed $value): mixed
    {
        return $value === '' || $value === null ? null : $value;
    }
}
