<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

/**
 * Monthly reports (contracts §13, §15.2, T17A): the project owner is direct;
 * the program manager comes through the resolver's cascade and MEAL +
 * General Management through the catalog's oversight. The full map (the
 * validator, the preparer and submitter, reopen deciders) lands in T17A
 * slice 4. `monthly_reports.generated` is listener driven:
 * GenerateMonthlyReportsForProject names the owner itself.
 */
final class MonthlyReportRecipients extends ProjectOwnedRecipients {}
