<?php

namespace App\Modules\CRM\Contracts;

/**
 * Where "achieved" comes from. The default policy — which Sales Orders (Phase 12) will implement — is:
 *
 *  - only **approved** orders count; drafts, pending, rejected and **cancelled** orders do not, and an approved order that
 *    is later cancelled stops counting from the moment it is cancelled;
 *  - an order counts on its **order date**, for its **owner** (the salesperson credited with it);
 *  - the value is the order total **net of tax** (after discount, before GST), so a target in rupees means sales, not tax;
 *  - an order counts **in full when approved**, whether or not it has been invoiced, delivered or paid, and a partly
 *    invoiced or partly delivered order does not count less;
 *  - **returns and credit notes** reduce the period in which they are recorded, never an earlier one;
 *  - a quantity counts in each item's **base unit**, so an "item group" target is only meaningful when its items share a unit;
 *  - the item-group and branch of a target filter the order *lines* and the order's branch.
 *
 * Until orders exist the module is bound to NullAchievementSource, which reports nothing achieved.
 */
interface AchievementSource
{
    /**
     * What one salesperson achieved between two dates (both inclusive).
     *
     * @return array{value: string, quantity: string} value with 2 decimals, quantity with 3
     */
    public function achieved(int $userId, string $from, string $to, ?int $itemGroupId, ?int $locationId): array;

    /** Is this reading real orders? False for the placeholder, so screens can say "no sales orders yet" rather than "0%". */
    public function isLive(): bool;
}
