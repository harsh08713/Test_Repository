<?php

namespace App\Modules\Masters\Actions;

use App\Modules\CRM\Actions\SeedCrmDefaults;
use App\Modules\Masters\Support\StateCatalog;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Gives a company the reference data every company needs: the 36 states/UTs with GST codes, the standard
 * units of measure, the standard Indian chart-of-accounts groups, the usual payment methods, and the CRM's lead
 * sources, follow-up outcomes and lost reasons (SeedCrmDefaults).
 *
 * Idempotent: running it again only adds what is missing and never touches rows an administrator has since
 * edited (a row an administrator deleted counts as present, so it is not brought back). It is a system action
 * (it runs without a user's tenant restrictions) that writes straight to the tables in one transaction — about a
 * hundred rows in a handful of statements — and records one summary audit entry instead of a "created" entry for
 * each seeded row.
 */
final class SeedCompanyDefaults
{
    public function __construct(private readonly TenantContext $tenant, private readonly SeedCrmDefaults $crm) {}

    /**
     * How many rows were added, per kind.
     *
     * @return array{states: int, units: int, account_groups: int, payment_methods: int, lead_sources: int, followup_outcomes: int, lost_reasons: int}
     */
    public function run(Company $company): array
    {
        return $this->tenant->withoutTenancy(function () use ($company) {
            $added = DB::transaction(fn () => [
                'states' => $this->states($company),
                'units' => $this->units($company),
                'account_groups' => $this->chartOfAccounts($company),
                'payment_methods' => $this->paymentMethods($company),
                // the CRM's own reference data: lead sources, follow-up outcomes, lost reasons
                ...$this->crm->run($company),
            ]);

            if (array_sum($added) > 0) {
                activity('company')->performedOn($company)->event('defaults_seeded')->withProperties($added)->log('defaults_seeded');
            }

            return $added;
        });
    }

    private function states(Company $company): int
    {
        $rows = [];
        foreach (StateCatalog::all() as $row) {
            $rows[$row['gst_code']] = ['code' => $row['code'], 'name' => $row['name']];
        }

        return $this->insertMissing('states', $company, 'gst_code', $rows);
    }

    private function units(Company $company): int
    {
        $rows = [];
        foreach ($this->standardUnits() as [$code, $name, $decimal]) {
            $rows[$code] = ['name' => $name, 'decimal_allowed' => $decimal];
        }

        return $this->insertMissing('units', $company, 'code', $rows);
    }

    private function paymentMethods(Company $company): int
    {
        $rows = [];
        foreach ([['CASH', 'Cash'], ['CHEQUE', 'Cheque'], ['NEFT', 'NEFT'], ['RTGS', 'RTGS'], ['IMPS', 'IMPS'], ['UPI', 'UPI']] as [$code, $name]) {
            $rows[$code] = ['name' => $name];
        }

        return $this->insertMissing('payment_methods', $company, 'code', $rows);
    }

    /**
     * Insert the rows whose key the company does not have yet (deleted rows count as present: the unique index
     * would refuse them, and an administrator removed them on purpose).
     *
     * @param  array<string, array<string, mixed>>  $rows  key value => the other columns
     */
    private function insertMissing(string $table, Company $company, string $key, array $rows): int
    {
        $have = DB::table($table)->where('company_id', $company->id)->pluck($key)->map(fn ($v) => (string) $v)->all();
        $now = now();

        $new = [];
        foreach ($rows as $keyValue => $attributes) {
            if (! in_array((string) $keyValue, $have, true)) {
                $new[] = [...$attributes, $key => $keyValue, 'company_id' => $company->id, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
            }
        }

        if ($new !== []) {
            DB::table($table)->insert($new);
        }

        return count($new);
    }

    private function chartOfAccounts(Company $company): int
    {
        $ids = DB::table('account_groups')->where('company_id', $company->id)->pluck('id', 'code')->all();   // code => id
        $now = now();
        $added = 0;

        $create = function (array $node, ?int $parentId, string $nature) use (&$create, &$ids, &$added, $company, $now) {
            if (! isset($ids[$node['code']])) {
                $ids[$node['code']] = DB::table('account_groups')->insertGetId([
                    'company_id' => $company->id, 'code' => $node['code'], 'name' => $node['name'], 'nature' => $nature,
                    'parent_id' => $parentId, 'is_system' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $added++;
            }

            foreach ($node['children'] ?? [] as $child) {
                $create($child, $ids[$node['code']], $nature);
            }
        };

        foreach ($this->standardChart() as $nature => $root) {
            $create($root, null, $nature);
        }

        return $added;
    }

    /** @return list<array{0: string, 1: string, 2: bool}> code, name, decimals allowed */
    private function standardUnits(): array
    {
        return [
            ['NOS', 'Numbers', false], ['PCS', 'Pieces', false], ['SET', 'Set', false], ['PAIR', 'Pair', false],
            ['BOX', 'Box', false], ['PKT', 'Packet', false], ['BAG', 'Bag', false], ['ROLL', 'Roll', false],
            ['KG', 'Kilogram', true], ['GM', 'Gram', true], ['TON', 'Metric Ton', true],
            ['LTR', 'Litre', true], ['ML', 'Millilitre', true],
            ['MTR', 'Metre', true], ['CM', 'Centimetre', true], ['FT', 'Feet', true],
            ['SQM', 'Square Metre', true], ['SQF', 'Square Feet', true], ['CUM', 'Cubic Metre', true],
        ];
    }

    /**
     * The standard Indian groups, in the familiar Tally-style layout. Codes are spaced so a company can
     * slot its own groups in between.
     *
     * @return array<string, array{code: string, name: string, children?: list<array<string, mixed>>}>
     */
    private function standardChart(): array
    {
        return [
            'asset' => ['code' => '1000', 'name' => 'Assets', 'children' => [
                ['code' => '1100', 'name' => 'Current Assets', 'children' => [
                    ['code' => '1110', 'name' => 'Bank Accounts'],
                    ['code' => '1120', 'name' => 'Cash-in-Hand'],
                    ['code' => '1130', 'name' => 'Deposits (Asset)'],
                    ['code' => '1140', 'name' => 'Loans & Advances (Asset)'],
                    ['code' => '1150', 'name' => 'Stock-in-Hand'],
                    ['code' => '1160', 'name' => 'Sundry Debtors'],
                ]],
                ['code' => '1200', 'name' => 'Fixed Assets'],
                ['code' => '1300', 'name' => 'Investments'],
                ['code' => '1400', 'name' => 'Miscellaneous Expenses (Asset)'],
            ]],
            'liability' => ['code' => '2000', 'name' => 'Liabilities', 'children' => [
                ['code' => '2100', 'name' => 'Capital Account', 'children' => [
                    ['code' => '2110', 'name' => 'Reserves & Surplus'],
                ]],
                ['code' => '2200', 'name' => 'Current Liabilities', 'children' => [
                    ['code' => '2210', 'name' => 'Duties & Taxes'],
                    ['code' => '2220', 'name' => 'Provisions'],
                    ['code' => '2230', 'name' => 'Sundry Creditors'],
                ]],
                ['code' => '2300', 'name' => 'Loans (Liability)', 'children' => [
                    ['code' => '2310', 'name' => 'Bank OD Accounts'],
                    ['code' => '2320', 'name' => 'Secured Loans'],
                    ['code' => '2330', 'name' => 'Unsecured Loans'],
                ]],
                ['code' => '2400', 'name' => 'Suspense Account'],
            ]],
            'income' => ['code' => '3000', 'name' => 'Income', 'children' => [
                ['code' => '3100', 'name' => 'Direct Incomes'],
                ['code' => '3200', 'name' => 'Indirect Incomes'],
                ['code' => '3300', 'name' => 'Sales Accounts'],
            ]],
            'expense' => ['code' => '4000', 'name' => 'Expenses', 'children' => [
                ['code' => '4100', 'name' => 'Direct Expenses'],
                ['code' => '4200', 'name' => 'Indirect Expenses'],
                ['code' => '4300', 'name' => 'Purchase Accounts'],
            ]],
        ];
    }
}
