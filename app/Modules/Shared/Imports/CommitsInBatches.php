<?php

namespace App\Modules\Shared\Imports;

/**
 * For an importer whose rows should be committed a few at a time rather than a whole chunk (thousands of rows) at once.
 *
 * A chunk's transaction holds every lock its rows take until it commits. That is right for a bulk load of reference data,
 * and wrong for rows that take a lock other people's work waits on: importing a lead takes its branch's number series,
 * and at some tens of milliseconds a lead, a chunk of 2,000 would keep anyone typing a lead into that branch waiting for
 * the best part of a minute — longer than the database is willing to wait.
 *
 * Reading the file is not affected (it is still read a chunk at a time); only how many rows share a transaction is.
 */
interface CommitsInBatches
{
    /** How many rows are committed together. Small enough that the locks are held for a second or so. */
    public function commitEvery(): int;
}
