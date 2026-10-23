<?php

namespace App\Modules\Shared\Models\Concerns;

use App\Modules\Shared\Exceptions\TenantAccessException;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Defence against cross-tenant references (IDOR through a foreign key): a record may only point at a
 * parent that belongs to the same company, whatever id a tampered request supplied. Soft-deleted parents
 * count as missing. Forms validate this too; this guard covers every other code path.
 *
 * @mixin Model
 */
trait ValidatesParentCompany
{
    /**
     * Foreign-key attribute => the model it points to, for every parent that must share this record's company.
     *
     * @return array<string, class-string<Model>>
     */
    abstract protected function companyScopedParents(): array;

    public static function bootValidatesParentCompany(): void
    {
        static::saving(function (self $model) {
            $companyId = $model->getAttribute('company_id') ?? app(TenantContext::class)->activeCompanyId();

            foreach ($model->companyScopedParents() as $attribute => $related) {
                $id = $model->getAttribute($attribute);

                if ($id === null || ($model->exists && ! $model->isDirty($attribute))) {
                    continue;
                }

                $parentCompany = $related::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($id)->value('company_id');

                if ($parentCompany === null || $companyId === null || (int) $parentCompany !== (int) $companyId) {
                    throw TenantAccessException::mismatch("{$attribute} must refer to a record of the same company.");
                }
            }
        });
    }
}
