<?php

namespace App\Modules\Reports\Exceptions;

use RuntimeException;

/** A report was asked for with filters the person may not use or that make no sense; `$problems` is filter key => sentence. */
final class InvalidReportFilters extends RuntimeException
{
    /** @param array<string, string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }
}
