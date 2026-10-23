<?php

namespace App\Modules\Sales\Exceptions;

use RuntimeException;

/**
 * Thrown inside the invoice generator's transaction when, once it holds the order's row lock, it finds the invoice has already been made —
 * which rolls the transaction back (so the invoice number it had taken is given back, and the series stays free of gaps) and tells the caller
 * to hand over the existing invoice instead.
 */
final class InvoiceAlreadyMade extends RuntimeException
{
    public function __construct(public readonly int $invoiceId)
    {
        parent::__construct("The invoice for this order has already been made (#{$invoiceId}).");
    }
}
