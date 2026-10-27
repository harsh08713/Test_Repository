<?php

use App\Modules\CRM\Enums\LeadChannel;
use App\Modules\Integrations\Exceptions\ProviderException;
use App\Modules\Integrations\Providers\IndiaMartProvider;
use App\Modules\Integrations\Providers\JustDialProvider;
use App\Modules\Integrations\Providers\ProviderRegistry;
use App\Modules\Integrations\Support\PayloadRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
 * Contract tests: the adapters against the shapes the providers document (IndiaMART) or are known to send (Just Dial). Nothing
 * here touches the network — the HTTP client is faked — and nothing touches the database: an adapter knows only its wire format.
 * That the shapes match a *live* account is pending until credentials exist (see docs/integrations.md).
 */

const IM_KEY = 'imkey-SECRET-0123456789';

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 10:00:00');   // Asia/Kolkata

    $this->im = new IndiaMartProvider;
    $this->jd = new JustDialProvider;
    $this->window = fn () => [now()->subHour(), now()];
});

afterEach(fn () => Carbon::setTestNow());

// ---- IndiaMART: asking ----------------------------------------------------------------------------------

it('asks IndiaMART the way its Pull API documents: key, and an IST window written day-month-year', function () {
    Http::fake([IndiaMartProvider::URL.'*' => Http::response(indiaMartAnswer([indiaMartRecord()]))]);

    [$from, $to] = ($this->window)();
    $records = $this->im->fetch(IM_KEY, $from, $to);

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://mapi.indiamart.com/wservce/crm/crmListing/v2/')
            && $query === ['glusr_crm_key' => IM_KEY, 'start_time' => '12-Oct-202609:00:00', 'end_time' => '12-Oct-202610:00:00'];
    });
    expect($records)->toHaveCount(1)->and($records[0]['UNIQUE_QUERY_ID'])->toBe('2026101200001');
});

it('writes the window in IST whatever zone it was built in', function () {
    Http::fake([IndiaMartProvider::URL.'*' => Http::response(indiaMartAnswer([]))]);

    $this->im->fetch(IM_KEY, Carbon::parse('2026-10-12 03:30:00', 'UTC'), Carbon::parse('2026-10-12 04:30:00', 'UTC'));

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['start_time'] === '12-Oct-202609:00:00' && $query['end_time'] === '12-Oct-202610:00:00';
    });
});

it('reads each documented answer for what it means', function (int $code, ?string $kind, int $wait) {
    Http::fake([IndiaMartProvider::URL.'*' => Http::response(['CODE' => $code, 'STATUS' => 'FAILURE', 'MESSAGE' => 'x', 'TOTAL_RECORDS' => '0', 'RESPONSE' => []])]);

    [$from, $to] = ($this->window)();

    if ($kind === null) {
        expect($this->im->fetch(IM_KEY, $from, $to))->toBe([]);

        return;
    }

    try {
        $this->im->fetch(IM_KEY, $from, $to);
        $thrown = null;
    } catch (ProviderException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()->and($thrown->kind)->toBe($kind)->and($thrown->retryAfterMinutes)->toBe($wait)
        ->and($thrown->getMessage())->not->toContain(IM_KEY);
})->with([
    '204 no leads' => [204, null, 0],
    '429 too often' => [429, 'rate_limited', 16],     // "more than 5 hits a minute blocks the key for 15 minutes"
    '401 wrong or expired key' => [401, 'auth', 60],
    '400 bad range' => [400, 'invalid', 60],
    '500 their side' => [500, 'unavailable', 15],
    '503 something else' => [503, 'unavailable', 15],
]);

it('treats an HTTP failure without a body the same way, by status', function () {
    Http::fake([IndiaMartProvider::URL.'*' => Http::response('Bad gateway', 502)]);

    [$from, $to] = ($this->window)();

    expect(fn () => $this->im->fetch(IM_KEY, $from, $to))->toThrow(ProviderException::class, 'code 502');
});

it('never lets the key escape in the message when the network fails', function () {
    // the real client's message names the address, and the address carries the key
    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host for https://mapi.indiamart.com/?glusr_crm_key='.IM_KEY));

    [$from, $to] = ($this->window)();

    try {
        $this->im->fetch(IM_KEY, $from, $to);
        $thrown = null;
    } catch (ProviderException $e) {
        $thrown = $e;
    }

    expect($thrown?->kind)->toBe('unavailable')->and($thrown->getMessage())->not->toContain(IM_KEY)->not->toContain('glusr_crm_key')->not->toContain('cURL');
});

it('keeps to what IndiaMART permits', function () {
    expect($this->im->minPullGapMinutes())->toBe(5)->and($this->im->maxWindowDays())->toBe(7)
        ->and($this->im->canPull())->toBeTrue()->and($this->im->canPush())->toBeTrue()
        ->and($this->im->channel())->toBe(LeadChannel::IndiaMart)->and($this->im->sourceCode())->toBe('INDIAMART');
});

// ---- IndiaMART: reading a lead -----------------------------------------------------------------------------

it('reads a full IndiaMART enquiry', function () {
    $lead = $this->im->normalise(indiaMartRecord());

    expect($lead->providerLeadId)->toBe('2026101200001')
        ->and($lead->name)->toBe('Shree Ganesh Traders')->and($lead->contactPerson)->toBe('Meera Shah')
        ->and($lead->mobile)->toBe('+91-9876543210')->and($lead->email)->toBe('Meera@Example.test')
        ->and($lead->city)->toBe('Ahmedabad')->and($lead->state)->toBe('Gujarat')->and($lead->pinCode)->toBe('380001')
        ->and($lead->product)->toBe('Hex Bolt M10')->and($lead->kind)->toBe('Direct enquiry')->and($lead->countryIso)->toBe('IN')
        ->and($lead->message)->toBe("Need 5000 pcs.\nPlease quote.")          // the literal \n the provider writes becomes a line break
        ->and($lead->receivedAt->toDateTimeString())->toBe('2026-10-12 08:45:10')
        ->and($lead->namePlaceholder)->toBeFalse();
});

it('copes with the minimal enquiry the provider documents: an id, an e-mail, and "IndiaMART Buyer"', function () {
    $lead = $this->im->normalise(['UNIQUE_QUERY_ID' => 'Q9', 'SENDER_NAME' => 'IndiaMART Buyer', 'SENDER_EMAIL' => 'someone@example.test']);

    expect($lead->name)->toBeNull()->and($lead->contactPerson)->toBeNull()->and($lead->namePlaceholder)->toBeTrue()
        ->and($lead->mobile)->toBeNull()->and($lead->email)->toBe('someone@example.test')->and($lead->receivedAt)->toBeNull();
});

it('names the buyer by company first, then by person', function () {
    expect($this->im->normalise(['UNIQUE_QUERY_ID' => 'a', 'SENDER_NAME' => 'Meera Shah'])->name)->toBe('Meera Shah')
        ->and($this->im->normalise(['UNIQUE_QUERY_ID' => 'a', 'SENDER_NAME' => 'Meera Shah', 'SENDER_COMPANY' => 'Shree Ganesh'])->name)->toBe('Shree Ganesh');
});

it('labels every query type, and leaves an unknown one as it came', function () {
    $kind = fn (string $type) => $this->im->normalise(['UNIQUE_QUERY_ID' => 'a', 'QUERY_TYPE' => $type])->kind;

    expect($kind('W'))->toBe('Direct enquiry')->and($kind('B'))->toBe('Buy-lead')->and($kind('P'))->toBe('Phone enquiry (PNS)')
        ->and($kind('BIZ'))->toBe('Catalogue view')->and($kind('WA'))->toBe('WhatsApp enquiry')->and($kind('XYZ'))->toBe('XYZ');
});

it('ignores empty-looking values and an unreadable time', function () {
    $lead = $this->im->normalise(['UNIQUE_QUERY_ID' => 'a', 'SENDER_CITY' => 'null', 'SENDER_STATE' => ' ', 'SENDER_PINCODE' => 'NA', 'QUERY_TIME' => 'last Tuesday-ish']);

    expect($lead->city)->toBeNull()->and($lead->state)->toBeNull()->and($lead->pinCode)->toBeNull()->and($lead->receivedAt)->toBeNull();
});

it('finds the leads inside whatever IndiaMART pushes', function () {
    $one = indiaMartRecord(['UNIQUE_QUERY_ID' => 'P1']);
    $two = indiaMartRecord(['UNIQUE_QUERY_ID' => 'P2']);
    $ids = fn (array $body) => array_map(fn ($r) => $r['UNIQUE_QUERY_ID'], $this->im->extractRecords($body));

    expect($ids($one))->toBe(['P1'])                                              // the bare lead
        ->and($ids(['CODE' => 200, 'STATUS' => 'SUCCESS', 'RESPONSE' => $one]))->toBe(['P1'])   // wrapped, one
        ->and($ids(['CODE' => 200, 'RESPONSE' => [$one, $two]]))->toBe(['P1', 'P2'])             // wrapped, several
        ->and($ids([$one, $two]))->toBe(['P1', 'P2'])                                             // a bare list
        ->and($ids(['RESPONSE' => 'oops']))->toBe([])->and($ids(['hello' => 'world']))->toBe([])->and($ids([]))->toBe([]);
});

// ---- Just Dial -----------------------------------------------------------------------------------------------

it('reads a Just Dial push', function () {
    $lead = $this->jd->normalise(justDialRecord());

    expect($lead->providerLeadId)->toBe('JD-77001')
        ->and($lead->name)->toBe('Patel Hardware')->and($lead->contactPerson)->toBe('Mr Ravi Patel')
        ->and($lead->mobile)->toBe('9825012345')->and($lead->email)->toBe('ravi@example.test')
        ->and($lead->product)->toBe('Fasteners')->and($lead->city)->toBe('Naroda')->and($lead->pinCode)->toBe('380001')
        ->and($lead->kind)->toBe('Enquiry')->and($lead->receivedAt->toDateTimeString())->toBe('2026-10-12 09:15:30')
        ->and($lead->message)->toBe('Looked for: Fasteners. Area: Naroda. Branch area: Ahmedabad.')
        ->and($lead->doNotCall)->toBeFalse()->and($lead->namePlaceholder)->toBeFalse();
});

it('names a Just Dial lead by person when there is no company, and flags the do-not-call register', function () {
    $lead = $this->jd->normalise(justDialRecord(['company' => '', 'dncmobile' => '1']));

    expect($lead->name)->toBe('Mr Ravi Patel')->and($lead->contactPerson)->toBeNull()->and($lead->doNotCall)->toBeTrue();
    expect($this->jd->normalise(justDialRecord(['dncmobile' => '0', 'dncphone' => 'yes']))->doNotCall)->toBeTrue();
    expect($this->jd->normalise(['leadid' => 'x'])->namePlaceholder)->toBeTrue();
});

it('finds the leads inside whatever Just Dial pushes', function () {
    $ids = fn (array $body) => array_map(fn ($r) => $r['leadid'], $this->jd->extractRecords($body));

    expect($ids(justDialRecord(['leadid' => 'A'])))->toBe(['A'])
        ->and($ids(['leads' => [justDialRecord(['leadid' => 'A']), justDialRecord(['leadid' => 'B'])]]))->toBe(['A', 'B'])
        ->and($ids(['data' => justDialRecord(['leadid' => 'C'])]))->toBe(['C'])
        ->and($ids([justDialRecord(['leadid' => 'D'])]))->toBe(['D'])
        ->and($ids(['unrelated' => true]))->toBe([]);
});

it('says plainly that Just Dial has nothing to pull', function () {
    expect($this->jd->canPull())->toBeFalse()->and($this->jd->canPush())->toBeTrue()
        ->and(fn () => $this->jd->fetch('k', now()->subHour(), now()))->toThrow(ProviderException::class, 'nothing to pull');
});

it('knows its providers by key', function () {
    $registry = app(ProviderRegistry::class);

    expect(array_keys($registry->all()))->toBe(['indiamart', 'justdial', 'website'])
        ->and($registry->get('indiamart'))->toBeInstanceOf(IndiaMartProvider::class)->and($registry->has('zomato'))->toBeFalse()
        ->and(fn () => $registry->get('zomato'))->toThrow(InvalidArgumentException::class);
});

// ---- redaction ---------------------------------------------------------------------------------------------

it('removes secrets by field name, by value and from query strings — and leaves ordinary fields alone', function () {
    $clean = PayloadRedactor::payload([
        'glusr_crm_key' => IM_KEY, 'api_key' => 'abc', 'Authorization' => 'Bearer zzz', 'password' => 'p', 'X-Signature' => 's',
        'SENDER_NAME' => 'Meera', 'keyword' => 'bolts', 'monkey' => 'tail',
        'QUERY_MESSAGE' => 'see https://x.test/?glusr_crm_key='.IM_KEY.'&a=1 and call back',
        'nested' => ['token' => 'tt', 'note' => 'my secret is open-sesame-777'],
    ], ['open-sesame-777']);

    expect($clean['glusr_crm_key'])->toBe('[removed]')->and($clean['api_key'])->toBe('[removed]')->and($clean['Authorization'])->toBe('[removed]')
        ->and($clean['password'])->toBe('[removed]')->and($clean['X-Signature'])->toBe('[removed]')
        ->and($clean['SENDER_NAME'])->toBe('Meera')->and($clean['keyword'])->toBe('bolts')->and($clean['monkey'])->toBe('tail')
        ->and($clean['QUERY_MESSAGE'])->toBe('see https://x.test/?glusr_crm_key=[removed]&a=1 and call back')
        ->and($clean['nested']['token'])->toBe('[removed]')->and($clean['nested']['note'])->toBe('my secret is [removed]');
});

it('scrubs invalid bytes so a record can always be stored, and caps a field’s size', function () {
    $clean = PayloadRedactor::payload(['name' => "Caf\xE9 Traders", 'long' => str_repeat('x', 9000)]);

    expect(json_encode($clean))->not->toBeFalse()->and(mb_check_encoding($clean['name'], 'UTF-8'))->toBeTrue()->and(mb_strlen($clean['long']))->toBe(4000);
});
