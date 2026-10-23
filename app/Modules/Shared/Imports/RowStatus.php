<?php

namespace App\Modules\Shared\Imports;

enum RowStatus: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'New',
            self::Updated => 'Update',
            self::Unchanged => 'No change',
            self::Error => 'Error',
        };
    }
}
