<?php

namespace App\Modules\Shared\Policies;

use App\Models\User;
use App\Modules\Shared\Imports\ImporterRegistry;
use App\Modules\Shared\Models\ImportBatch;
use App\Modules\Shared\Tenancy\TenantContext;

/**
 * Who may see and run an import is decided by the importer itself: geography uses `geography.import`,
 * leads will use `lead.import`. The batch must also be in one of the user's companies.
 */
class ImportBatchPolicy
{
    public function __construct(private readonly ImporterRegistry $importers, private readonly TenantContext $tenant) {}

    public function view(User $user, ImportBatch $batch): bool
    {
        return $this->mayImport($user, $batch);
    }

    /** Check, confirm, retry, cancel. */
    public function manage(User $user, ImportBatch $batch): bool
    {
        return $this->mayImport($user, $batch);
    }

    private function mayImport(User $user, ImportBatch $batch): bool
    {
        $importer = $this->importers->find($batch->importer);

        return $importer !== null
            && $user->can($importer->permission())
            && $this->tenant->userCanAccessCompany($user, $batch->company_id);
    }
}
