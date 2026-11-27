<?php

declare(strict_types=1);

namespace App\Support\Locations;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Y-18 (D-92): what is still attached to a project, counted. The one gate in
 * front of the drop — every row has to read zero before a single column of
 * slice 2 is dropped, because this is the task `down()` cannot undo.
 *
 * Three kinds of row:
 *
 * - **columns**: per table carrying the legacy `project_id`, the rows where it
 *   is set *and* the column that replaced it is empty. A row that still points
 *   at a project and nowhere else would lose its place when the column goes.
 * - **morphs**: per morph column, the rows whose value is still the `project`
 *   morph key (removed from `enforceMorphMap` in slice 2).
 * - **projects_without_row**: a project no location row carries. It has no
 *   successor, so `project_redirects` (slice 3) could not name one either.
 *
 * `milestones` is not here: D-95 dropped that table on 17 Nov, so the prompt's
 * fifteenth column does not exist.
 *
 * Reads only. It writes nothing, locks nothing and is safe on production.
 *
 * @deprecated Y-18 slice 3 deletes this class with the `projects` table.
 */
final class ProjectsRetireAudit
{
    /**
     * Every table whose legacy `project_id` is replaced by
     * `proposal_location_id` alone: the row's place is the replacement, so a
     * row with a project and no location row has nowhere to go.
     *
     * @var list<string>
     */
    private const ROW_KEYED = [
        'risks',
        'indicators',
        'activity_schedules',
        'project_activity_statuses',
        'plan_updates',
        'activity_plan_follow_up_tasks',
        'schedule_dependencies',
        'schedule_baselines',
    ];

    /**
     * Count what is still attached to a project.
     *
     * @return array{
     *     columns: array<string, int>,
     *     morphs: array<string, int>,
     *     projects_without_row: int,
     *     total: int,
     * }
     */
    public function run(): array
    {
        $columns = $this->columns();
        $morphs = $this->morphs();
        $withoutRow = $this->projectsWithoutRow();

        return [
            'columns' => $columns,
            'morphs' => $morphs,
            'projects_without_row' => $withoutRow,
            'total' => array_sum($columns) + array_sum($morphs) + $withoutRow,
        ];
    }

    /**
     * True when nothing is left attached to a project.
     *
     * @param  array{total: int}  $result
     */
    public function isClean(array $result): bool
    {
        return $result['total'] === 0;
    }

    /**
     * Per legacy column, the rows whose replacement is empty.
     *
     * @return array<string, int>
     */
    private function columns(): array
    {
        $counts = [];

        foreach (self::ROW_KEYED as $table) {
            $counts[$table] = $this->legacy($table)->whereNull('proposal_location_id')->count();
        }

        // A target is placed by its row (a proposal target, Y-10) or by its
        // place columns (an annual-plan target, which by D-44 is per center
        // and has no row at all). Either answer is enough.
        $counts['plan_indicators'] = $this->legacy('plan_indicators')
            ->whereNull('proposal_location_id')
            ->where(fn (Builder $query): Builder => $query->whereNull('city_id')->orWhereNull('program_id'))
            ->count();

        // A template is placed by city + program (+ center or city-wide),
        // Y-02; a monthly report belongs to its template, Y-03.
        $counts['report_templates'] = $this->legacy('report_templates')
            ->where(fn (Builder $query): Builder => $query->whereNull('city_id')->orWhereNull('program_id'))
            ->count();

        $counts['monthly_reports'] = $this->legacy('monthly_reports')->whereNull('report_template_id')->count();

        // Coverage moved to places in Y-14, which kept the legacy rows "for
        // rollback": a row with no `scope_key` was never placed.
        $counts['meal_coverages'] = $this->legacy('meal_coverages')->whereNull('scope_key')->count();

        // The row is itself the replacement, so what is left to check is that
        // it has the proposal that gives it one.
        $counts['proposal_locations'] = $this->legacy('proposal_locations')->whereNull('proposal_id')->count();

        ksort($counts);

        return $counts;
    }

    /**
     * Per morph column, the rows still naming the `project` morph key.
     *
     * @return array<string, int>
     */
    private function morphs(): array
    {
        $counts = [
            'tasks.source_type' => 'source_type',
            'media.mediable_type' => 'mediable_type',
            'assignments.assignable_type' => 'assignable_type',
            'activities.subject_type' => 'subject_type',
            'notifications.subject_type' => 'subject_type',
            'plan_indicators.source_type' => 'source_type',
        ];

        foreach ($counts as $row => $column) {
            [$table] = explode('.', $row);

            $counts[$row] = DB::table($table)->where($column, 'project')->count();
        }

        return $counts;
    }

    /**
     * A project no location row carries: it has no successor to redirect to.
     */
    private function projectsWithoutRow(): int
    {
        return DB::table('projects')
            ->whereNotExists(fn (Builder $query): Builder => $query
                ->selectRaw('1')
                ->from('proposal_locations')
                ->whereColumn('proposal_locations.project_id', 'projects.id'))
            ->count();
    }

    /**
     * The rows of $table that still carry a project.
     */
    private function legacy(string $table): Builder
    {
        return DB::table($table)->whereNotNull('project_id');
    }
}
