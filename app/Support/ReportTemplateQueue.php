<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ProjectStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Enums\ReportTemplateStatus;
use App\Models\HrCity;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MEAL's "Templates to confirm" queue (contracts.md §12.4, T33 slice 3): the
 * templates still pending, plus the confirmed ones that received pending
 * lines (D-41), of the projects the user covers (MealCoverage::projectsCoveredBy());
 * every project for the MEAL Manager slot holder.
 *
 * A fixed number of queries whatever the row count: the templates with their
 * projects, one grouped count of pending lines, the proposals' names and the
 * governorates' names (HR, by id, read-only). No money.
 */
final class ReportTemplateQueue
{
    /**
     * The queue rows for this user, pending templates first, then by project name.
     *
     * @return list<array{
     *     template: ReportTemplate,
     *     project: Project,
     *     status: ReportTemplateStatus,
     *     pendingLineCount: int,
     *     annualPlanLineCount: int,
     *     proposals: list<array{id: int, name: string, number: string}>,
     *     cityId: int|null,
     *     cityName: string|null,
     *     centerName: string|null,
     * }>
     */
    public static function rowsFor(User $user): array
    {
        $employeeId = $user->employee_id;

        if ($employeeId === null) {
            return [];
        }

        $projectIds = $employeeId === Slots::holderOf('meal_manager') ? null : MealCoverage::projectsCoveredBy($employeeId);

        if ($projectIds === []) {
            return [];
        }

        $templates = ReportTemplate::query()
            ->select(['id', 'project_id', 'status', 'updated_at'])
            ->when($projectIds !== null, fn (Builder $query) => $query->whereIn('project_id', $projectIds))
            ->whereHas('project', fn (Builder $query) => $query->whereIn('status', [ProjectStatus::Approved->value, ProjectStatus::Active->value]))
            ->where(fn (Builder $query) => $query
                ->where('status', ReportTemplateStatus::Pending->value)
                ->orWhereHas('lines', fn (Builder $query) => $query->where('status', ReportTemplateLineStatus::Pending->value)))
            ->with('project:id,name,slug,status,city_id,center_id')
            ->get();

        if ($templates->isEmpty()) {
            return [];
        }

        $pendingGroups = ReportTemplateLine::query()
            ->toBase()
            ->join('plan_indicators', 'plan_indicators.id', '=', 'report_template_lines.plan_indicator_id')
            ->whereIn('report_template_lines.report_template_id', $templates->modelKeys())
            ->where('report_template_lines.status', ReportTemplateLineStatus::Pending->value)
            ->groupBy('report_template_lines.report_template_id', 'plan_indicators.source_type', 'plan_indicators.source_id')
            ->get([
                'report_template_lines.report_template_id',
                'plan_indicators.source_type',
                'plan_indicators.source_id',
                DB::raw('count(*) as line_count'),
            ]);

        $proposalIds = $pendingGroups->where('source_type', 'proposal')->pluck('source_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $proposals = $proposalIds === [] ? collect() : Proposal::query()
            ->whereKey($proposalIds)
            ->orderBy('name')
            ->get(['id', 'name', 'internal_project_number'])
            ->keyBy('id');

        $cityIds = [];

        foreach ($templates as $template) {
            $cityIds[$template->id] = MealCoverage::cityIdOf($template->project);
        }

        $knownCityIds = array_values(array_unique(array_filter($cityIds, fn (?int $id): bool => $id !== null)));
        $cities = $knownCityIds === [] ? collect() : HrCity::query()->whereKey($knownCityIds)->get(['id', 'name_en', 'name_ar'])->keyBy('id');

        $rows = [];

        foreach ($templates as $template) {
            $groups = $pendingGroups->where('report_template_id', $template->id);
            $templateProposalIds = $groups->where('source_type', 'proposal')->pluck('source_id')->map(fn (mixed $id): int => (int) $id)->all();
            $cityId = $cityIds[$template->id];

            $rows[] = [
                'template' => $template,
                'project' => $template->project,
                'status' => $template->status,
                'pendingLineCount' => (int) $groups->sum('line_count'),
                'annualPlanLineCount' => (int) $groups->where('source_type', 'annual_plan')->sum('line_count'),
                'proposals' => array_values($proposals
                    ->only($templateProposalIds)
                    ->map(fn (Proposal $proposal): array => ['id' => $proposal->id, 'name' => $proposal->name, 'number' => $proposal->internal_project_number])
                    ->sortBy('name')
                    ->all()),
                'cityId' => $cityId,
                'cityName' => $cityId === null ? null : $cities->get($cityId)?->name,
                'centerName' => $template->project->center()?->name,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['status'] === ReportTemplateStatus::Pending ? 0 : 1, mb_strtolower($a['project']->name), $a['project']->id]
            <=> [$b['status'] === ReportTemplateStatus::Pending ? 0 : 1, mb_strtolower($b['project']->name), $b['project']->id]);

        return $rows;
    }
}
