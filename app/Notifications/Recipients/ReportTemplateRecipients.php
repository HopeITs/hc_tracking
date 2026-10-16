<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

/**
 * Report templates (contracts §15.2, T33): the project owner is direct for
 * `report_template.confirmed` and `report_template.lines_added`; the program
 * manager comes through the resolver's cascade and MEAL + General Management
 * through the catalog's oversight. `report_template.pending` is listener
 * driven: PrefillReportTemplates passes the responsible officer and the MEAL
 * Manager slot itself.
 */
final class ReportTemplateRecipients extends ProjectOwnedRecipients {}
