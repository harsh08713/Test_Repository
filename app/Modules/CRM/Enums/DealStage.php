<?php

namespace App\Modules\CRM\Enums;

/**
 * Where a deal stands: Discovery → Negotiation, then Quotation → Won as the documents that follow are made (Phases 11–12), or
 * Lost at any open point.
 *
 * People move a deal between Discovery and Negotiation, mark it Lost (with a reason) and re-open a lost one. Quotation and
 * Won are set by the system when a quotation is made or an order is approved — never by hand — so a deal cannot claim a
 * document that does not exist. Each stage has the probability a deal starts with there; the salesperson may adjust it.
 */
enum DealStage: string
{
    case Discovery = 'discovery';
    case Negotiation = 'negotiation';
    case Quotation = 'quotation';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Discovery => 'Discovery',
            self::Negotiation => 'Negotiation',
            self::Quotation => 'Quotation',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Discovery => 'bg-sky-100 text-sky-800',
            self::Negotiation => 'bg-indigo-100 text-indigo-800',
            self::Quotation => 'bg-amber-100 text-amber-800',
            self::Won => 'bg-emerald-100 text-emerald-800',
            self::Lost => 'bg-rose-100 text-rose-800',
        };
    }

    /** Still being worked: anything but Won or Lost. */
    public function isOpen(): bool
    {
        return $this !== self::Won && $this !== self::Lost;
    }

    /** Set only by the document that follows (quotation, sales order), never chosen by a person. */
    public function isSetBySystem(): bool
    {
        return $this === self::Quotation || $this === self::Won;
    }

    /** The probability (per cent) a deal has on reaching this stage. */
    public function defaultProbability(): int
    {
        return match ($this) {
            self::Discovery => 10,
            self::Negotiation => 50,
            self::Quotation => 70,
            self::Won => 100,
            self::Lost => 0,
        };
    }

    /**
     * The stages a person may move a deal to from here. Lost is reached through "mark as lost" (it needs a reason) and left
     * through "re-open" (which needs its own permission); both are listed so one rule decides every manual move.
     *
     * @return list<self>
     */
    public function manualMoves(): array
    {
        return match ($this) {
            self::Discovery => [self::Negotiation, self::Lost],
            self::Negotiation => [self::Discovery, self::Lost],
            self::Quotation => [self::Lost],
            self::Lost => [self::Discovery, self::Negotiation],
            self::Won => [],
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
