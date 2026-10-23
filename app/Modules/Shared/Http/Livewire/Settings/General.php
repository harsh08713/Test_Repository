<?php

namespace App\Modules\Shared\Http\Livewire\Settings;

use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Per-company settings: approval behaviour, geofence, credit-limit policy, integration API keys. */
#[Title('Company settings')]
class General extends Component
{
    /** @var array<string, mixed> values keyed by setting key, with "." replaced by "__" (dots are Livewire path separators) */
    public array $values = [];

    /** @var array<string, string> new secret values; blank = keep the stored one */
    public array $secrets = [];

    /** @var array<string, bool> secrets to remove */
    public array $clear = [];

    public function mount(SettingsService $settings): void
    {
        Gate::authorize('setting.view');

        foreach ($settings->definitions() as $key => $definition) {
            if ($definition['type'] === 'secret') {
                continue;
            }

            $this->values[self::field($key)] = $settings->get($key);
        }
    }

    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function save(SettingsService $settings, TenantContext $tenant): void
    {
        Gate::authorize('setting.edit');

        $definitions = $settings->definitions();
        $rules = [];

        foreach ($definitions as $key => $definition) {
            $field = 'values.'.self::field($key);

            $rules += match ($definition['type']) {
                'bool' => [$field => ['boolean']],
                'int' => [$field => ['required', 'integer', 'min:'.($definition['min'] ?? 0), 'max:'.($definition['max'] ?? 1000000)]],
                'select' => [$field => ['required', Rule::in(array_keys($definition['options']))]],
                'secret' => ['secrets.'.self::field($key) => ['nullable', 'string', 'max:500']],
                default => [$field => ['nullable', 'string', 'max:255']],
            };
        }

        $this->validate($rules);

        $changes = ['old' => [], 'attributes' => []];
        $secretsChanged = [];

        foreach ($definitions as $key => $definition) {
            $field = self::field($key);

            if ($definition['type'] === 'secret') {
                if (! empty($this->clear[$field])) {
                    $settings->set($key, null);
                    $secretsChanged[] = "{$key} (cleared)";
                } elseif (filled($this->secrets[$field] ?? null)) {
                    $settings->set($key, $this->secrets[$field]);
                    $secretsChanged[] = "{$key} (updated)";
                }

                continue;
            }

            $old = $settings->get($key);
            $new = $this->values[$field] ?? $definition['default'];

            if ($old !== $new && ! ($definition['type'] === 'int' && (int) $old === (int) $new)) {
                $settings->set($key, $new);
                $changes['old'][$key] = $old;
                $changes['attributes'][$key] = $new;
            }
        }

        if ($changes['attributes'] !== [] || $secretsChanged !== []) {
            // Secret values are never written to the audit log — only the fact that they changed.
            activity('settings')
                ->causedBy(Auth::user())->event('updated')
                ->withProperties([...$changes, 'secrets' => $secretsChanged])
                ->tap(fn ($a) => $a->company_id = $tenant->activeCompanyId())
                ->log('updated');
        }

        $this->secrets = [];
        $this->clear = [];

        session()->flash('status', 'Settings saved.');
    }

    public function render(SettingsService $settings): View
    {
        $groups = collect($settings->definitions())
            ->map(fn ($definition, $key) => $definition + ['key' => $key, 'field' => self::field($key), 'is_set' => $definition['type'] === 'secret' ? $settings->isSet($key) : null])
            ->groupBy('group');

        return view('shared.settings.general', [
            'groups' => $groups,
            'canEdit' => Gate::allows('setting.edit'),
        ]);
    }
}
