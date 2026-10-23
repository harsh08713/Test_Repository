<?php

namespace App\Modules\Shared\Imports;

/**
 * For an import session with something to do once the whole file has been imported — telling people about what they
 * were given, say. Called once, after the last chunk of a real import has been committed; never after a check.
 *
 * It must not write business data (the import is over and each row has already been committed or rejected), and a
 * failure here is reported but does not undo the import.
 */
interface FinishesImport
{
    public function finished(): void;
}
