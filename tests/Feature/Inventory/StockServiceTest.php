<?php

use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Notifications\StockExpiryNotification;
use App\Modules\Inventory\Services\ExpiryWatch;
use App\Modules\Inventory\Services\StockQuery;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Services\CostResolver;
use App\Modules\Masters\Services\ItemService;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * The stock ledger and its balances: moving average without rounding residue, no negative stock, batches and expiry, transfers that arrive at
 * the value that left, once-only postings, an append-only ledger and balances that can always be rebuilt from it.
 * Hand-calculated: 100 Nos at ₹10 and 50 at ₹16 → 150 at an average of ₹12 (₹1,800); taking 30 out takes ₹360.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->store = makeUser(['Inventory User'], [$this->acme], [$this->ahd]);   // works in Ahmedabad only
    $this->outsider = makeUser(['Company Admin'], [$this->globex]);
    $purchase = makeUser(['Purchase User'], [$this->acme]);

    $this->bolt = makeItem($this->acme, $purchase, ['item' => ['name' => 'MS Hex Bolt M10', 'standard_cost' => '4.50']], 'approved');   // 1 Box = 100 Nos
    $this->drug = makeItem($this->acme, $purchase, ['item' => ['name' => 'Paracetamol 500', 'standard_cost' => '1.20'], 'uoms' => [], 'inventory' => ['tracking' => 'batch', 'has_expiry' => true]], 'approved');

    $this->do = fn (callable $call) => inCompany($this->acme, $call);
    $this->stock = fn () => app(StockService::class);
    $this->receive = fn ($item, string $qty, string $cost, $location = null, ?Batch $batch = null, int $line = 1) => ($this->do)(
        fn () => ($this->stock)()->receive($item, ($location ?? $this->ahd)->id, $qty, $cost, $batch, 'test', 1, $line)
    );
    $this->batch = fn (string $no, ?string $expiry) => ($this->do)(function () use ($no, $expiry) {
        $b = new Batch;
        $b->forceFill(['company_id' => $this->acme->id, 'item_id' => $this->drug->id, 'batch_no' => $no, 'expiry_date' => $expiry, 'status' => 'active'])->save();

        return $b;
    });
    $this->balance = fn ($item, $location = null, ?Batch $batch = null) => DB::table('stock_balances')
        ->where(['item_id' => $item->id, 'location_id' => ($location ?? $this->ahd)->id, 'batch_key' => $batch?->id ?? 0])->first();
});

afterEach(fn () => Carbon::setTestNow());

// ---- moving average ---------------------------------------------------------------------------------------------------------------

it('keeps a moving average: receipts add value, issues take it out at the average', function () {
    ($this->receive)($this->bolt, '100', '10', null, null, 1);
    ($this->receive)($this->bolt, '50', '16', null, null, 2);

    $b = ($this->balance)($this->bolt);
    expect((string) $b->quantity)->toBe('150.000')->and((string) $b->value)->toBe('1800.00');

    $out = ($this->do)(fn () => ($this->stock)()->adjustOut($this->bolt, $this->ahd->id, '30', null, 'Short at the monthly count', $this->admin));
    expect((string) $out->quantity)->toBe('-30.000')->and((string) $out->unit_cost)->toBe('12.0000')->and((string) $out->value)->toBe('-360.00')
        ->and($out->movement_type)->toBe('adjustment_out')->and($out->created_by)->toBe($this->admin->id)->and($out->reason)->toBe('Short at the monthly count');

    $b = ($this->balance)($this->bolt);
    expect((string) $b->quantity)->toBe('120.000')->and((string) $b->value)->toBe('1440.00');
});

it('leaves no rounding residue when the last unit leaves', function () {
    ($this->receive)($this->bolt, '3', '3.3333');   // value 10.00 (9.9999 rounded)

    $outs = [];
    foreach (['1', '1', '1'] as $q) {
        $outs[] = (string) ($this->do)(fn () => ($this->stock)()->scrap($this->bolt, $this->ahd->id, $q, null, 'Damaged in the rack', $this->admin))->value;
    }

    $b = ($this->balance)($this->bolt);
    expect($outs)->toBe(['-3.33', '-3.34', '-3.33'])->and((string) $b->quantity)->toBe('0.000')->and((string) $b->value)->toBe('0.00');
});

it('refuses more than is on hand, and leaves the balance as it was', function () {
    ($this->receive)($this->bolt, '10', '5');

    expect(fn () => ($this->do)(fn () => ($this->stock)()->adjustOut($this->bolt, $this->ahd->id, '10.5', null, 'Count difference', $this->admin)))
        ->toThrow(StockException::class, 'Only 10 of MS Hex Bolt M10 is on hand');

    expect((string) ($this->balance)($this->bolt)->quantity)->toBe('10.000')->and(StockMovement::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(1);

    // the database refuses a negative balance too, whatever code tried
    expect(fn () => DB::table('stock_balances')->where('item_id', $this->bolt->id)->update(['quantity' => -1]))->toThrow(QueryException::class);
});

it('checks quantities, reasons and costs', function () {
    $s = fn () => ($this->stock)();

    expect(fn () => ($this->do)(fn () => $s()->adjustIn($this->bolt, $this->ahd->id, '0', null, null, 'Found', $this->admin)))->toThrow(StockException::class)
        ->and(fn () => ($this->do)(fn () => $s()->adjustIn($this->bolt, $this->ahd->id, '1.2345', null, null, 'Found', $this->admin)))->toThrow(StockException::class)
        ->and(fn () => ($this->do)(fn () => $s()->adjustIn($this->bolt, $this->ahd->id, '1', null, null, 'x', $this->admin)))->toThrow(StockException::class, 'say why')
        ->and(fn () => ($this->do)(fn () => $s()->adjustIn($this->bolt, $this->ahd->id, '1', 'abc', null, 'Found in the yard', $this->admin)))->toThrow(StockException::class, 'cost');
});

it('values stock found at the average, else at the standard cost, and asks for a cost when it has neither', function () {
    $in = ($this->do)(fn () => ($this->stock)()->adjustIn($this->bolt, $this->ahd->id, '10', null, null, 'Found in the yard', $this->admin));
    expect((string) $in->unit_cost)->toBe('4.5000')->and((string) $in->value)->toBe('45.00');   // no stock yet: the standard cost

    ($this->receive)($this->bolt, '10', '10');                                                       // average now (45 + 100) / 20 = 7.25
    $in = ($this->do)(fn () => ($this->stock)()->adjustIn($this->bolt, $this->ahd->id, '4', null, null, 'Found in the yard', $this->admin));
    expect((string) $in->unit_cost)->toBe('7.2500');

    $plain = makeItem($this->acme, makeUser(['Purchase User'], [$this->acme]), ['item' => ['name' => 'Uncosted washer'], 'uoms' => []], 'approved');
    expect(fn () => ($this->do)(fn () => ($this->stock)()->adjustIn($plain, $this->ahd->id, '1', null, null, 'Found in the yard', $this->admin)))
        ->toThrow(StockException::class, 'Enter a cost');
});

// ---- transfers ----------------------------------------------------------------------------------------------------------------------

it('moves stock between branches at the value that left', function () {
    ($this->receive)($this->bolt, '3', '3.3333');   // ₹10.00 for 3

    $moved = ($this->do)(fn () => ($this->stock)()->transfer($this->bolt, $this->ahd->id, $this->mum->id, '2', null, 'Mumbai ran short', $this->admin));

    expect((string) $moved['out']->value)->toBe('-6.67')->and((string) $moved['in']->value)->toBe('6.67')->and($moved['in']->movement_type)->toBe('transfer_in')
        ->and((string) ($this->balance)($this->bolt)->value)->toBe('3.33')->and((string) ($this->balance)($this->bolt, $this->mum)->value)->toBe('6.67')
        ->and((string) ($this->balance)($this->bolt, $this->mum)->quantity)->toBe('2.000');

    expect(fn () => ($this->do)(fn () => ($this->stock)()->transfer($this->bolt, $this->ahd->id, $this->ahd->id, '1', null, 'Nowhere', $this->admin)))->toThrow(StockException::class);
});

it('keeps a person to the branches they work in', function () {
    ($this->receive)($this->bolt, '10', '5', $this->mum);

    expect(fn () => ($this->do)(fn () => ($this->stock)()->adjustOut($this->bolt, $this->mum->id, '1', null, 'Count difference', $this->store)))
        ->toThrow(StockException::class, 'branch you do not work in')
        ->and(fn () => ($this->do)(fn () => ($this->stock)()->transfer($this->bolt, $this->mum->id, $this->ahd->id, '1', null, 'Pull it over', $this->store)))
        ->toThrow(StockException::class, 'branch you do not work in');
});

// ---- batches and expiry -----------------------------------------------------------------------------------------------------------

it('needs a batch for an item tracked by batch, and refuses one for an item that is not', function () {
    $lot = ($this->batch)('B-1', '2027-03-31');

    expect(fn () => ($this->receive)($this->drug, '10', '1'))->toThrow(StockException::class, 'tracked by batch')
        ->and(fn () => ($this->receive)($this->bolt, '10', '1', null, $lot))->toThrow(StockException::class, 'not tracked by batch');

    ($this->receive)($this->drug, '10', '1.10', null, $lot);
    expect((string) ($this->balance)($this->drug, null, $lot)->quantity)->toBe('10.000');
});

it('lets an expired batch be scrapped but not moved', function () {
    $old = ($this->batch)('OLD-1', '2026-10-01');
    ($this->receive)($this->drug, '20', '1', null, $old);

    expect(fn () => ($this->do)(fn () => ($this->stock)()->transfer($this->drug, $this->ahd->id, $this->mum->id, '5', $old, 'Send it on', $this->admin)))
        ->toThrow(StockException::class, 'expired');

    $scrap = ($this->do)(fn () => ($this->stock)()->scrap($this->drug, $this->ahd->id, '20', $old, 'Expired on 1 October', $this->admin));
    expect((string) $scrap->value)->toBe('-20.00');
});

it('marks expired batches and warns once about the ones about to expire, only to people who see stock in that company', function () {
    Notification::fake();
    $sales = makeUser(['BDE / Field Salesperson'], [$this->acme]);

    $soon = ($this->batch)('SOON', '2026-10-20');       // within 30 days
    $later = ($this->batch)('LATER', '2027-06-30');     // not yet
    $gone = ($this->batch)('GONE', '2026-10-05');       // already past
    $empty = ($this->batch)('EMPTY', '2026-10-15');     // no stock left: ignored
    foreach ([$soon, $later, $gone] as $i => $b) {
        ($this->receive)($this->drug, '10', '2', null, $b, 10 + $i);
    }

    $result = app(ExpiryWatch::class)->run();

    expect($result)->toBe(['expired' => 1, 'warned' => 1])
        ->and($gone->fresh()->status)->toBe('expired')->and($soon->fresh()->status)->toBe('active')
        ->and($soon->fresh()->expiry_alerted_at)->not->toBeNull()->and($empty->fresh()->expiry_alerted_at)->toBeNull();

    Notification::assertSentTo([$this->admin, $this->store], StockExpiryNotification::class, fn ($n) => $n->batchNo === 'SOON' && $n->value === '₹ 20.00');
    Notification::assertNotSentTo([$sales, $this->outsider], StockExpiryNotification::class);

    expect(app(ExpiryWatch::class)->run())->toBe(['expired' => 0, 'warned' => 0]);   // a second run tells nobody again
    Notification::assertSentToTimes($this->admin, StockExpiryNotification::class, 1);
});

it('sends no warning when the company has turned it off', function () {
    Notification::fake();
    ($this->receive)($this->drug, '10', '2', null, ($this->batch)('SOON', '2026-10-20'));
    inCompany($this->acme, fn () => app(SettingsService::class)->set('inventory.expiry_warning_days', 0));

    expect(app(ExpiryWatch::class)->run()['warned'])->toBe(0);
    Notification::assertNothingSent();
});

it('works out the value of stock about to expire', function () {
    ($this->receive)($this->drug, '10', '2', null, ($this->batch)('SOON', '2026-10-20'), 1);
    ($this->receive)($this->drug, '5', '3', null, ($this->batch)('GONE', '2026-10-05'), 2);
    ($this->receive)($this->drug, '7', '4', null, ($this->batch)('LATER', '2027-06-30'), 3);

    $this->actingAs($this->admin);
    expect(app(StockQuery::class)->expiryExposure(30))->toBe('35.00')            // 20 + 15
        ->and(app(StockQuery::class)->expiryExposure(365))->toBe('63.00');       // and the later one
});

// ---- once only, append-only, rebuildable -------------------------------------------------------------------------------------------

it('posts a document line once, however often it is asked, with the unique key as the backstop', function () {
    $first = ($this->receive)($this->bolt, '10', '5');
    $again = ($this->receive)($this->bolt, '10', '5');

    expect($again->id)->toBe($first->id)->and(StockMovement::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(1)
        ->and((string) ($this->balance)($this->bolt)->quantity)->toBe('10.000');

    $row = (array) DB::table('stock_movements')->where('id', $first->id)->first();
    unset($row['id']);
    expect(fn () => DB::table('stock_movements')->insert($row))->toThrow(UniqueConstraintViolationException::class);
});

it('never changes or removes a movement', function () {
    $m = ($this->receive)($this->bolt, '10', '5');

    expect(fn () => $m->forceFill(['quantity' => '99'])->save())->toThrow(LogicException::class)
        ->and(fn () => $m->delete())->toThrow(LogicException::class);
});

it('rebuilds the balances from the ledger and says which were wrong', function () {
    ($this->receive)($this->bolt, '100', '10', null, null, 1);
    ($this->do)(fn () => ($this->stock)()->transfer($this->bolt, $this->ahd->id, $this->mum->id, '40', null, 'Rebalance', $this->admin));

    expect(app(StockService::class)->rebuildBalances($this->acme->id))->toBe(0);

    DB::table('stock_balances')->where('location_id', $this->mum->id)->update(['quantity' => 1, 'value' => 1]);
    expect(app(StockService::class)->rebuildBalances($this->acme->id))->toBe(1)
        ->and((string) ($this->balance)($this->bolt, $this->mum)->quantity)->toBe('40.000')->and((string) ($this->balance)($this->bolt, $this->mum)->value)->toBe('400.00');

    $this->artisan('stock:rebuild-balances')->assertSuccessful();
});

it('keeps each company and branch to its own stock', function () {
    ($this->receive)($this->bolt, '10', '5', $this->ahd, null, 1);
    ($this->receive)($this->bolt, '7', '5', $this->mum, null, 2);

    $this->actingAs($this->store);   // Ahmedabad only
    expect(StockBalance::query()->sum('quantity'))->toEqual('10.000')->and(app(StockQuery::class)->onHand($this->bolt))->toBe('10.000');

    $this->actingAs($this->outsider);
    expect(StockBalance::query()->count())->toBe(0)->and(StockMovement::query()->count())->toBe(0);
});

// ---- cost for documents --------------------------------------------------------------------------------------------------------------

it('costs a sale at the moving average when there is stock, in the unit sold, and at standard cost otherwise', function () {
    $resolver = app(CostResolver::class);
    $box = Unit::withoutGlobalScopes(Tenancy::SCOPES)->findOrFail(unitId($this->acme, 'BOX'));
    $bolt = $this->bolt->fresh(['baseUnit', 'uoms']);

    expect($resolver->unitCost($bolt, $bolt->baseUnit, $this->ahd->id))->toBe(['unit_cost' => '4.5000', 'source' => 'standard']);   // no stock yet

    ($this->receive)($this->bolt, '100', '10', null, null, 1);
    ($this->receive)($this->bolt, '50', '16', null, null, 2);

    expect($resolver->unitCost($bolt, $bolt->baseUnit, $this->ahd->id))->toBe(['unit_cost' => '12.0000', 'source' => 'moving_average'])
        ->and($resolver->unitCost($bolt, $box, $this->ahd->id))->toBe(['unit_cost' => '1200.0000', 'source' => 'moving_average'])
        ->and($resolver->unitCost($bolt, $bolt->baseUnit, $this->mum->id))->toBe(['unit_cost' => '12.0000', 'source' => 'moving_average']);   // none in Mumbai: the company's

    inCompany($this->acme, fn () => app(SettingsService::class)->set('costing.method', 'standard'));
    expect($resolver->unitCost($bolt, $bolt->baseUnit, $this->ahd->id))->toBe(['unit_cost' => '4.5000', 'source' => 'standard']);
});

// ---- tracking on the item -----------------------------------------------------------------------------------------------------------

it('keeps an expiring item tracked by batch, and its tracking fixed once stock has moved', function () {
    $purchase = makeUser(['Purchase User'], [$this->acme]);

    expect(fn () => makeItem($this->acme, $purchase, ['item' => ['name' => 'Expiring loose'], 'uoms' => [], 'inventory' => ['tracking' => 'none', 'has_expiry' => true]], 'inventory'))
        ->toThrow(ValidationException::class);

    // put the bolt back through Inventory after stock has moved, and try to switch it to batches
    ($this->receive)($this->bolt, '1', '5');
    $inventory = makeUser(['Inventory User'], [$this->acme]);
    DB::table('items')->where('id', $this->bolt->id)->update(['workflow_stage' => 'inventory_pending', 'approval_status' => 'draft']);

    expect(fn () => inCompany($this->acme, fn () => app(ItemService::class)->saveInventoryStage($this->bolt->fresh(), inventoryInput('MS Hex Bolt M10', ['inventory' => ['tracking' => 'batch']]), $inventory)))
        ->toThrow(ValidationException::class);
});
