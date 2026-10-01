<?php

use App\Enums\Currency;
use App\Support\CsvExport;
use Carbon\CarbonImmutable;

test('a csv starts with a byte order mark and writes plain cells', function () {
    $response = CsvExport::download('x.csv', ['Name', 'Amount'], [
        ['=HYPERLINK("x")', CsvExport::amount('1234.5')],
        ['-15.00', CarbonImmutable::parse('2026-03-05')],
        [null, Currency::Usd],
    ]);

    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();

    expect($content)->toStartWith("\xEF\xBB\xBF")
        ->and($response->headers->get('content-type'))->toContain('text/csv')
        ->and($content)->toContain("'=HYPERLINK")
        ->and($content)->toContain('1234.50')
        ->and($content)->toContain('-15.00,2026-03-05')
        ->and($content)->toContain(',USD')
        ->and(CsvExport::amount(null))->toBe('')
        ->and(CsvExport::cell('+961 1'))->toBe("'+961 1")
        ->and(CsvExport::cell('<1'))->toBe('<1');
});
