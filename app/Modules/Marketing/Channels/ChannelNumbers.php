<?php

namespace App\Modules\Marketing\Channels;

use App\Modules\Shared\Services\SettingsService;

/** A stored ten-digit mobile number in the international form providers want: country code (company setting, 91 by default) followed by the number, digits only. */
final class ChannelNumbers
{
    public static function international(string $tenDigits, int $companyId, SettingsService $settings): string
    {
        $code = preg_replace('/\D+/', '', (string) $settings->get('channels.country_code', $companyId)) ?: '91';

        return $code.preg_replace('/\D+/', '', $tenDigits);
    }
}
