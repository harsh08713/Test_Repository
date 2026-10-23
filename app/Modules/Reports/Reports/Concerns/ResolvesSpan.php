<?php

namespace App\Modules\Reports\Reports\Concerns;

use App\Modules\Reports\Support\ReportInput;
use Carbon\CarbonImmutable;

/** The dates a report was asked for as real dates, with "from the beginning" and "until today" where the person left them empty. */
trait ResolvesSpan
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    protected function span(ReportInput $input): array
    {
        return [CarbonImmutable::parse($input->from ?? '2000-01-01')->startOfDay(), CarbonImmutable::parse($input->to ?? now()->toDateString())->startOfDay()];
    }
}
