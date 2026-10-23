<?php

namespace App\Modules\Shared\Services;

use App\Modules\Shared\Enums\ApprovalMode;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Exceptions\TenantAccessException;
use App\Modules\Shared\Models\Setting;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * Typed per-company settings with defaults from config('formezy.settings').
 * Secrets (API keys) are encrypted at rest and only ever returned by get() to server-side code.
 */
final class SettingsService
{
    /** @var array<int, array<string, Setting>> company id => key => row */
    private array $rows = [];

    public function __construct(private readonly TenantContext $tenant) {}

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return config('formezy.settings', []);
    }

    public function get(string $key, ?int $companyId = null): mixed
    {
        $definition = $this->definition($key);
        $row = $this->rowsFor($this->companyId($companyId))[$key] ?? null;

        if ($row === null || $row->value === null) {
            return $definition['default'] ?? null;
        }

        $raw = $row->is_encrypted ? Crypt::decryptString($row->value) : $row->value;

        return $this->cast($definition, $raw);
    }

    public function set(string $key, mixed $value, ?int $companyId = null): void
    {
        $definition = $this->definition($key);
        $companyId = $this->companyId($companyId);

        if (! $this->tenant->canAccessCompany($companyId)) {
            throw TenantAccessException::company($companyId);
        }

        $isSecret = ($definition['type'] ?? null) === 'secret';
        $stored = $value === null || $value === '' ? null : $this->serialize($definition, $value);

        Setting::updateOrCreate(
            ['company_id' => $companyId, 'key' => $key],
            [
                'value' => $stored !== null && $isSecret ? Crypt::encryptString($stored) : $stored,
                'is_encrypted' => $isSecret && $stored !== null,
            ],
        );

        unset($this->rows[$companyId]);
    }

    /** True when a secret has a stored value (the UI shows "saved" but never the value). */
    public function isSet(string $key, ?int $companyId = null): bool
    {
        $this->definition($key);

        return ($this->rowsFor($this->companyId($companyId))[$key]->value ?? null) !== null;
    }

    /** @param array<string, mixed> $definition */
    private function cast(array $definition, string $raw): mixed
    {
        return match ($definition['type']) {
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $raw,
            default => $raw,
        };
    }

    /** @param array<string, mixed> $definition */
    private function serialize(array $definition, mixed $value): string
    {
        return match ($definition['type']) {
            'bool' => $value ? '1' : '0',
            'int' => (string) (int) $value,
            'select' => array_key_exists((string) $value, $definition['options'])
                ? (string) $value
                : throw new InvalidArgumentException('Invalid option for setting.'),
            default => (string) $value,
        };
    }

    /** @return array<string, mixed> */
    private function definition(string $key): array
    {
        if (isset($this->definitions()[$key])) {
            return $this->definitions()[$key];
        }

        // `approvals.mode.<document type>` — one per document type, managed on the Approval Levels screen,
        // so they are generated rather than listed in config (and stay off the general settings page).
        if (preg_match('/^approvals\.mode\.(\w+)$/', $key, $match) === 1 && DocumentType::tryFrom($match[1]) !== null) {
            return [
                'type' => 'select',
                'options' => collect(ApprovalMode::cases())->mapWithKeys(fn (ApprovalMode $mode) => [$mode->value => $mode->label()])->all(),
                'default' => ApprovalMode::Sequential->value,
            ];
        }

        throw new InvalidArgumentException("Unknown setting [{$key}].");
    }

    private function companyId(?int $companyId): int
    {
        return $companyId
            ?? $this->tenant->activeCompanyId()
            ?? throw new InvalidArgumentException('No company in context for this setting.');
    }

    /** @return array<string, Setting> */
    private function rowsFor(int $companyId): array
    {
        return $this->rows[$companyId] ??= Setting::query()
            ->where('company_id', $companyId)
            ->get()
            ->keyBy('key')
            ->all();
    }
}
