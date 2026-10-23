<?php

namespace App\Modules\CRM\Support;

/** How the field-sales services store what a device told them: coordinates as fixed decimals, free text bounded, the channel known. */
final class FieldInput
{
    public static function coordinate(?float $value): string
    {
        return number_format((float) $value, 7, '.', '');
    }

    /** What a device sent as a position, kept as text (at most 80 characters) when it was not a position. */
    public static function raw(mixed $latitude, mixed $longitude): string
    {
        $text = fn (mixed $v): string => is_scalar($v) || $v === null ? (string) $v : gettype($v);

        return mb_substr($text($latitude).', '.$text($longitude), 0, 80);
    }

    public static function source(string $source): string
    {
        return in_array($source, ['web', 'mobile', 'api'], true) ? $source : 'web';
    }

    public static function clientInfo(?string $info): ?string
    {
        $info = $info === null ? null : trim($info);

        return $info === null || $info === '' ? null : mb_substr($info, 0, 255);
    }
}
