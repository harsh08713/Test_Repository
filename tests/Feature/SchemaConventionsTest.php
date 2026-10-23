<?php

use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestDocument;

it('gives every master table the standard columns', function () {
    $columns = collect(Schema::getColumnListing('test_documents'));

    expect($columns->all())->toContain(
        'id', 'company_id', 'code', 'status', 'approval_status', 'approved_by', 'approved_at',
        'remarks', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
    );
});

it('stores money as decimal(15,2) and quantities as decimal(15,3), never floats', function () {
    $types = collect(Schema::getColumns('test_documents'))->keyBy('name');

    expect($types['total']['type'])->toBe('decimal(15,2)')
        ->and($types['qty']['type'])->toBe('decimal(15,3)');
});

it('keeps decimal precision through a save', function () {
    $company = makeCompany('ACME');
    $doc = makeDocument($company);
    $doc->forceFill(['total' => '1234567890123.45', 'qty' => '99.125'])->save();

    $raw = TestDocument::query()->whereKey($doc->id)->first()->getRawOriginal();

    expect($raw['total'])->toBe('1234567890123.45')->and($raw['qty'])->toBe('99.125');
});

it('keeps codes unique within a company but reusable across companies', function () {
    $acme = makeCompany('ACME');
    $globex = makeCompany('GLOBEX');

    makeDocument($acme, null, ['code' => 'ITM-001']);
    makeDocument($globex, null, ['code' => 'ITM-001']);     // same code, different company: fine

    expect(fn () => makeDocument($acme, null, ['code' => 'ITM-001']))->toThrow(QueryException::class);
});

it('indexes the columns that filters and joins rely on', function () {
    $indexed = collect(Schema::getIndexes('test_documents'))->flatMap(fn ($i) => $i['columns'])->unique();

    expect($indexed->all())->toContain('company_id', 'status', 'approval_status', 'location_id');
});

it('soft deletes instead of removing rows', function () {
    $doc = makeDocument(makeCompany('ACME'));
    $doc->delete();

    expect(TestDocument::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0)
        ->and(TestDocument::withoutGlobalScopes()->count())->toBe(1);   // the row is still there, flagged deleted
});

it('defaults a new record to active and draft, matching the table defaults', function () {
    $doc = new TestDocument(['name' => 'x']);

    expect($doc->status)->toBe('active')->and($doc->approval_status->value)->toBe('draft');
});

it('enforces one counter per company, branch, document type and financial year', function () {
    $uniqueIndexes = collect(Schema::getIndexes('number_series'))->where('unique', true)->pluck('columns');

    expect($uniqueIndexes->contains(['company_id', 'location_id', 'document_type', 'financial_year']))->toBeTrue();
});
