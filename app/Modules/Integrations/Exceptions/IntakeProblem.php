<?php

namespace App\Modules\Integrations\Exceptions;

use RuntimeException;

/**
 * A delivery cannot become a lead until a **person** does something — the connection has no branch, the person it enters leads
 * as has been switched off, the standard source was deleted. Trying again cannot help, so the queue does not: the delivery stays
 * in the log as failed, with this message, and is retried by hand once the cause is put right.
 */
final class IntakeProblem extends RuntimeException {}
