<?php

namespace App\Modules\Integrations\Http\Livewire;

use App\Models\User;
use App\Modules\CRM\Services\SalesTeamService;
use App\Modules\Integrations\Jobs\PullProviderLeads;
use App\Modules\Integrations\Models\IntegrationConnection;
use App\Modules\Integrations\Models\LeadApiLog;
use App\Modules\Integrations\Providers\LeadProvider;
use App\Modules\Integrations\Providers\ProviderRegistry;
use App\Modules\Integrations\Services\ConnectionService;
use App\Modules\Integrations\Services\ProviderSync;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The lead marketplaces the company is connected to: for each, how it is wired (pull, push or both; which branch and owner; the
 * webhook address), how it is doing (configured / connected / degraded / disabled, the last success, the last error, what is waiting),
 * and the actions that run it — test the connection, pull now, a new webhook address. The API key and the signing secret are not here:
 * they are secrets and live only in Company settings, where nothing can read them back.
 */
#[Title('Integrations')]
class IntegrationList extends Component
{
    /** The provider whose settings are being edited ('' = none). */
    public string $editing = '';

    /** @var array<string, mixed> */
    public array $form = [];

    /** The provider whose "new address" is waiting for confirmation. */
    public string $confirming = '';

    /**
     * What the last action said, per provider: [kind, text].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public array $notes = [];

    public function mount(ConnectionService $connections, ProviderRegistry $providers): void
    {
        Gate::authorize('viewAny', IntegrationConnection::class);

        // the first visit makes the (switched off) connection rows; only someone who may set them up does that
        if (Auth::user()?->can('integration.edit')) {
            foreach (array_keys($providers->all()) as $key) {
                $connections->forProvider($key);
            }
        }
    }

    public function edit(string $provider): void
    {
        $connection = $this->connection($provider);
        Gate::authorize('update', $connection);

        $this->resetErrorBag();
        $this->confirming = '';
        $this->editing = $provider;
        $this->form = [
            'enabled' => $connection->enabled,
            'mode' => $connection->mode,
            'default_location_id' => (string) ($connection->default_location_id ?? ''),
            'assigned_to' => (string) ($connection->assigned_to ?? ''),
            'pull_interval_minutes' => (string) $connection->pull_interval_minutes,
            'initial_lookback_hours' => (string) $connection->initial_lookback_hours,
        ];
    }

    public function cancel(): void
    {
        $this->editing = '';
        $this->form = [];
        $this->resetErrorBag();
    }

    public function save(ConnectionService $connections): void
    {
        $connection = $this->connection($this->editing);
        Gate::authorize('update', $connection);
        $this->resetErrorBag();

        try {
            $saved = $connections->save($connection, $this->form, $this->actor());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError("form.{$field}", $messages[0]);
            }

            return;
        }

        $this->notes[$saved->provider] = ['success', $saved->enabled ? 'Saved. This connection is switched on.' : 'Saved. This connection is switched off.'];
        $this->cancel();
    }

    /** Call the provider once for the last hour's leads and say what came back; saves nothing. */
    public function test(string $provider, ProviderSync $sync): void
    {
        $connection = $this->connection($provider);
        Gate::authorize('configure', $connection);

        $result = $sync->test($connection);
        $this->notes[$provider] = [$result->succeeded() ? 'success' : ($result->outcome === 'failed' ? 'error' : 'info'), $result->message];
    }

    public function pullNow(string $provider): void
    {
        $connection = $this->connection($provider);
        Gate::authorize('configure', $connection);

        if (! $connection->enabled || ! $connection->pulls()) {
            $this->notes[$provider] = ['info', 'This connection is switched off or does not pull.'];

            return;
        }

        PullProviderLeads::dispatch((int) $connection->company_id, (int) $connection->getKey());
        $this->notes[$provider] = ['success', 'A pull has been queued. New leads appear in the log below within a minute or two.'];
    }

    public function askForNewAddress(string $provider): void
    {
        Gate::authorize('configure', $this->connection($provider));
        $this->confirming = $provider;
    }

    public function newAddress(ConnectionService $connections): void
    {
        $connection = $this->connection($this->confirming);
        Gate::authorize('configure', $connection);

        $connections->regenerateAddress($connection, $this->actor());
        $this->notes[$connection->provider] = ['success', 'There is a new webhook address. The old one has stopped working: give the new one to the provider.'];
        $this->confirming = '';
    }

    public function cancelNewAddress(): void
    {
        $this->confirming = '';
    }

    private function connection(string $provider): IntegrationConnection
    {
        abort_unless(app(ProviderRegistry::class)->has($provider), 404);

        return IntegrationConnection::query()->where('provider', $provider)->firstOrFail();
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function render(ProviderRegistry $providers, SettingsService $settings, SalesTeamService $teams): View
    {
        $user = $this->actor();
        $companyId = (int) app(TenantContext::class)->activeCompanyId();
        $canEdit = $user->can('integration.edit');
        $canRun = $user->can('integration.configure');

        $connections = IntegrationConnection::query()->get()->keyBy('provider');
        $counts = LeadApiLog::query()->toBase()->selectRaw('provider, status, count(*) as total')->groupBy('provider', 'status')->get()
            ->groupBy('provider')->map(fn ($rows) => $rows->pluck('total', 'status')->map(fn ($n) => (int) $n)->all());
        $today = LeadApiLog::query()->toBase()->where('created_at', '>=', now()->startOfDay())->selectRaw('provider, count(*) as total')->groupBy('provider')->pluck('total', 'provider');

        $rows = collect($providers->all())->map(fn (LeadProvider $provider, string $key) => [
            'provider' => $provider,
            'connection' => $connections->get($key),
            'counts' => $counts->get($key, []),
            'today' => (int) ($today[$key] ?? 0),
            'apiKeySet' => $settings->isSet("integrations.{$key}_api_key", $companyId),
            'signed' => $settings->isSet("integrations.{$key}_webhook_secret", $companyId),
        ])->filter(fn (array $row) => $row['connection'] !== null)->values();

        $branchId = (string) ($this->form['default_location_id'] ?? '');

        return view('integrations.list', [
            'rows' => $rows,
            'canEdit' => $canEdit,
            'canRun' => $canRun,
            'branches' => $this->editing === '' ? [] : Location::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'owners' => $this->editing === '' ? [] : (ctype_digit($branchId) ? $teams->staffInBranch((int) $branchId) : $teams->staff()),
        ]);
    }
}
