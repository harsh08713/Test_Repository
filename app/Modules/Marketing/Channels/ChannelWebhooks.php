<?php

namespace App\Modules\Marketing\Channels;

use App\Modules\Shared\Services\SettingsService;
use Illuminate\Support\Str;

/** The address a provider reports to. It holds the company and an unguessable token (a company setting): a wrong or switched-off address is a plain 404. */
final class ChannelWebhooks
{
    public static function url(string $channel, int $companyId, SettingsService $settings): string
    {
        return route('webhooks.channel', ['company' => $companyId, 'channel' => $channel, 'token' => self::token($companyId, $settings) ?? '0']);
    }

    public static function token(int $companyId, SettingsService $settings): ?string
    {
        $token = (string) $settings->get('channels.webhook_token', $companyId);

        return $token === '' ? null : $token;
    }

    public static function newToken(): string
    {
        return Str::random(40);
    }
}
