<?php

namespace App\Services\ProductImport;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use RuntimeException;

/**
 * Reads .xlsx, .xls, .ods and .csv files into plain rows of cell values.
 * CSV files may be UTF-8 (with or without BOM) or Windows-1251, the encoding
 * older Macedonian accounting programs export; the separator is detected.
 */
class SheetReader
{
    public const EXTENSIONS = ['xlsx', 'xls', 'ods', 'csv', 'txt'];
    public const MAX_ROWS = 20000;
    public const MAX_COLUMNS = 60;

    /**
     * @return array{sheets: list<string>, sheet: int, rows: list<list<mixed>>}
     */
    public function read(string $path, string $extension, int $sheet = 0): array
    {
        $extension = strtolower($extension);

        if (in_array($extension, ['csv', 'txt'], true)) {
            return ['sheets' => ['CSV'], 'sheet' => 0, 'rows' => $this->readCsv((string) file_get_contents($path))];
        }

        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new RuntimeException('Unsupported file type. Use .xlsx, .xls or .csv.');
        }

        try {
            $reader = IOFactory::createReader(match ($extension) {
                'xlsx' => 'Xlsx',
                'xls' => 'Xls',
                'ods' => 'Ods',
            });
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $names = $reader->listWorksheetNames($path);
            $spreadsheet = $reader->load($path);
        } catch (ReaderException|\Throwable $e) {
            throw new RuntimeException('The file could not be read as a spreadsheet. Save it again from Excel and retry.');
        }

        $sheet = max(0, min($sheet, $spreadsheet->getSheetCount() - 1));
        $worksheet = $spreadsheet->getSheet($sheet);

        try {
            $rows = $worksheet->toArray(null, true, false, false);
        } catch (\Throwable) {
            $rows = $worksheet->toArray(null, false, false, false);
        }

        $spreadsheet->disconnectWorksheets();

        return ['sheets' => array_values($names), 'sheet' => $sheet, 'rows' => $this->tidy($rows)];
    }

    /** @return list<list<mixed>> */
    public function readCsv(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }

        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        // Excel adds "sep=;" as a first line when asked to.
        $delimiter = null;
        if (isset($lines[0]) && preg_match('/^sep=(.)$/i', trim($lines[0]), $match)) {
            $delimiter = $match[1];
            array_shift($lines);
            $content = implode("\n", $lines);
        }
        $delimiter ??= $this->detectDelimiter(array_slice($lines, 0, 20));

        $rows = [];
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($cell) => $cell === null || $cell === '' ? null : $cell, $row);
            if (count($rows) > self::MAX_ROWS) {
                break;
            }
        }
        fclose($handle);

        return $this->tidy($rows);
    }

    /** @param list<string> $lines */
    private function detectDelimiter(array $lines): string
    {
        $best = ',';
        $bestScore = -1;
        foreach ([',', ';', "\t", '|'] as $delimiter) {
            $counts = array_map(fn (string $line) => count(str_getcsv($line, $delimiter, '"', '')), array_filter($lines, fn ($l) => trim($l) !== ''));
            if ($counts === []) {
                continue;
            }
            // Many columns on most lines wins.
            sort($counts);
            $score = $counts[intdiv(count($counts), 2)];
            if ($score > $bestScore) {
                $best = $delimiter;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Same width for every row, trailing empty rows removed, capped in size.
     *
     * @param array<int, array<int, mixed>> $rows
     * @return list<list<mixed>>
     */
    private function tidy(array $rows): array
    {
        $rows = array_slice(array_values($rows), 0, self::MAX_ROWS);
        $width = 0;
        foreach ($rows as $row) {
            for ($i = count($row) - 1; $i >= 0; $i--) {
                if (($row[$i] ?? null) !== null && trim((string) $row[$i]) !== '') {
                    $width = max($width, $i + 1);
                    break;
                }
            }
        }
        $width = min($width, self::MAX_COLUMNS);

        $tidy = array_map(function ($row) use ($width) {
            $row = array_values(array_slice($row, 0, $width));

            return array_pad(array_map(fn ($cell) => is_string($cell) && trim($cell) === '' ? null : $cell, $row), $width, null);
        }, $rows);

        while ($tidy !== [] && array_filter(end($tidy), fn ($cell) => $cell !== null) === []) {
            array_pop($tidy);
        }

        return $tidy;
    }
}
