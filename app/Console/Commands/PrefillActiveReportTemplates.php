<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DenominatorBasis;
use App\Enums\ProjectStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Support\PrefillResult;
use App\Support\ReportTemplatePrefiller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Launch backfill (contracts.md §12.4, T33, D-45): creates the pending
 * report templates and lines of projects already active at deploy. They stay
 * active: no activation, no project or proposal status write. Prints one row
 * per project: template new or already there, lines created, lines already
 * there and the unlinked targets ("link it to the library first"); then the
 * ids of the `fixed`-basis lines with no `fixed_denominator` (a NULL
 * `project_proposal.target_beneficiaries`, D-25), or "none", which T27 checks
 * before go. Idempotent; --dry-run computes the same rows and list (the
 * would-be-new lines as target ids) and writes nothing. Runs silent: a
 * launch fill writes no activity rows (Dev 1, 2026-10-23); MEAL's
 * `report_template.pending` notification is still sent.
 */
class PrefillActiveReportTemplates extends Command
{
    protected $signature = 'report-templates:prefill-active
        {--dry-run : Show what would be created without writing anything}';

    protected $description = 'Pre-fill the pending report templates of projects already active at deploy';

    public function handle(ReportTemplatePrefiller $prefiller): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $projects = Project::query()
            ->where('status', ProjectStatus::Active)
            ->orderBy('id')
            ->get();
        $missingTargetIds = [];

        foreach ($projects as $project) {
            $row = $dryRun ? $this->preview($prefiller, $project) : $this->apply($prefiller, $project);
            $missingTargetIds = [...$missingTargetIds, ...$row['missingTargets']];

            $this->line(__('report_templates.console.prefill_row', [
                'project' => $project->id,
                'template' => __($row['templateCreated'] ? 'report_templates.console.template_new' : 'report_templates.console.template_existing'),
                'created' => $row['created'],
                'skipped' => $row['skipped'],
                'unlinked' => $this->ids($row['unlinked']),
            ]));
        }

        $this->reportMissingDenominators($this->missingDenominatorLineIds(), $missingTargetIds);

        if ($dryRun) {
            $this->info(__('report_templates.console.dry_run'));
        }

        return self::SUCCESS;
    }

    /**
     * @return array{templateCreated: bool, created: int, skipped: int, unlinked: list<int>, missingTargets: list<int>}
     */
    private function apply(ReportTemplatePrefiller $prefiller, Project $project): array
    {
        $result = Activity::withoutRecording(fn (): PrefillResult => $prefiller->prefillProject($project));
        $lines = DB::table('report_template_lines')
            ->join('report_templates', 'report_templates.id', '=', 'report_template_lines.report_template_id')
            ->where('report_templates.project_id', $project->id)
            ->count();

        return [
            'templateCreated' => $result->createdTemplateIds !== [],
            'created' => count($result->createdLineIds),
            'skipped' => $lines - count($result->createdLineIds),
            'unlinked' => $result->unlinkedPlanIndicatorIds,
            'missingTargets' => [],
        ];
    }

    /**
     * The same row as apply(), read-only.
     *
     * @return array{templateCreated: bool, created: int, skipped: int, unlinked: list<int>, missingTargets: list<int>}
     */
    private function preview(ReportTemplatePrefiller $prefiller, Project $project): array
    {
        $preview = $prefiller->previewProject($project);

        return [
            'templateCreated' => $preview['createsTemplate'],
            'created' => count($preview['newLinePlanIndicatorIds']),
            'skipped' => $preview['existingLineCount'],
            'unlinked' => $preview['unlinkedPlanIndicatorIds'],
            'missingTargets' => $preview['missingFixedDenominatorPlanIndicatorIds'],
        ];
    }

    /**
     * The pending or confirmed lines of active projects whose library
     * basis is `fixed` and that have no `fixed_denominator`, in one query.
     *
     * @return list<int>
     */
    private function missingDenominatorLineIds(): array
    {
        return array_values(DB::table('report_template_lines')
            ->join('report_templates', 'report_templates.id', '=', 'report_template_lines.report_template_id')
            ->join('projects', 'projects.id', '=', 'report_templates.project_id')
            ->join('plan_indicators', 'plan_indicators.id', '=', 'report_template_lines.plan_indicator_id')
            ->join('indicators', 'indicators.id', '=', 'plan_indicators.indicator_id')
            ->join('library_indicators', 'library_indicators.id', '=', 'indicators.library_indicator_id')
            ->where('projects.status', ProjectStatus::Active->value)
            ->whereIn('report_template_lines.status', [ReportTemplateLineStatus::Pending->value, ReportTemplateLineStatus::Confirmed->value])
            ->where('library_indicators.denominator_basis', DenominatorBasis::Fixed->value)
            ->whereNull('report_template_lines.fixed_denominator')
            ->orderBy('report_template_lines.id')
            ->pluck('report_template_lines.id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all());
    }

    /**
     * Print the missing-denominator lines (and, on a dry run, targets), or
     * an explicit "none" so the launch check can see the list is empty.
     *
     * @param  list<int>  $lineIds
     * @param  list<int>  $targetIds
     */
    private function reportMissingDenominators(array $lineIds, array $targetIds): void
    {
        if ($lineIds === [] && $targetIds === []) {
            $this->line(__('report_templates.console.missing_none'));

            return;
        }

        if ($lineIds !== []) {
            $this->warn(__('report_templates.console.missing_lines', ['ids' => $this->ids($lineIds)]));
        }

        if ($targetIds !== []) {
            $this->warn(__('report_templates.console.missing_targets', ['ids' => $this->ids($targetIds)]));
        }
    }

    /**
     * Ids for a row, or "none".
     *
     * @param  list<int>  $ids
     */
    private function ids(array $ids): string
    {
        return $ids === [] ? __('report_templates.console.none') : implode(', ', $ids);
    }
}
