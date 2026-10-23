<?php

namespace App\Modules\Shared\Imports;

use Generator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Throwable;
use ZipArchive;

/**
 * Reads the first sheet (or the one called "Data") of an .xlsx or .csv file a chunk of rows at a time, so a
 * big file never has to fit in memory. Row 1 holds the headings; data rows are numbered as in the spreadsheet,
 * so an error report can say "row 417" and mean the same row the person sees in Excel.
 *
 * Only cell *values* are read: formulas are not evaluated and macros, links and styles are ignored.
 */
final class SpreadsheetReader
{
    private ?string $sheetName = null;

    private ?int $lastRow = null;

    private ?int $lastColumn = null;

    public function __construct(private readonly string $path, private readonly string $extension) {}

    /** Refuse files that are not what they claim to be, or that would blow up when unpacked. */
    public function assertSafe(int $maxUnpackedMb): void
    {
        if ($this->isCsv()) {
            $sample = (string) file_get_contents($this->path, false, null, 0, 8192);

            if (str_contains($sample, "\0")) {
                throw new ImportFileException('This does not look like a CSV text file. Save it as "CSV UTF-8" or upload the .xlsx template.');
            }

            return;
        }

        $zip = new ZipArchive;

        if ($zip->open($this->path, ZipArchive::RDONLY) !== true || $zip->locateName('xl/workbook.xml') === false) {
            throw new ImportFileException('This is not a valid Excel (.xlsx) workbook. Use the template, or save your file as .xlsx.');
        }

        $unpacked = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $unpacked += (int) ($zip->statIndex($i)['size'] ?? 0);
        }
        $zip->close();

        if ($unpacked > $maxUnpackedMb * 1024 * 1024) {
            throw new ImportFileException("The workbook is too large once unpacked (over {$maxUnpackedMb} MB). Split it into smaller files or upload a CSV.");
        }
    }

    /** @return list<mixed> the cells of row 1 */
    public function headings(): array
    {
        if ($this->isCsv()) {
            foreach ($this->csvRecords() as $record) {
                return $record;
            }

            return [];
        }

        return $this->xlsxRange(1, 1)[1] ?? [];
    }

    /** Last row number in the sheet (an upper bound on the data rows: trailing blank rows are included). */
    public function lastRow(): int
    {
        if ($this->isCsv()) {
            $n = 0;
            foreach ($this->csvRecords() as $_) {
                $n++;
            }

            return $n;
        }

        $this->discoverSheet();

        return $this->lastRow ?? 1;
    }

    /**
     * Data rows, `$size` at a time. Each chunk maps spreadsheet row number => list of cell values.
     *
     * @return Generator<int, array<int, list<mixed>>>
     */
    public function chunks(int $size): Generator
    {
        if ($this->isCsv()) {
            $chunk = [];
            foreach ($this->csvRecords() as $number => $record) {
                if ($number === 1) {
                    continue;
                }
                $chunk[$number] = $record;
                if (count($chunk) >= $size) {
                    yield $chunk;
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                yield $chunk;
            }

            return;
        }

        $last = $this->lastRow();
        for ($from = 2; $from <= $last; $from += $size) {
            $chunk = $this->xlsxRange($from, min($from + $size - 1, $last));
            if ($chunk !== []) {
                yield $chunk;
            }
        }
    }

    private function isCsv(): bool
    {
        return $this->extension === 'csv';
    }

    // ---- xlsx ----------------------------------------------------------------------------------------------

    private function discoverSheet(): void
    {
        if ($this->sheetName !== null) {
            return;
        }

        $reader = new XlsxReader;
        $names = $reader->listWorksheetNames($this->path);

        if ($names === []) {
            throw new ImportFileException('The workbook has no sheets.');
        }

        $this->sheetName = collect($names)->first(fn (string $n) => strtolower(trim($n)) === 'data') ?? $names[0];

        foreach ($reader->listWorksheetInfo($this->path) as $info) {
            if ($info['worksheetName'] === $this->sheetName) {
                $this->lastRow = (int) $info['totalRows'];
                $this->lastColumn = max(1, (int) $info['totalColumns']);
            }
        }
    }

    /**
     * @return array<int, list<mixed>> row number => cells
     */
    private function xlsxRange(int $from, int $to): array
    {
        $this->discoverSheet();

        $reader = new XlsxReader;
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setLoadSheetsOnly([$this->sheetName]);
        $reader->setReadFilter(new class($from, $to) implements IReadFilter
        {
            public function __construct(private readonly int $from, private readonly int $to) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row >= $this->from && $row <= $this->to;
            }
        });

        try {
            $spreadsheet = $reader->load($this->path);
        } catch (Throwable) {
            throw new ImportFileException('The workbook could not be read. It may be damaged, password-protected or not a real .xlsx file.');
        }

        $endColumn = Coordinate::stringFromColumnIndex(min($this->lastColumn ?? 1, 60));
        $values = $spreadsheet->getActiveSheet()->rangeToArray("A{$from}:{$endColumn}{$to}", null, false, false, false);

        $spreadsheet->disconnectWorksheets();

        $rows = [];
        foreach ($values as $offset => $cells) {
            $rows[$from + $offset] = array_values($cells);
        }

        return $rows;
    }

    // ---- csv -----------------------------------------------------------------------------------------------

    /**
     * @return Generator<int, list<string|null>> record number (1 = headings) => cells
     */
    private function csvRecords(): Generator
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw new ImportFileException('The file could not be opened.');
        }

        try {
            $sample = (string) fread($handle, 65536);
            rewind($handle);

            $utf8 = mb_check_encoding($sample, 'UTF-8');
            $firstLine = strtok(ltrim($sample, "\xEF\xBB\xBF"), "\r\n") ?: '';
            $delimiter = collect([',', ';', "\t"])->sortByDesc(fn (string $d) => substr_count($firstLine, $d))->first();

            $number = 0;
            while (($record = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $number++;

                if ($number === 1 && isset($record[0])) {
                    $record[0] = ltrim($record[0], "\xEF\xBB\xBF");
                }

                yield $number => array_map(
                    fn ($cell) => $cell === null ? null : ($utf8 ? $cell : mb_convert_encoding($cell, 'UTF-8', 'Windows-1252')),
                    $record,
                );
            }
        } finally {
            fclose($handle);
        }
    }
}
