<?php

namespace App\Modules\CRM\Enums;

/**
 * Where a lead stands: New → Contacted → Qualified, then Deal → Quotation → Won as it is converted (later phases), or Lost
 * at any open point.
 *
 * People move a lead between New, Contacted and Qualified, mark it Lost (with a reason) and re-open a lost one. Deal,
 * Quotation and Won are set by the system when the lead is converted — never by hand — so a lead cannot claim an order
 * that does not exist.
 */
enum LeadStage: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Deal = 'deal';
    case Quotation = 'quotation';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Qualified => 'Qualified',
            self::Deal => 'Deal',
            self::Quotation => 'Quotation',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::New => 'bg-slate-100 text-slate-700',
            self::Contacted => 'bg-sky-100 text-sky-800',
            self::Qualified => 'bg-indigo-100 text-indigo-800',
            self::Deal, self::Quotation => 'bg-amber-100 text-amber-800',
            self::Won => 'bg-emerald-100 text-emerald-800',
            self::Lost => 'bg-rose-100 text-rose-800',
        };
    }

    /** Still being worked: anything but Won or Lost. An open lead with the same mobile number is a duplicate. */
    public function isOpen(): bool
    {
        return $this !== self::Won && $this !== self::Lost;
    }

    /** Set only by a conversion (deal, quotation, order), never chosen by a person. */
    public function isSetBySystem(): bool
    {
        return $this === self::Deal || $this === self::Quotation || $this === self::Won;
    }

    /**
     * The stages a person may move a lead to from here. Lost is reached through "mark as lost" (it needs a reason) and
     * left through "re-open"; both are listed so one rule decides every manual move.
     *
     * @return list<self>
     */
    public function manualMoves(): array
    {
        return match ($this) {
            self::New => [self::Contacted, self::Qualified, self::Lost],
            self::Contacted => [self::Qualified, self::New, self::Lost],
            self::Qualified => [self::Contacted, self::Lost],
            self::Lost => [self::New, self::Contacted, self::Qualified],
            self::Deal, self::Quotation, self::Won => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->manualMoves(), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
