<?php

declare(strict_types=1);

/**
 * D-74: a wave 4 file names `Project` only inside PlaceBridge. Starts with the
 * files Y-02 creates or lists; later tasks add theirs. Do not add a file to
 * the allow-list without Dev 1.
 */
it('names the Project model only in the place bridge', function (): void {
    $paths = [
        ...glob(dirname(__DIR__, 2).'/app/Support/Reporting/*.php'),
        dirname(__DIR__, 2).'/app/Support/ReportTemplatePrefiller.php',
        dirname(__DIR__, 2).'/app/Support/ReportTemplateReader.php',
        dirname(__DIR__, 2).'/app/Support/ReportTemplateQueue.php',
        ...glob(dirname(__DIR__, 2).'/app/Actions/ReportTemplates/*.php'),
        // Y-09: the change-request actions.
        ...glob(dirname(__DIR__, 2).'/app/Actions/Proposals/*ProposalChange.php'),
    ];

    $offenders = [];

    foreach ($paths as $path) {
        if (basename($path) === 'PlaceBridge.php') {
            continue;
        }

        if (preg_match('/App\\\\Models\\\\Project\b|\bProject::/', (string) file_get_contents($path)) === 1) {
            $offenders[] = str_replace(dirname(__DIR__, 2).DIRECTORY_SEPARATOR, '', $path);
        }
    }

    expect($offenders)->toBe([]);
});
