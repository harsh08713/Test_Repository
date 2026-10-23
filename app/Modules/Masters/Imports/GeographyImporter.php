<?php

namespace App\Modules\Masters\Imports;

use App\Modules\Shared\Imports\ImportColumn;
use App\Modules\Shared\Imports\Importer;
use App\Modules\Shared\Imports\ImportSession;

/**
 * Tent Studio's Block / PIN code data → the company's State → District → Block → PIN code masters.
 * The column layout is ours (Tent Studio's own file has not been supplied), so the template defines it.
 */
final class GeographyImporter implements Importer
{
    public function key(): string
    {
        return 'geography';
    }

    public function label(): string
    {
        return 'Geography: districts, blocks and PIN codes';
    }

    public function description(): string
    {
        return 'One row per block and/or PIN code locality, with its state and district. Used to fill addresses from a PIN code.';
    }

    public function permission(): string
    {
        return 'geography.import';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('state', 'State', true, 'The state or union territory, as named under Masters → Geography → States. The 2-letter code (GJ) or GST code (24) also works.', 'Gujarat'),
            new ImportColumn('district', 'District', true, 'Created automatically if it does not exist yet.', 'Surat'),
            new ImportColumn('block', 'Block', false, 'Block / tehsil / taluka. Created automatically if it does not exist yet. Leave empty for a PIN code with no known block.', 'Choryasi'),
            new ImportColumn('pin_code', 'PIN Code', false, '6 digits, not starting with 0. Leave empty only on a row that just adds a block.', '395003', text: true),
            new ImportColumn('locality', 'Locality', false, 'Post office / locality. One PIN code can have several localities: put each on its own row. Leave empty for a row that covers the whole PIN code.', 'Surat City'),
        ];
    }

    public function rules(): array
    {
        return [
            'Row 1 holds the column headings exactly as on the Data sheet. Extra columns are ignored; column order does not matter.',
            'Every row needs a State and a District, and a Block, a PIN Code, or both.',
            'The import never creates states: a State that is not in your States master is rejected (check the spelling).',
            'Districts and blocks are matched by name inside their parent, ignoring capitals, accents and extra spaces, and are created when missing.',
            'A PIN code row is identified by PIN + district + locality. Uploading the same row again changes nothing.',
            'An empty Block cell never removes a block that is already recorded; a different block replaces it.',
            'Districts, blocks and PIN codes you have deactivated are never reactivated: rows that refer to them are rejected. Ones you deleted are restored.',
            'If the same PIN, district and locality appears twice with different blocks, the second row is rejected.',
            'Rows with problems are skipped and listed in an error report; every other row is imported. Correct the listed rows and upload again; re-uploading the whole file is safe.',
            'The check shows exactly what would happen. Nothing is saved until you confirm.',
        ];
    }

    public function examples(): array
    {
        return [
            ['state' => 'Gujarat', 'district' => 'Surat', 'block' => 'Choryasi', 'pin_code' => '395003', 'locality' => 'Surat City'],
            ['state' => 'Gujarat', 'district' => 'Ahmedabad', 'block' => 'Daskroi', 'pin_code' => '380001', 'locality' => 'Khanpur'],
            ['state' => 'Maharashtra', 'district' => 'Pune', 'block' => 'Haveli', 'pin_code' => '', 'locality' => ''],
        ];
    }

    public function counterLabels(): array
    {
        return [
            'districts_created' => 'New districts',
            'blocks_created' => 'New blocks',
            'pins_created' => 'New PIN code rows',
            'pins_updated' => 'PIN code rows updated',
            'records_restored' => 'Deleted records restored',
            'repeated_rows' => 'Repeated rows (already covered earlier in the file)',
        ];
    }

    public function begin(int $companyId, ?int $userId = null, array $options = []): ImportSession
    {
        return new GeographyImportSession($companyId);
    }
}
