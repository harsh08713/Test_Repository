<?php

namespace App\Modules\Workspace\Enums;

enum SlaKind: string
{
    case FirstResponse = 'first_response';
    case Followup = 'followup';
    case Task = 'task';
    case QuotationResponse = 'quotation_response';
    case Approval = 'approval';

    public function label(): string
    {
        return match ($this) {
            self::FirstResponse => 'First response to a new lead',
            self::Followup => 'Planned follow-up done on time',
            self::Task => 'Task done by its deadline',
            self::QuotationResponse => 'A sent quotation answered by the customer',
            self::Approval => 'A document decided by its approver',
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::FirstResponse => 'First response',
            self::Followup => 'Follow-up',
            self::Task => 'Task',
            self::QuotationResponse => 'Quotation',
            self::Approval => 'Approval',
        };
    }

    /** Does a lead source narrow this kind (leads and the follow-ups on them)? */
    public function usesSource(): bool
    {
        return $this === self::FirstResponse || $this === self::Followup;
    }

    /** What the minutes of the policy are counted from. */
    public function counts(): string
    {
        return match ($this) {
            self::FirstResponse => 'after the lead was made',
            self::Followup => 'after the time the follow-up was planned for',
            self::Task => 'after the task’s deadline',
            self::QuotationResponse => 'after the quotation was sent',
            self::Approval => 'after the document was sent for approval',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $k) => [$k->value => $k->label()])->all();
    }
}
