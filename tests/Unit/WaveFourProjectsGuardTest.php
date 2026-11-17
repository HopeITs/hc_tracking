<?php

declare(strict_types=1);

/**
 * D-74: a wave 4 file names `Project` only inside PlaceBridge. Starts with the
 * files Y-02 creates or lists; later tasks add theirs. Do not add a file to
 * the allow-list without Dev 1.
 */
it('names the Project model only in the place bridge', function (): void {
    $paths = [
        ...glob(base_path('app/Support/Reporting/*.php')),
        base_path('app/Support/ReportTemplatePrefiller.php'),
        base_path('app/Support/ReportTemplateReader.php'),
        base_path('app/Support/ReportTemplateQueue.php'),
        ...glob(base_path('app/Actions/ReportTemplates/*.php')),
    ];

    $offenders = [];

    foreach ($paths as $path) {
        if (basename($path) === 'PlaceBridge.php') {
            continue;
        }

        if (preg_match('/App\\\\Models\\\\Project\b|\bProject::/', (string) file_get_contents($path)) === 1) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        }
    }

    expect($offenders)->toBe([]);
});
