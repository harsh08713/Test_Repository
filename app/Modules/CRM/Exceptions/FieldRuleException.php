<?php

namespace App\Modules\CRM\Exceptions;

use RuntimeException;

/** A business rule of the field-sales module said no (a locked plan, an off switch). The message is meant for the person. */
final class FieldRuleException extends RuntimeException {}
