<?php

namespace App\Modules\Shared\Imports;

/** What happened (or, in a check, would happen) to one spreadsheet row. */
final readonly class RowOutcome
{
    /** @param  list<string>  $messages  reasons, for an error */
    private function __construct(
        public RowStatus $status,
        public string $detail,
        public array $messages = [],
    ) {}

    public static function created(string $detail): self
    {
        return new self(RowStatus::Created, $detail);
    }

    public static function updated(string $detail): self
    {
        return new self(RowStatus::Updated, $detail);
    }

    public static function unchanged(string $detail = 'Already up to date'): self
    {
        return new self(RowStatus::Unchanged, $detail);
    }

    /** @param  string|list<string>  $messages */
    public static function error(string|array $messages): self
    {
        return new self(RowStatus::Error, '', is_array($messages) ? $messages : [$messages]);
    }

    public function isError(): bool
    {
        return $this->status === RowStatus::Error;
    }
}
