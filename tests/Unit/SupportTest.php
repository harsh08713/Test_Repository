<?php

use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Support\FinancialYear;
use App\Modules\Shared\Support\Format;
use Carbon\CarbonImmutable;

// ---- Indian number and date formatting -----------------------------------------------------------

it('groups digits the Indian way', function (float|int|string $value, string $expected) {
    expect(Format::number($value))->toBe($expected);
})->with([
    [0, '0.00'],
    [7, '7.00'],
    [999, '999.00'],
    [1000, '1,000.00'],
    [12345, '12,345.00'],
    [100000, '1,00,000.00'],
    [1234567.5, '12,34,567.50'],
    [12345678.9, '1,23,45,678.90'],
    [1000000000, '1,00,00,00,000.00'],
    [-1234567.891, '-12,34,567.89'],
    ['2500.5', '2,500.50'],
]);

it('formats rupee amounts and quantities', function () {
    expect(Format::inr(1234567.5))->toBe('₹12,34,567.50')
        ->and(Format::inr(-500))->toBe('-₹500.00')
        ->and(Format::inr(null))->toBe('')
        ->and(Format::number(1234.5678, 3))->toBe('1,234.568')
        ->and(Format::number(1500, 0))->toBe('1,500');
});

it('displays dates as DD-MM-YYYY', function () {
    expect(Format::date('2026-10-05'))->toBe('05-10-2026')
        ->and(Format::date(CarbonImmutable::create(2027, 1, 31)))->toBe('31-01-2027')
        ->and(Format::dateTime(CarbonImmutable::create(2026, 3, 9, 14, 5)))->toBe('09-03-2026 14:05')
        ->and(Format::date(null))->toBe('');
});

it('runs in the Indian timezone', function () {
    expect(config('app.timezone'))->toBe('Asia/Kolkata')
        ->and(now()->timezoneName)->toBe('Asia/Kolkata');
});

// ---- financial year ------------------------------------------------------------------------------

it('labels the April–March financial year', function (string $date, string $label) {
    expect(FinancialYear::label(CarbonImmutable::parse($date)))->toBe($label);
})->with([
    ['2026-04-01', '26-27'],
    ['2026-12-31', '26-27'],
    ['2027-01-01', '26-27'],
    ['2027-03-31', '26-27'],
    ['2027-04-01', '27-28'],
    ['2099-06-15', '99-00'],
]);

it('supports a different start month', function () {
    expect(FinancialYear::label(CarbonImmutable::parse('2026-06-30'), 7))->toBe('25-26')
        ->and(FinancialYear::label(CarbonImmutable::parse('2026-07-01'), 7))->toBe('26-27');
});

it('returns the first and last day of the year', function () {
    [$from, $to] = FinancialYear::range(CarbonImmutable::parse('2026-10-12'));

    expect($from->toDateString())->toBe('2026-04-01')->and($to->toDateString())->toBe('2027-03-31');
});

// ---- enums ---------------------------------------------------------------------------------------

it('numbers only the transactional documents', function () {
    expect(collect(DocumentType::numbered())->map->value->all())
        ->toBe(['lead', 'deal', 'quotation', 'sales_order', 'sales_invoice', 'sales_return', 'purchase_order', 'grn', 'loss_incident', 'corrective_action', 'campaign', 'production_order', 'inspection', 'non_conformance', 'maintenance_order'])
        ->and(DocumentType::Item->isNumbered())->toBeFalse()
        ->and(DocumentType::SalesOrder->prefix())->toBe('SO')
        ->and(DocumentType::Quotation->prefix())->toBe('QT');
});

it('uses the document type as the permission form key', function () {
    foreach (DocumentType::cases() as $type) {
        expect($type->permissionKey())->toBe($type->value)->and($type->label())->not->toBeEmpty();
    }
});

it('only lets drafts and rejected documents be edited', function () {
    expect(ApprovalStatus::Draft->isEditable())->toBeTrue()
        ->and(ApprovalStatus::Rejected->isEditable())->toBeTrue()
        ->and(ApprovalStatus::Pending->isEditable())->toBeFalse()
        ->and(ApprovalStatus::Approved->isEditable())->toBeFalse();
});
