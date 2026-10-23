<?php

namespace App\Modules\Shared\Imports;

use RuntimeException;

/** The uploaded file cannot be used at all. The message is written for the person who uploaded it. */
final class ImportFileException extends RuntimeException {}
