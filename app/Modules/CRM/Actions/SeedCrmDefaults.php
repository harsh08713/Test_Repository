<?php

namespace App\Modules\CRM\Actions;

use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Gives a company the CRM reference data the brief names: the lead sources, the follow-up outcomes and a starting set
 * of lost reasons. Called for every new company (from SeedCompanyDefaults) and for the companies that already existed
 * when the CRM tables were created.
 *
 * Idempotent: it only adds codes the company does not have (a row an administrator deleted counts as present, so it is
 * not brought back) and never touches a row that has been edited.
 */
final class SeedCrmDefaults
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @return array{lead_sources: int, followup_outcomes: int, lost_reasons: int} how many rows were added */
    public function run(Company|int $company): array
    {
        $companyId = $company instanceof Company ? (int) $company->getKey() : $company;

        return $this->tenant->withoutTenancy(fn () => DB::transaction(fn () => [
            'lead_sources' => $this->insertMissing('lead_sources', $companyId, self::leadSources()),
            'followup_outcomes' => $this->insertMissing('followup_outcomes', $companyId, self::followupOutcomes()),
            'lost_reasons' => $this->insertMissing('lost_reasons', $companyId, self::lostReasons()),
        ]));
    }

    /**
     * The sources the brief lists. Their codes are relied on by importers and integrations.
     *
     * @return array<string, array<string, mixed>> code => the other columns
     */
    public static function leadSources(): array
    {
        $rows = [];
        foreach ([
            ['REFERENCE', 'Reference'], ['WEBSITE', 'Website'], ['SOCIAL', 'Social Media'], ['TELECALLER', 'Tele-caller'],
            ['INDIAMART', 'IndiaMART'], ['JUSTDIAL', 'Just Dial'], ['FIELD', 'Field'], ['EXCEL', 'Excel Import'], ['OTHER', 'Other'],
        ] as [$code, $name]) {
            $rows[$code] = ['name' => $name, 'is_system' => true];
        }

        return $rows;
    }

    /**
     * The outcomes the brief lists, with what each means for the lead.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function followupOutcomes(): array
    {
        $rows = [];
        // [code, name, the person was actually reached, needs a date for the next step]
        foreach ([
            ['CALL_DISCONNECTED', 'Call Disconnected', false, false], ['NOT_AVAILABLE', 'Not Available', false, false],
            ['INTERESTED', 'Interested', true, false], ['NOT_INTERESTED', 'Not Interested', true, false],
            ['CALL_BACK_LATER', 'Call Back Later', true, true], ['MEETING_FIXED', 'Meeting Fixed', true, true],
            ['STORE_VISITED', 'Store Visited', true, false],
        ] as [$code, $name, $contact, $next]) {
            $rows[$code] = ['name' => $name, 'is_system' => true, 'counts_as_contact' => $contact, 'needs_next_followup' => $next];
        }

        return $rows;
    }

    /**
     * A starting set; the company edits it freely (none of these is relied on by code).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function lostReasons(): array
    {
        $rows = [];
        foreach ([
            ['PRICE', 'Price too high'], ['COMPETITOR', 'Went to a competitor'], ['NO_REQUIREMENT', 'No requirement now'],
            ['NO_RESPONSE', 'No response'], ['NOT_SERVICEABLE', 'Area or item not serviceable'], ['DUPLICATE', 'Duplicate lead'], ['OTHER', 'Other'],
        ] as [$code, $name]) {
            $rows[$code] = ['name' => $name];
        }

        return $rows;
    }

    /** @param  array<string, array<string, mixed>>  $rows */
    private function insertMissing(string $table, int $companyId, array $rows): int
    {
        $have = DB::table($table)->where('company_id', $companyId)->pluck('code')->map(fn ($v) => (string) $v)->all();
        $now = now();

        $new = [];
        foreach ($rows as $code => $attributes) {
            if (! in_array((string) $code, $have, true)) {
                $new[] = [...$attributes, 'code' => $code, 'company_id' => $companyId, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
            }
        }

        if ($new !== []) {
            DB::table($table)->insert($new);
        }

        return count($new);
    }
}
