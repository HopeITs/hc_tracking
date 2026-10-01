<?php

declare(strict_types=1);

namespace App\Support;

use BackedEnum;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a CSV a spreadsheet opens cleanly: UTF-8 with a byte order mark,
 * ISO dates, plain numbers, and no cell a spreadsheet would run as a formula.
 */
final class CsvExport
{
    /**
     * @param  list<string>  $headings
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $headings, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, array_map(self::cell(...), $headings), ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($output, array_map(self::cell(...), array_values($row)), ',', '"', '');
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * An amount as a plain number with two decimals, or empty.
     */
    public static function amount(string|float|int|null $value): string
    {
        return $value === null ? '' : number_format((float) $value, 2, '.', '');
    }

    /**
     * One cell as text. Text that starts like a formula is prefixed with an
     * apostrophe; numbers, negative ones included, are left alone.
     */
    public static function cell(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof BackedEnum => (string) $value->value,
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };

        return $text !== '' && ! is_numeric($text) && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}
