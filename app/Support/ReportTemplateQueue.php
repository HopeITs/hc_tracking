<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ProjectStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Enums\ReportTemplateStatus;
use App\Enums\TemplatePlacementStatus;
use App\Models\HrCenter;
use App\Models\HrCity;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\ReportTemplatePlacement;
use App\Models\User;
use App\Support\Reporting\PlaceBridge;
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
    /** @return list<array<string, mixed>> */
    public static function placementRowsFor(User $user): array
    {
        return array_values(ReportTemplatePlacement::query()->manageableBy($user)
            ->where('status', TemplatePlacementStatus::Pending)
            ->with(['proposal:id,name,internal_project_number', 'program', 'city'])
            ->orderBy('id')->get()->map(function (ReportTemplatePlacement $placement): array {
                return [
                    'placement' => $placement, 'category' => 'placement', 'template' => null, 'project' => null,
                    'name' => $placement->proposal->name,
                    'status' => ReportTemplateStatus::Pending, 'pendingLineCount' => 0, 'annualPlanLineCount' => 0,
                    'templateProposals' => [], 'proposals' => [['id' => $placement->proposal_id, 'name' => $placement->proposal->name, 'number' => $placement->proposal->internal_project_number]],
                    'cityId' => $placement->city_id, 'cityName' => $placement->city?->name,
                    'centerName' => $placement->city_wide ? __('report_templates.queue.field_work')
                        : HrCenter::remembered($placement->center_id)?->name,
                    'programName' => $placement->program->name,
                ];
            })->values()->all());
    }

    /**
     * The queue rows for this user, pending templates first, then by project name.
     *
     * @return list<array{
     *     template: ReportTemplate,
     *     project: object|null,
     *     name: string,
     *     templateProposals: list<array{id: int, name: string, number: string|null}>,
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

        $coveredProjectIds = $employeeId === Slots::holderOf('meal_manager') ? null : MealCoverage::projectsCoveredBy($employeeId);

        if ($coveredProjectIds === []) {
            return [];
        }

        $templates = ReportTemplate::query()
            ->select(['id', 'project_id', 'city_id', 'program_id', 'center_id', 'city_wide', 'status', 'updated_at'])
            ->where(fn (Builder $query) => $query
                ->where('status', ReportTemplateStatus::Pending->value)
                ->orWhereHas('lines', fn (Builder $query) => $query->where('status', ReportTemplateLineStatus::Pending->value)))
            ->with(['program:id', 'proposals:id,name,internal_project_number'])
            ->get();

        $bridge = app(PlaceBridge::class);
        $legacyIds = $bridge->legacyProjectIdsFor($templates);
        $projects = $bridge->projectsByIds(array_values(array_unique(array_filter($legacyIds))));

        $templates = $templates->filter(function (ReportTemplate $template) use ($legacyIds, $projects, $coveredProjectIds): bool {
            $project = $projects->get($legacyIds[$template->id] ?? 0);

            if ($project !== null && ! in_array($project->status, [ProjectStatus::Approved, ProjectStatus::Active], true)) {
                return false;
            }

            return $coveredProjectIds === null || ($project !== null && in_array($project->id, $coveredProjectIds, true));
        })->values();

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

        $knownCityIds = $templates->pluck('city_id')->unique()->values()->all();
        $cities = HrCity::query()->whereKey($knownCityIds)->get(['id', 'name_en', 'name_ar'])->keyBy('id');

        $rows = [];

        foreach ($templates as $template) {
            $groups = $pendingGroups->where('report_template_id', $template->id);
            $templateProposalIds = $groups->where('source_type', 'proposal')->pluck('source_id')->map(fn (mixed $id): int => (int) $id)->all();
            $cityId = $template->city_id;
            $project = $projects->get($legacyIds[$template->id] ?? 0);

            $rows[] = [
                'template' => $template,
                'project' => $project,
                'name' => $project->name ?? (string) $template->program?->name,
                'status' => $template->status,
                'pendingLineCount' => (int) $groups->sum('line_count'),
                'annualPlanLineCount' => (int) $groups->where('source_type', 'annual_plan')->sum('line_count'),
                'proposals' => array_values($proposals
                    ->only($templateProposalIds)
                    ->map(fn (Proposal $proposal): array => ['id' => $proposal->id, 'name' => $proposal->name, 'number' => $proposal->internal_project_number])
                    ->sortBy('name')
                    ->all()),
                'templateProposals' => array_values($template->proposals
                    ->sortBy('name')
                    ->map(fn (Proposal $proposal): array => ['id' => $proposal->id, 'name' => $proposal->name, 'number' => $proposal->internal_project_number])
                    ->all()),
                'cityId' => $cityId,
                'cityName' => $cities->get($cityId)?->name,
                'centerName' => $template->center_id === null ? ($template->city_wide ? (string) __('report_templates.queue.field_work') : null) : HrCenter::remembered($template->center_id)?->name,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['status'] === ReportTemplateStatus::Pending ? 0 : 1, mb_strtolower($a['name']), $a['template']->id]
            <=> [$b['status'] === ReportTemplateStatus::Pending ? 0 : 1, mb_strtolower($b['name']), $b['template']->id]);

        return $rows;
    }
}
