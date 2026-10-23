<?php

namespace App\Modules\Integrations\Providers;

use InvalidArgumentException;

/** The lead providers the application knows, by key. Modules register theirs; nothing here holds request state. */
final class ProviderRegistry
{
    /** @var array<string, LeadProvider> */
    private array $providers = [];

    public function register(LeadProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    public function get(string $key): LeadProvider
    {
        return $this->providers[$key] ?? throw new InvalidArgumentException("Unknown lead provider [{$key}].");
    }

    /** @return array<string, LeadProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}
