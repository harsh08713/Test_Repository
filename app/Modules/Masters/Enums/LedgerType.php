<?php

namespace App\Modules\Masters\Enums;

/**
 * The kind of party or account a ledger is. The type fixes which fields apply and which part of the chart of
 * accounts the ledger may be filed under (see groupRootCodes()).
 */
enum LedgerType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Bank = 'bank';
    case Cash = 'cash';
    case Expense = 'expense';
    case General = 'general';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function plural(): string
    {
        return match ($this) {
            self::Customer => 'Customers',
            self::Supplier => 'Suppliers',
            self::Bank => 'Banks',
            self::Cash => 'Cash',
            self::Expense => 'Expenses',
            self::General => 'General',
        };
    }

    /** Prefix of the auto-generated account code, e.g. CUS-00001. */
    public function codePrefix(): string
    {
        return match ($this) {
            self::Customer => 'CUS',
            self::Supplier => 'SUP',
            self::Bank => 'BNK',
            self::Cash => 'CSH',
            self::Expense => 'EXP',
            self::General => 'GEN',
        };
    }

    /**
     * Codes of the standard (system) account groups this type must be filed under — the group itself or any
     * group beneath it. Null means "any group". These are the codes `SeedCompanyDefaults` creates, and system
     * groups can be neither deleted nor moved, so they always exist.
     *
     * @return list<string>|null
     */
    public function groupRootCodes(): ?array
    {
        return match ($this) {
            self::Customer => ['1160'],                 // Sundry Debtors
            self::Supplier => ['2230'],                 // Sundry Creditors
            self::Bank => ['1110', '2310'],             // Bank Accounts, Bank OD Accounts
            self::Cash => ['1120'],                     // Cash-in-Hand
            self::Expense => ['4100', '4200', '4300'],  // Direct / Indirect Expenses, Purchase Accounts
            self::General => null,
        };
    }

    /** Does a party with commercial terms (credit days, payment preferences, addresses and contacts) sit behind it? */
    public function isParty(): bool
    {
        return $this === self::Customer || $this === self::Supplier;
    }

    /** May bank account details be recorded against this type? */
    public function allowsBankDetails(): bool
    {
        return $this !== self::Cash && $this !== self::Expense;
    }

    /** Are bank details part of what makes the ledger complete? */
    public function requiresBankDetails(): bool
    {
        return $this === self::Bank;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
