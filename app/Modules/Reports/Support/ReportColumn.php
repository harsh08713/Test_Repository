<?php

namespace App\Modules\Reports\Support;

use App\Modules\Shared\Support\Format;

/** One column of a report: what it is called and how its value is shown on the screen (formatted) and in an export (plain). */
final class ReportColumn
{
    public const TEXT = 'text';

    public const NUMBER = 'number';

    public const MONEY = 'money';

    public const PERCENT = 'percent';

    public const DATE = 'date';

    public const DATETIME = 'datetime';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = self::TEXT,
    ) {}

    public function isNumeric(): bool
    {
        return in_array($this->type, [self::NUMBER, self::MONEY, self::PERCENT], true);
    }

    /** The value as the screen and the PDF print it. */
    public function show(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($this->type) {
            self::MONEY => Format::number($value, 2),
            self::NUMBER => Format::number($value, 0),
            self::PERCENT => Format::number($value, 1).' %',
            self::DATE => Format::date($value),
            self::DATETIME => Format::dateTime($value),
            default => (string) $value,
        };
    }

    /** The value as a spreadsheet cell: a number stays a number, everything else is text (the binder keeps it inert). */
    public function cell(mixed $value): string|int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->type) {
            self::MONEY, self::PERCENT => (float) $value,
            self::NUMBER => is_numeric($value) ? $value + 0 : (string) $value,
            self::DATE => Format::date($value),
            self::DATETIME => Format::dateTime($value),
            default => (string) $value,
        };
    }
}
