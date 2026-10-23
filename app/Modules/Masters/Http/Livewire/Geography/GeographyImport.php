<?php

namespace App\Modules\Masters\Http\Livewire\Geography;

use App\Modules\Shared\Http\Livewire\ImportWizard;
use Livewire\Attributes\Title;

/** Import districts, blocks and PIN codes from Tent Studio's spreadsheet. */
#[Title('Import geography')]
class GeographyImport extends ImportWizard
{
    protected function importerKey(): string
    {
        return 'geography';
    }

    protected function tabsView(): ?string
    {
        return 'masters.partials.geography-tabs';
    }
}
