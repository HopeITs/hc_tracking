<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Y-03 backfill (D-76, D-80, wave4/AGENT.md §4): gives every monthly report
 * that has no template yet the single template of its project.
 *
 * A project with no template or with more than one leaves its reports
 * unmapped: they are listed and never guessed. This and the Y-03 migration
 * are the only places that read `report_templates.project_id` for a report,
 * so the logic lives here once and the migration calls it. `--dry-run`
 * prints the same counts and writes nothing. Idempotent: it only touches
 * reports whose `report_template_id` is null.
 */
class TemplateBackfillMonthlyReports extends Command
{
    protected $signature = 'monthly-reports:template-backfill
        {--dry-run : Print the counts without writing anything}';

    protected $description = 'Give existing monthly reports the template of their project (Y-03)';

    public function handle(): int
    {
        $plan = $this->plan();
        $counts = $this->counts($plan);

        $this->line(__('monthly_reports.console.template_backfill_reports', ['count' => $counts['reports']]));
        $this->line(__('monthly_reports.console.template_backfill_mapped', ['count' => $counts['mapped']]));
        $this->line(__('monthly_reports.console.template_backfill_unmapped', ['count' => $counts['unmapped']]));

        if ($counts['unmapped'] > 0) {
            $this->line(__('monthly_reports.console.template_backfill_unmapped_ids', ['ids' => implode(', ', $this->unmappedIds($plan))]));
        }

        foreach ($counts['by_status'] as $status => $count) {
            $this->line(__('monthly_reports.console.template_backfill_status', ['status' => $status, 'count' => $count]));
        }

        if ($this->option('dry-run')) {
            $this->line(__('monthly_reports.console.dry_run'));

            return self::SUCCESS;
        }

        if ($counts['unmapped'] > 0) {
            return self::FAILURE;
        }

        $this->apply($plan);

        return self::SUCCESS;
    }

    /**
     * Every report without a template, with its project's single template
     * or null when the project has none or several.
     *
     * @return array<int, array{project_id: int|null, status: string, report_template_id: int|null}>
     */
    public function plan(): array
    {
        $reports = DB::table('monthly_reports')
            ->when(Schema::hasColumn('monthly_reports', 'report_template_id'), fn (Builder $query) => $query->whereNull('report_template_id'))
            ->orderBy('id')
            ->get(['id', 'project_id', 'status']);

        $templates = DB::table('report_templates')
            ->whereIn('project_id', $reports->pluck('project_id')->filter()->unique()->values())
            ->groupBy('project_id')
            ->selectRaw('project_id, MIN(id) as template_id, COUNT(*) as templates')
            ->get()
            ->keyBy('project_id');

        $plan = [];

        foreach ($reports as $report) {
            $template = $report->project_id === null ? null : $templates->get($report->project_id);

            $plan[(int) $report->id] = [
                'project_id' => $report->project_id === null ? null : (int) $report->project_id,
                'status' => (string) $report->status,
                'report_template_id' => $template !== null && (int) $template->templates === 1 ? (int) $template->template_id : null,
            ];
        }

        return $plan;
    }

    /**
     * Write the mapped reports in one UPDATE … JOIN; unmapped ones stay null.
     *
     * @param  array<int, array{project_id: int|null, status: string, report_template_id: int|null}>  $plan
     */
    public function apply(array $plan): int
    {
        $mapped = array_keys(array_filter($plan, fn (array $row): bool => $row['report_template_id'] !== null));

        if ($mapped === []) {
            return 0;
        }

        $single = DB::table('report_templates')
            ->whereNotNull('project_id')
            ->groupBy('project_id')
            ->havingRaw('COUNT(*) = 1')
            ->selectRaw('project_id, MIN(id) as template_id');

        return DB::table('monthly_reports')
            ->joinSub($single, 'single_templates', 'single_templates.project_id', '=', 'monthly_reports.project_id')
            ->whereNull('monthly_reports.report_template_id')
            ->whereIn('monthly_reports.id', $mapped)
            ->update(['monthly_reports.report_template_id' => DB::raw('single_templates.template_id')]);
    }

    /**
     * @param  array<int, array{project_id: int|null, status: string, report_template_id: int|null}>  $plan
     * @return array{reports: int, mapped: int, unmapped: int, by_status: array<string, int>}
     */
    public function counts(array $plan): array
    {
        $rows = collect($plan);
        $mapped = $rows->filter(fn (array $row): bool => $row['report_template_id'] !== null)->count();

        return [
            'reports' => $rows->count(),
            'mapped' => $mapped,
            'unmapped' => $rows->count() - $mapped,
            'by_status' => $rows->countBy('status')->sortKeys()->all(),
        ];
    }

    /**
     * @param  array<int, array{project_id: int|null, status: string, report_template_id: int|null}>  $plan
     * @return list<int>
     */
    public function unmappedIds(array $plan): array
    {
        return array_keys(array_filter($plan, fn (array $row): bool => $row['report_template_id'] === null));
    }
}
