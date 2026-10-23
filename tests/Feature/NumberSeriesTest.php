<?php

use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Exceptions\TenantAccessException;
use App\Modules\Shared\Models\NumberSeries;
use App\Modules\Shared\Services\NumberSeriesService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->company = makeCompany('ACME');
    $this->ahd = makeLocation($this->company, 'AHD');
    $this->mum = makeLocation($this->company, 'MUM');
    $this->numbers = app(NumberSeriesService::class);
    $this->date = CarbonImmutable::create(2026, 10, 12);   // financial year 2026-27
});

it('formats numbers as prefix / branch / financial year / sequence', function () {
    expect($this->numbers->next(DocumentType::Quotation, $this->ahd, $this->date))->toBe('QT/AHD/26-27/00001')
        ->and($this->numbers->next(DocumentType::Quotation, $this->ahd, $this->date))->toBe('QT/AHD/26-27/00002');
});

it('uses the standard prefix for each numbered document type', function () {
    expect($this->numbers->next(DocumentType::Lead, $this->ahd, $this->date))->toStartWith('LD/')
        ->and($this->numbers->next(DocumentType::Deal, $this->ahd, $this->date))->toStartWith('DL/')
        ->and($this->numbers->next(DocumentType::SalesOrder, $this->ahd, $this->date))->toStartWith('SO/')
        ->and($this->numbers->next(DocumentType::SalesInvoice, $this->ahd, $this->date))->toStartWith('SI/');
});

it('keeps an independent counter per document type and per branch', function () {
    $this->numbers->next(DocumentType::Quotation, $this->ahd, $this->date);
    $this->numbers->next(DocumentType::Quotation, $this->ahd, $this->date);

    expect($this->numbers->next(DocumentType::Quotation, $this->mum, $this->date))->toBe('QT/MUM/26-27/00001')
        ->and($this->numbers->next(DocumentType::SalesOrder, $this->ahd, $this->date))->toBe('SO/AHD/26-27/00001')
        ->and($this->numbers->next(DocumentType::Quotation, $this->ahd, $this->date))->toBe('QT/AHD/26-27/00003');
});

it('rolls over to a fresh sequence on 1 April', function () {
    $lastDay = CarbonImmutable::create(2027, 3, 31);
    $firstDay = CarbonImmutable::create(2027, 4, 1);

    expect($this->numbers->next(DocumentType::SalesOrder, $this->ahd, $lastDay))->toBe('SO/AHD/26-27/00001')
        ->and($this->numbers->next(DocumentType::SalesOrder, $this->ahd, $lastDay))->toBe('SO/AHD/26-27/00002')
        ->and($this->numbers->next(DocumentType::SalesOrder, $this->ahd, $firstDay))->toBe('SO/AHD/27-28/00001');
});

it('honours a company whose financial year starts in another month', function () {
    $calendarYear = makeCompany('CAL', ['financial_year_start_month' => 1]);
    $branch = makeLocation($calendarYear, 'DEL');

    expect($this->numbers->next(DocumentType::Lead, $branch, CarbonImmutable::create(2027, 2, 1)))->toBe('LD/DEL/27-28/00001');
});

it('hands out unique numbers across many calls', function () {
    $issued = collect(range(1, 40))->map(fn () => $this->numbers->next(DocumentType::Lead, $this->ahd, $this->date));

    expect($issued->unique()->count())->toBe(40)
        ->and($issued->last())->toBe('LD/AHD/26-27/00040')
        ->and(NumberSeries::withoutGlobalScopes()->count())->toBe(1);
});

it('does not consume a number when the surrounding transaction rolls back', function () {
    try {
        DB::transaction(function () {
            expect($this->numbers->next(DocumentType::Quotation, $this->ahd, $this->date))->toBe('QT/AHD/26-27/00001');

            throw new RuntimeException('document failed to save');
        });
    } catch (RuntimeException) {
    }

    expect($this->numbers->next(DocumentType::Quotation, $this->ahd, $this->date))->toBe('QT/AHD/26-27/00001');
});

it('previews the next number without consuming it', function () {
    expect($this->numbers->preview(DocumentType::Quotation, $this->ahd, $this->date))->toBe('QT/AHD/26-27/00001')
        ->and($this->numbers->preview(DocumentType::Quotation, $this->ahd, $this->date))->toBe('QT/AHD/26-27/00001')
        ->and($this->numbers->next(DocumentType::Quotation, $this->ahd, $this->date))->toBe('QT/AHD/26-27/00001')
        ->and($this->numbers->preview(DocumentType::Quotation, $this->ahd, $this->date))->toBe('QT/AHD/26-27/00002');
});

it('uses a customised prefix and padding', function () {
    $this->numbers->next(DocumentType::Quotation, $this->ahd, $this->date);

    NumberSeries::withoutGlobalScopes()->update(['prefix' => 'QTN', 'padding' => 6]);

    expect($this->numbers->next(DocumentType::Quotation, $this->ahd, $this->date))->toBe('QTN/AHD/26-27/000002');
});

it('refuses documents that are not numbered', function () {
    $this->numbers->next(DocumentType::Ledger, $this->ahd, $this->date);
})->throws(InvalidArgumentException::class, 'not a numbered document');

it('refuses a branch the user cannot access', function () {
    $this->actingAs(makeUser(['Sales Manager'], [$this->company], locations: [$this->ahd]));

    $this->numbers->next(DocumentType::Quotation, $this->mum, $this->date);
})->throws(TenantAccessException::class);

it('lets a branch user number documents for their own branch', function () {
    $this->actingAs(makeUser(['Sales Manager'], [$this->company], locations: [$this->ahd]));

    expect($this->numbers->next(DocumentType::Quotation, $this->ahd, $this->date))->toBe('QT/AHD/26-27/00001');
});

it('will not number documents for a deleted branch', function () {
    $this->mum->delete();

    expect(fn () => $this->numbers->next(DocumentType::Quotation, $this->mum->id, $this->date))
        ->toThrow(ModelNotFoundException::class);
});

it('accepts a branch id as well as a model', function () {
    expect($this->numbers->next(DocumentType::Deal, $this->ahd->id, $this->date))->toBe('DL/AHD/26-27/00001');
});
