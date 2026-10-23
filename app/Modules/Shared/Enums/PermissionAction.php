<?php

namespace App\Modules\Shared\Enums;

/** Columns of the permission matrix. `Comment` lets view-only users annotate a record. */
enum PermissionAction: string
{
    case View = 'view';
    case Create = 'create';
    case Edit = 'edit';
    case Delete = 'delete';
    case Approve = 'approve';
    case Export = 'export';
    case Import = 'import';
    case Comment = 'comment';
    /** Assign or transfer ownership (leads, deals, tasks, route plans...). */
    case Assign = 'assign';
    /** Change the configuration of a module (approval levels, number series, masters used as settings). */
    case Configure = 'configure';
    /** Read the audit history of a record. */
    case ViewAudit = 'view_audit';

    public function label(): string
    {
        return match ($this) {
            self::Assign => 'Assign / transfer',
            self::ViewAudit => 'View audit',
            default => ucfirst($this->value),
        };
    }

    public function permission(string $form): string
    {
        return $form.'.'.$this->value;
    }
}
