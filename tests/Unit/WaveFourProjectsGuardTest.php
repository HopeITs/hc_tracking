<?php

declare(strict_types=1);

/**
 * D-74: a wave 4 file names `Project` only inside PlaceBridge. Starts with the
 * files Y-02 creates or lists; later tasks add theirs. Do not add a file to
 * the allow-list without Dev 1.
 *
 * Y-11 (Dev 1, 23 Nov), removed by Y-17: `LocationBackfill` and `ProjectLocationMirror` copied
 * `projects` onto the location rows while `projects` is still the writer, so
 * they must read it. Both are removed by Y-18.
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
        // Y-03: monthly reports follow the template.
        dirname(__DIR__, 2).'/app/Support/MonthlyReportGenerator.php',
        dirname(__DIR__, 2).'/app/Console/Commands/TemplateBackfillMonthlyReports.php',
        // Y-03 slice 2: every monthly-report action and support class, and the two commands.
        ...glob(dirname(__DIR__, 2).'/app/Actions/MonthlyReports/*.php'),
        ...glob(dirname(__DIR__, 2).'/app/Support/MonthlyReport*.php'),
        dirname(__DIR__, 2).'/app/Console/Commands/PrepareMonthlyReports.php',
        dirname(__DIR__, 2).'/app/Console/Commands/BackfillMonthlyReports.php',
        // Y-03 slice 3: monthly-report notification recipients follow the template.
        dirname(__DIR__, 2).'/app/Notifications/Recipients/MonthlyReportRecipients.php',
        // Y-05: the Monthly Matrix reads through ReportExtraction (its Activities tab through PlaceBridge).
        dirname(__DIR__, 2).'/app/Livewire/Concerns/Reports/BuildsMonthlyMatrix.php',
        dirname(__DIR__, 2).'/app/Livewire/Concerns/Reports/GroupsMonthlyMatrix.php',
        dirname(__DIR__, 2).'/app/Livewire/Concerns/Reports/BuildsMatrixBreakdowns.php',
        dirname(__DIR__, 2).'/app/Livewire/Concerns/Reports/BuildsMatrixActivities.php',
        // Y-11: the location rows and their successors.
        ...glob(dirname(__DIR__, 2).'/app/Support/Locations/*.php'),
    ];

    // Y-17 removed LocationBackfill and ProjectLocationMirror with the
    // mirror itself, so PlaceBridge is the last class that may name Project.
    $allowed = ['PlaceBridge.php'];

    $offenders = [];

    foreach ($paths as $path) {
        if (in_array(basename($path), $allowed, true)) {
            continue;
        }

        if (preg_match('/App\\\\Models\\\\Project\b|\bProject::/', (string) file_get_contents($path)) === 1) {
            $offenders[] = str_replace(dirname(__DIR__, 2).DIRECTORY_SEPARATOR, '', $path);
        }
    }

    expect($offenders)->toBe([]);
});
