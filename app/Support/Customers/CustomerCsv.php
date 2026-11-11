<?php

namespace App\Support\Customers;

/**
 * Customer list as CSV for Excel / Google Sheets: UTF-8 with a BOM so Excel
 * shows Cyrillic and Albanian letters correctly.
 */
final class CustomerCsv
{
    public const BOM = "\xEF\xBB\xBF";

    private const TYPES = ['customer' => 'Customer', 'business' => 'Business', 'guest' => 'Guest (no account)'];

    /** @param iterable<array<string, mixed>> $rows */
    public static function make(iterable $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, [
            'Name', 'Email', 'Phone', 'City', 'Type', 'Orders', 'Total spent (MKD)', 'Average order (MKD)',
            'First order', 'Last order', 'Days since last order', 'Store credit (MKD)', 'Tags', 'Notes',
        ], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'cell'], [
                $row['name'],
                $row['email'],
                $row['phone'],
                $row['city'],
                self::TYPES[$row['type']] ?? $row['type'],
                $row['ordersCount'],
                self::amount($row['totalSpent']),
                self::amount($row['averageOrder']),
                $row['firstOrderAt'] ? substr($row['firstOrderAt'], 0, 10) : '',
                $row['lastOrderAt'] ? substr($row['lastOrderAt'], 0, 10) : '',
                $row['daysSinceLastOrder'] ?? '',
                self::amount($row['storeCredit']),
                implode(', ', $row['tags']),
                $row['notes'],
            ]), ',', '"', '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return self::BOM . $csv;
    }

    private static function amount(float|int|null $value): string
    {
        $value = (float) $value;

        return floor($value) == $value ? (string) (int) $value : number_format($value, 2, '.', '');
    }

    /** Stops a cell like "=HYPERLINK(...)" typed into a name or note from running as an Excel formula. */
    public static function cell(mixed $value): string
    {
        $value = (string) ($value ?? '');
        if ($value !== '' && (in_array($value[0], ['=', '@', "\t", "\r"], true)
            || (in_array($value[0], ['+', '-'], true) && ! preg_match('/^[+-][\d\s().]*$/', $value)))) {
            return "'" . $value;
        }

        return $value;
    }
}
