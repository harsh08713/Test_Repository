<?php

namespace App\Modules\Sales\Enums;

/**
 * The answer of the credit check an order carries: the customer's limit against what is already owed plus this order, as the company's
 * `credit_limit.behaviour` setting says to treat it.
 *
 *  - **NotChecked** — a draft that has not been checked yet.
 *  - **Ignored** — the company has switched the check off.
 *  - **NoLimit** — the customer has no credit limit on the ledger, so there is nothing to exceed.
 *  - **Ok** — within the limit.
 *  - **Warn** — over the limit, and the company's setting is to warn and allow: the order goes on, and the approver sees the warning.
 *  - **Blocked** — over the limit, and the company blocks: the order cannot be sent for approval.
 *  - **Overridden** — over the limit and blocked, but someone holding `credit_limit.override` allowed it, with a reason, audited.
 */
enum CreditResult: string
{
    case NotChecked = 'not_checked';
    case Ignored = 'ignored';
    case NoLimit = 'no_limit';
    case Ok = 'ok';
    case Warn = 'warn';
    case Blocked = 'blocked';
    case Overridden = 'overridden';

    public function label(): string
    {
        return match ($this) {
            self::NotChecked => 'Not checked yet',
            self::Ignored => 'Not checked (switched off)',
            self::NoLimit => 'No credit limit',
            self::Ok => 'Within the credit limit',
            self::Warn => 'Over the credit limit (allowed)',
            self::Blocked => 'Over the credit limit (blocked)',
            self::Overridden => 'Over the credit limit (allowed by an override)',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::NotChecked, self::Ignored, self::NoLimit => 'bg-slate-100 text-slate-700',
            self::Ok => 'bg-emerald-100 text-emerald-800',
            self::Warn => 'bg-amber-100 text-amber-800',
            self::Blocked => 'bg-rose-100 text-rose-800',
            self::Overridden => 'bg-indigo-100 text-indigo-800',
        };
    }

    /** May an order with this answer be sent for approval? */
    public function allowsSubmission(): bool
    {
        return $this !== self::Blocked && $this !== self::NotChecked;
    }
}
