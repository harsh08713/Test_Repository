<?php

namespace App\Modules\Integrations\Providers;

/**
 * A provider whose leads are posted by a visitor's own browser (a form on the company's website), not by another company's server.
 * Two things follow and the hub treats them differently from a marketplace: the browser must be allowed to call us from the page it is on
 * (the company names its sites), and nobody is vouching for the sender, so a robot's submission is dropped before it is written down.
 */
interface BrowserForm
{
    /**
     * Does this record look like a robot's (a trap field a person never sees was filled in)?
     *
     * @param  array<string, mixed>  $record
     */
    public function isBot(array $record): bool;

    /** A short piece of HTML a company can paste into its page, pointed at its own form address. */
    public function snippet(string $address): string;
}
