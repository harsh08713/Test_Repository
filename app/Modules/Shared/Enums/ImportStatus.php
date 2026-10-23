<?php

namespace App\Modules\Shared\Enums;

/**
 * Life of one uploaded spreadsheet:
 *
 *   uploaded → checking → checked → queued → processing → completed
 *                  ↘ failed ↙                      ↘ failed (a failed import can be retried: re-running is idempotent)
 *   any idle state → cancelled
 */
enum ImportStatus: string
{
    case Uploaded = 'uploaded';
    case Checking = 'checking';
    case Checked = 'checked';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::Checking => 'Checking',
            self::Checked => 'Ready to import',
            self::Queued => 'Queued',
            self::Processing => 'Importing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** A job is (or is about to be) working on the batch: nothing else may touch it. */
    public function isBusy(): bool
    {
        return in_array($this, [self::Checking, self::Queued, self::Processing], true);
    }

    /** No further work will happen on its own. */
    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }
}
