<?php

namespace App\Modules\Masters\Exceptions;

use DomainException;

/** A unit conversion that cannot be done exactly as asked. The message is fit to show to the person who asked. */
final class UomException extends DomainException
{
    public static function unknownUnit(string $item, string $unit): self
    {
        return new self("{$item} is not set up to be counted in {$unit}: add it as a unit conversion on the item first.");
    }

    public static function invalidQuantity(string $given): self
    {
        return new self("\"{$given}\" is not a valid quantity: use a number of zero or more with at most 3 decimal places.");
    }

    public static function fractional(string $quantity, string $unit): self
    {
        return new self("{$quantity} {$unit} is not a whole number, and {$unit} cannot be split.");
    }
}
