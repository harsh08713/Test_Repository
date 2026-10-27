<?php

namespace App\Modules\Integrations\Services;

use App\Modules\Shared\Services\SettingsService;

/**
 * The websites a company has said may post leads from a visitor's browser (company setting `integrations.website_allowed_origins`, one address
 * per entry, separated by commas, spaces or new lines). An origin is `https://www.example.com` — scheme, host and port, nothing else — and is
 * compared exactly: no wildcards, and `http` is not `https`. Nothing listed means no browser is allowed (a server posting the form has no origin).
 */
final class WebsiteOrigins
{
    public const SETTING = 'integrations.website_allowed_origins';

    public function __construct(private readonly SettingsService $settings) {}

    /** @return list<string> */
    public function allowed(int $companyId): array
    {
        $raw = (string) $this->settings->get(self::SETTING, $companyId);

        return array_values(array_unique(array_filter(array_map(fn (string $entry) => self::normalise($entry), preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []))));
    }

    public function allows(int $companyId, string $origin): bool
    {
        $normal = self::normalise($origin);

        return $normal !== null && in_array($normal, $this->allowed($companyId), true);
    }

    /** `HTTPS://Www.Example.com:443/path` is not an origin; `https://www.example.com` is. Null for anything that is not a web origin. */
    public static function normalise(string $value): ?string
    {
        $parts = parse_url(trim($value));

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.strtolower($parts['host']).($port === null || $port === $default ? '' : ":{$port}");
    }
}
