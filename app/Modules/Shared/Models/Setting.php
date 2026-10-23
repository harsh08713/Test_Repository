<?php

namespace App\Modules\Shared\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Raw key/value row. Read and written through SettingsService, which handles typing, defaults and encryption.
 *
 * @property int $id
 * @property int $company_id
 * @property string $key
 * @property string|null $value
 * @property bool $is_encrypted
 */
#[Fillable(['company_id', 'key', 'value', 'is_encrypted'])]
class Setting extends Model
{
    protected function casts(): array
    {
        return ['is_encrypted' => 'boolean'];
    }
}
