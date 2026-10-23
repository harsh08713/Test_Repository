<?php

namespace App\Modules\CRM\Enums;

/**
 * The two kinds of lead. A **new** lead is somebody who might buy: a prospect with a name and a mobile number, worked
 * until it is qualified. An **existing** lead is an inquiry from a customer who is already in the ledger master: it points
 * at that approved customer, lists the items they asked for with the rate they expect, and goes through the approval
 * workflow. Both follow the same stages, follow-ups and hand-overs, and either converts to a deal.
 */
enum LeadType: string
{
    case New = 'new';
    case Existing = 'existing';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New lead',
            self::Existing => 'Existing customer',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::New => 'bg-slate-100 text-slate-700',
            self::Existing => 'bg-violet-100 text-violet-800',
        };
    }
}
