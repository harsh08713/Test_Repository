<?php

namespace App\Modules\Shared\Imports;

use Illuminate\Support\Str;

/** One column of an import template: what it is called, whether it is mandatory and how to fill it in. */
final readonly class ImportColumn
{
    /**
     * @param  list<string>  $aliases  other headings people commonly use for the same thing ("Mobile No", "Phone"): a
     *                                 file that uses one is read without anybody having to rename its columns
     */
    public function __construct(
        public string $key,
        public string $heading,
        public bool $required,
        public string $description,
        public string $example = '',
        /** Keep as text in Excel (stops it turning codes into numbers or dates). */
        public bool $text = false,
        public array $aliases = [],
    ) {}

    /** Headings are matched ignoring case, spacing and punctuation: "PIN Code", "pin_code" and "Pin code *" all match. */
    public static function slug(string $heading): string
    {
        return Str::slug($heading, '_');
    }

    public function matches(string $heading): bool
    {
        $slug = self::slug($heading);

        if ($slug === '') {
            return false;
        }

        if ($slug === self::slug($this->heading)) {
            return true;
        }

        foreach ($this->aliases as $alias) {
            if ($slug === self::slug($alias)) {
                return true;
            }
        }

        return false;
    }
}
