<?php

namespace App\Modules\Masters\Enums;

/** The four primary natures every account group descends from. */
enum AccountNature: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
