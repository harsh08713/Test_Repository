<?php

namespace App\Modules\Shared\Enums;

/** How the levels of one approval submission are decided. Configured per company and document type. */
enum ApprovalMode: string
{
    /** Level 2 only becomes actionable after level 1 approved (default). */
    case Sequential = 'sequential';

    /** Every level is actionable at once, in any order; all must approve. */
    case Parallel = 'parallel';

    public function label(): string
    {
        return match ($this) {
            self::Sequential => 'In order (one level after another)',
            self::Parallel => 'In parallel (all levels at once)',
        };
    }

    public static function settingKey(DocumentType $type): string
    {
        return 'approvals.mode.'.$type->value;
    }
}
