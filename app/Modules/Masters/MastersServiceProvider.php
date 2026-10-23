<?php

namespace App\Modules\Masters;

use App\Modules\Masters\Imports\GeographyImporter;
use App\Modules\Masters\Models\AccountGroup;
use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\Brand;
use App\Modules\Masters\Models\Department;
use App\Modules\Masters\Models\Designation;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\ItemAlias;
use App\Modules\Masters\Models\ItemGroup;
use App\Modules\Masters\Models\ItemLocationParameter;
use App\Modules\Masters\Models\ItemStageEntry;
use App\Modules\Masters\Models\ItemUom;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\LedgerAddress;
use App\Modules\Masters\Models\LedgerBankDetail;
use App\Modules\Masters\Models\LedgerContact;
use App\Modules\Masters\Models\PaymentMethod;
use App\Modules\Masters\Models\PinCode;
use App\Modules\Masters\Models\PriceList;
use App\Modules\Masters\Models\Rate;
use App\Modules\Masters\Models\State;
use App\Modules\Masters\Models\Unit;
use App\Modules\Shared\Imports\ImporterRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Masters module: company, branches, chart of accounts, item groups, units, departments, geography. */
class MastersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Merged into the map enforced by the Shared provider.
        Relation::enforceMorphMap([
            'department' => Department::class,
            'designation' => Designation::class,
            'unit' => Unit::class,
            'account_group' => AccountGroup::class,
            'item_group' => ItemGroup::class,
            'state' => State::class,
            'district' => District::class,
            'block' => Block::class,
            'pin_code' => PinCode::class,
            'payment_method' => PaymentMethod::class,
            'ledger' => Ledger::class,
            'ledger_address' => LedgerAddress::class,
            'ledger_contact' => LedgerContact::class,
            'ledger_bank_detail' => LedgerBankDetail::class,
            'item' => Item::class,
            'item_uom' => ItemUom::class,
            'item_alias' => ItemAlias::class,
            'item_location_parameter' => ItemLocationParameter::class,
            'item_stage_entry' => ItemStageEntry::class,
            'price_list' => PriceList::class,
            'brand' => Brand::class,
            'rate' => Rate::class,
        ]);

        $this->app->make(ImporterRegistry::class)->register(new GeographyImporter);

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
    }
}
