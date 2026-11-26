<?php

declare(strict_types=1);

namespace App\Support\Locations;

use App\Models\MonthlyReport;
use App\Models\ProposalLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which report templates — and so which monthly reports — belong to a
 * location row's place (Y-02, Y-16, D-93).
 *
 * A monthly report belongs to a **template**, and a template names a place
 * (city, program, centre or field work), not a row: no foreign key joins the
 * two. Matching them is the only honest way for a row to reach its reports,
 * and it is the path Y-18 keeps once `projects` is gone. Reading
 * `monthly_reports.project_id` instead would hand the same report to every
 * row of that project, and two proposals may share a place (D-77).
 *
 * One query for the templates and one for the reports, however many rows.
 */
final class MonthlyReportsAtRows
{
    /**
     * The report templates at each row's place: same city and program, and
     * either both are field work (D-88), the template's centre is one of the
     * row's (D-90), or neither has a centre yet (D-82).
     *
     * @param  Collection<int, ProposalLocation>  $rows
     * @return array<int, list<int>> row id => template ids
     */
    public function templateIdsByRow(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $templates = DB::table('report_templates')
            ->whereIn('city_id', $rows->pluck('city_id')->filter()->unique()->all() ?: [0])
            ->whereIn('program_id', $rows->pluck('program_id')->filter()->unique()->all() ?: [0])
            ->get(['id', 'city_id', 'program_id', 'center_id', 'city_wide']);

        $byRow = [];

        foreach ($rows as $row) {
            $byRow[$row->id] = array_values($templates
                ->filter(function (object $template) use ($row): bool {
                    if ((int) $template->city_id !== (int) $row->city_id || (int) $template->program_id !== (int) $row->program_id) {
                        return false;
                    }

                    return match (true) {
                        (bool) $template->city_wide => $row->city_wide,
                        $template->center_id !== null => in_array((int) $template->center_id, $row->centerIds(), true),
                        default => ! $row->city_wide && $row->centerIds() === [],
                    };
                })
                ->map(fn (object $template): int => (int) $template->id)
                ->all());
        }

        return $byRow;
    }

    /**
     * The monthly reports at each row's place, oldest first.
     *
     * Load each row's `centers` before calling: the place match reads them.
     *
     * @param  Collection<int, ProposalLocation>  $rows
     * @param  list<string>  $columns
     * @return array<int, Collection<int, MonthlyReport>> row id => reports
     */
    public function reportsByRow(Collection $rows, array $columns = ['id', 'slug', 'project_id', 'report_template_id', 'period', 'status', 'returned_at']): array
    {
        $byRow = $this->templateIdsByRow($rows);
        $templateIds = array_values(array_unique(array_merge(...array_values($byRow)) ?: []));

        if ($templateIds === []) {
            return array_map(fn (): Collection => new Collection, $byRow);
        }

        $reports = MonthlyReport::query()
            ->whereIn('report_template_id', $templateIds)
            ->orderBy('period')
            ->orderBy('id')
            ->get(array_values(array_unique([...$columns, 'report_template_id'])))
            ->groupBy(fn (MonthlyReport $report): int => (int) $report->report_template_id);

        return array_map(
            fn (array $ids): Collection => (new Collection($ids))
                ->flatMap(fn (int $templateId): Collection => $reports->get($templateId) ?? new Collection)
                ->values(),
            $byRow,
        );
    }

    /**
     * The monthly reports at one row's place, oldest first.
     *
     * @param  list<string>  $columns
     * @return Collection<int, MonthlyReport>
     */
    public function forRow(ProposalLocation $row, array $columns = ['id', 'slug', 'project_id', 'report_template_id', 'period', 'status', 'returned_at']): Collection
    {
        return $this->reportsByRow(new Collection([$row]), $columns)[$row->id] ?? new Collection;
    }
}
