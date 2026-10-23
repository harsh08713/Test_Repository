<?php

use App\Modules\Sales\Services\QuotationService;
use Illuminate\Support\Carbon;

/*
 * Quotations over the API: read only, found only through the caller's own reach (another person's or company's id is a 404), every
 * figure the one stored on the revision.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');

    $this->acme = makeCompany('ACME', ['state_code' => '24']);
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD', ['state_code' => '24']);

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde, $this->bde2]);

    $this->bolt = makeUsableItem($this->acme);
    makeStandardRate($this->acme, $this->bolt, $this->ahd, '125.50');

    $this->quote = fn ($by, string $upTo = 'draft') => makeQuotation(
        $this->acme, $by,
        approveDocument($this->acme, makeDeal($this->acme, $by, makeLead($this->acme, $by, $this->ahd, [], [lineRow($this->bolt, '10', '')])), $by, $this->approver),
        [], null, $upTo, $this->approver,
    );
});

afterEach(fn () => Carbon::setTestNow());

it('lists the quotations a person may see, and nobody else’s', function () {
    $mine = ($this->quote)($this->bde, 'sent');
    $theirs = ($this->quote)($this->bde2);

    $response = apiCall('GET', '/api/v1/quotations', as: $this->bde)->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$mine->id]);

    $row = $response->json('data.0');
    expect($row['quotation_number'])->toBe('QT/AHD/26-27/00001')->and($row['revision_no'])->toBe(0)->and($row['state']['value'])->toBe('sent')
        ->and($row['supply_type'])->toBe('intra')->and($row['totals']['grand_total'])->toBe('1481.00')->and($row['totals']['cgst'])->toBe('112.95')
        ->and($row['valid_until'])->toBe('2026-11-11')->and($row)->not->toHaveKey('items');

    expect(collect(apiCall('GET', '/api/v1/quotations', as: $this->manager)->json('data'))->pluck('id')->sort()->values()->all())->toBe([$mine->id, $theirs->id]);
    expect(apiCall('GET', '/api/v1/quotations', as: $this->globexAdmin)->json('data'))->toBe([]);
});

it('shows one quotation with its lines, tax and history, and says 404 to anyone who may not see it', function () {
    $q = ($this->quote)($this->bde, 'sent');

    $data = apiCall('GET', "/api/v1/quotations/{$q->id}", as: $this->bde)->assertOk()->json('data');

    expect($data['display_number'])->toBe('QT/AHD/26-27/00001 · Rev 0')->and($data['items'])->toHaveCount(1)
        ->and($data['items'][0]['rate'])->toBe('125.5000')->and($data['items'][0]['gst_rate'])->toBe('18.00')->and($data['items'][0]['line_total'])->toBe('1480.90')
        ->and($data['place_of_supply']['gst_code'])->toBe('24')->and(collect($data['history'])->pluck('to')->all())->toBe(['sent', 'draft'])
        ->and($data['sent_at'])->toContain('+05:30');

    apiCall('GET', "/api/v1/quotations/{$q->id}", as: $this->bde2)->assertNotFound();
    apiCall('GET', "/api/v1/quotations/{$q->id}", as: $this->globexAdmin)->assertNotFound();
    apiCall('GET', '/api/v1/quotations/99999', as: $this->bde)->assertNotFound();
});

it('filters by state, deal and latest revision, and refuses what it does not understand', function () {
    $sent = ($this->quote)($this->bde, 'sent');
    $draft = ($this->quote)($this->bde);
    $rev1 = inCompany($this->acme, fn () => app(QuotationService::class)->revise($sent->fresh(), $this->bde));

    $ids = fn (string $query) => collect(apiCall('GET', "/api/v1/quotations{$query}", as: $this->bde)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids(''))->toBe([$draft->id, $rev1->id])                         // live: draft and draft (the sent one is superseded)
        ->and($ids('?state=superseded'))->toBe([$sent->id])
        ->and($ids('?state=all'))->toBe([$sent->id, $draft->id, $rev1->id])
        ->and($ids('?state=all&latest=1'))->toBe([$draft->id, $rev1->id])
        ->and($ids('?state=all&deal_id='.$sent->deal_id))->toBe([$sent->id, $rev1->id]);

    apiCall('GET', '/api/v1/quotations?state=sold', as: $this->bde)->assertStatus(422);
});

it('syncs by change time', function () {
    $q = ($this->quote)($this->bde);
    Carbon::setTestNow('2026-10-13 09:30:00');
    $later = ($this->quote)($this->bde);

    $since = urlencode('2026-10-13T00:00:00+05:30');
    $response = apiCall('GET', "/api/v1/quotations?updated_since={$since}", as: $this->bde)->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$later->id])->and($response->json('meta.server_time'))->not->toBeNull();
    expect($q->id)->not->toBe($later->id);
});

it('needs the quotation permission', function () {
    ($this->quote)($this->bde);

    apiCall('GET', '/api/v1/quotations', as: $this->telecaller)->assertForbidden();
    apiCall('GET', '/api/v1/quotations')->assertUnauthorized();
});

it('offers no way to change a quotation', function () {
    $q = ($this->quote)($this->bde);

    foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
        expect(apiCall($method, "/api/v1/quotations/{$q->id}", as: $this->bde)->status())->toBeIn([404, 405]);
    }
});
