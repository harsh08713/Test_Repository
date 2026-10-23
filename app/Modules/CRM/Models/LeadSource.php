<?php

namespace App\Modules\CRM\Models;

use App\Modules\CRM\Policies\CrmMasterPolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

/**
 * Where a lead came from: Reference, Website, Social Media, Tele-caller, IndiaMART, Just Dial, Field, Excel Import, Other,
 * and whatever else the company adds. The standard ones (`is_system`) are relied on by the importers and integrations, so
 * their codes are fixed and they cannot be deleted — only switched off.
 *
 * @property string $name
 * @property bool $is_system
 */
#[Fillable(['code', 'name', 'status', 'remarks'])]
#[UsePolicy(CrmMasterPolicy::class)]
class LeadSource extends TenantModel
{
    /** Codes other code refers to. */
    public const EXCEL = 'EXCEL';

    public const FIELD = 'FIELD';

    public const INDIAMART = 'INDIAMART';

    public const JUSTDIAL = 'JUSTDIAL';

    public const WEBSITE = 'WEBSITE';

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    protected function auditLogName(): string
    {
        return 'lead_sources';
    }
}
