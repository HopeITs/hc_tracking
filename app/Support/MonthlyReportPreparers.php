<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ProposalLocation;
use App\Models\ReportTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who prepares a template's monthly report (contracts §13.1 item 9, D-84):
 * the `responsible_employee_id` of a location row of one of the template's
 * proposals at the template's place, or a supervisor of such a responsible.
 *
 * "At the template's place" = a row of the same city and program that names
 * the template's center, or is `city_wide` when the template is (D-88). For
 * a template whose center is not set (D-82), every row of its proposals in
 * that city and program counts, so a migrated report always has a preparer.
 * This deliberately does not use ReportPlace::equals(), which equals nothing
 * for an unset place. A joined template may have several responsibles; any
 * of them (and any of their supervisors) prepares.
 */
final class MonthlyReportPreparers
{
    /**
     * The responsible employees of the template's rows at its place.
     *
     * @return list<int>
     */
    public function responsibleEmployeeIds(ReportTemplate $template): array
    {
        return $this->responsiblesByTemplate([$template])[$template->id] ?? [];
    }

    /**
     * The same, for many templates in a fixed number of queries.
     *
     * @param  iterable<ReportTemplate>  $templates
     * @return array<int, list<int>> template id => responsible employee ids
     */
    public function responsiblesByTemplate(iterable $templates): array
    {
        $templates = collect($templates)->keyBy('id');

        if ($templates->isEmpty()) {
            return [];
        }

        $links = DB::table('proposal_report_template')
            ->whereIn('report_template_id', $templates->keys()->all())
            ->get(['report_template_id', 'proposal_id']);

        $rows = ProposalLocation::query()
            ->with('centers')
            ->whereIn('proposal_id', $links->pluck('proposal_id')->unique()->values()->all())
            ->whereNotNull('responsible_employee_id')
            ->get(['id', 'proposal_id', 'city_id', 'program_id', 'city_wide', 'responsible_employee_id']);

        $result = [];

        foreach ($templates as $id => $template) {
            $place = $template->place();
            $proposalIds = $links->where('report_template_id', $id)->pluck('proposal_id')->map(fn (mixed $value): int => (int) $value)->all();

            $result[$id] = array_values($rows
                ->filter(fn (ProposalLocation $row): bool => in_array($row->proposal_id, $proposalIds, true)
                    && $row->city_id === $place->cityId
                    && $row->program_id === $place->programId
                    && match (true) {
                        $place->cityWide => $row->city_wide,
                        $place->centerId !== null => $row->centers->contains('center_id', $place->centerId),
                        default => true,
                    })
                ->pluck('responsible_employee_id')
                ->map(fn (mixed $value): int => (int) $value)
                ->unique()
                ->sort()
                ->all());
        }

        return $result;
    }

    /**
     * The templates the user is on the preparer side of: a responsible of a
     * row at the template's place, or a supervisor of one. What "My
     * reports" lists. A fixed number of queries.
     *
     * @return list<int>
     */
    public function templateIdsFor(User $user): array
    {
        if ($user->employee_id === null) {
            return [];
        }

        $employees = [$user->employee_id, ...SupervisionMap::superviseesOf($user->employee_id)];
        $proposalIds = ProposalLocation::query()
            ->whereIn('responsible_employee_id', $employees)
            ->distinct()
            ->pluck('proposal_id')
            ->all();

        if ($proposalIds === []) {
            return [];
        }

        $templates = ReportTemplate::query()
            ->whereIn('id', DB::table('proposal_report_template')->whereIn('proposal_id', $proposalIds)->select('report_template_id'))
            ->orderBy('id')
            ->get(['id', 'city_id', 'program_id', 'center_id', 'city_wide']);

        $responsibles = $this->responsiblesByTemplate($templates);

        return array_values(array_map(
            fn (ReportTemplate $template): int => $template->id,
            array_filter($templates->all(), fn (ReportTemplate $template): bool => array_intersect($responsibles[$template->id] ?? [], $employees) !== []),
        ));
    }

    /**
     * Whether the user is on the preparer side of the template: one of its
     * responsibles, or a supervisor of one. Permissions are checked by the
     * policy.
     */
    public function isPreparer(User $user, ReportTemplate $template): bool
    {
        return $this->isPreparerAmong($user, $this->responsibleEmployeeIds($template));
    }

    /**
     * The same check over responsibles already read (responsiblesByTemplate()).
     *
     * @param  list<int>  $responsibles
     */
    public function isPreparerAmong(User $user, array $responsibles): bool
    {
        if ($user->employee_id === null || $responsibles === []) {
            return false;
        }

        if (in_array($user->employee_id, $responsibles, true)) {
            return true;
        }

        foreach (SupervisionMap::supervisorsOf($responsibles) as $supervisors) {
            if (in_array($user->employee_id, $supervisors, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the template has a center and none of its responsibles sits at
     * it in HR now. A warning only, never a refusal (replaces the owner check).
     */
    public function centerMismatch(ReportTemplate $template): bool
    {
        if ($template->center_id === null) {
            return false;
        }

        $responsibles = $this->responsibleEmployeeIds($template);

        if ($responsibles === []) {
            return false;
        }

        foreach ($responsibles as $employeeId) {
            $centerId = EmployeeProfile::for($employeeId)['center_id'] ?? null;

            if ($centerId === null || $centerId === $template->center_id) {
                return false;
            }
        }

        return true;
    }
}
