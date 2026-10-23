<?php

namespace App\Modules\Shared\Enums;

/** The generic `status` column present on every master/transaction table. */
enum RecordStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
