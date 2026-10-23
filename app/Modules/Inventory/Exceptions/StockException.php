<?php

namespace App\Modules\Inventory\Exceptions;

use RuntimeException;

/** A stock entry the rules refuse; the message is written for the person who made it. */
class StockException extends RuntimeException {}
