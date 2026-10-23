<?php

namespace App\Modules\Shared\Imports;

/** The importers the application knows about; each module registers its own from its service provider. */
final class ImporterRegistry
{
    /** @var array<string, Importer> */
    private array $importers = [];

    public function register(Importer $importer): void
    {
        $this->importers[$importer->key()] = $importer;
    }

    public function find(string $key): ?Importer
    {
        return $this->importers[$key] ?? null;
    }

    public function get(string $key): Importer
    {
        return $this->find($key) ?? abort(404);
    }

    /** @return list<Importer> */
    public function all(): array
    {
        return array_values($this->importers);
    }
}
