<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use Illuminate\Validation\ValidationException;

/**
 * "Centers that already report" (contracts.md §6, Y-09, D-89a): a change
 * request may not move or remove a center, or turn a row city-wide or back,
 * when a report template of this proposal at the place it leaves has a
 * monthly report that is `submitted` or `validated`. Checked when the
 * request is opened and again when it is approved.
 *
 * Templates are read through `ReportTemplate::atPlace()` and the proposal
 * pivot (D-74); a template's reports through PlaceBridge until Y-17. A row
 * still waiting for its program has no place yet and reports nothing.
 */
final class ReportingCenters
{
    public function __construct(private readonly PlaceBridge $bridge) {}

    /**
     * Refuse the change when a place it leaves has a submitted or validated
     * report; otherwise return the ids of the templates of this proposal at
     * the places the change leaves, for MEAL to settle (they are not moved).
     *
     * @param  list<array{field: string, from: mixed, to: mixed, row?: array{city_id: int, program_id: int|null}}>  $changes
     * @return list<int> template ids, ascending
     *
     * @throws ValidationException `locations` => `proposals.location_center_reports`
     */
    public function guard(Proposal $proposal, array $changes): array
    {
        $templates = $this->templatesAt($proposal, $this->placesLeft($changes));

        if ($templates === []) {
            return [];
        }

        $projectIds = array_values(array_filter($this->bridge->legacyProjectIdsFor($templates)));

        $reported = $projectIds !== [] && MonthlyReport::query()
            ->whereIn('project_id', $projectIds)
            ->whereIn('status', [MonthlyReportStatus::Submitted->value, MonthlyReportStatus::Validated->value])
            ->exists();

        if ($reported) {
            throw ValidationException::withMessages(['locations' => __('proposals.location_center_reports')]);
        }

        $ids = array_map(fn (ReportTemplate $template): int => $template->id, $templates);
        sort($ids);

        return $ids;
    }

    /**
     * The places each location change leaves: a center dropped from the row,
     * the city-wide place of a row that stops being city-wide, every place of
     * a dropped row. A responsible-only change leaves no place.
     *
     * @param  list<array{field: string, from: mixed, to: mixed, row?: array{city_id: int, program_id: int|null}}>  $changes
     * @return list<ReportPlace>
     */
    private function placesLeft(array $changes): array
    {
        $places = [];

        foreach ($changes as $change) {
            if ($change['field'] !== 'locations' || ! is_array($change['from']) || ($change['row']['program_id'] ?? null) === null) {
                continue;
            }

            $cityId = (int) $change['row']['city_id'];
            $programId = (int) $change['row']['program_id'];
            $from = $change['from'];
            $to = is_array($change['to']) ? $change['to'] : null;

            if ($from['city_wide'] && ($to === null || ! $to['city_wide'])) {
                $places[] = new ReportPlace($cityId, $programId, null, true);
            }

            $kept = $to === null || $to['city_wide'] ? [] : $to['center_ids'];

            foreach (array_diff($from['center_ids'], $kept) as $centerId) {
                $places[] = new ReportPlace($cityId, $programId, (int) $centerId, false);
            }
        }

        return $places;
    }

    /**
     * The proposal's templates at the given places, one query per place.
     *
     * @param  list<ReportPlace>  $places
     * @return list<ReportTemplate>
     */
    private function templatesAt(Proposal $proposal, array $places): array
    {
        $templates = [];

        foreach ($places as $place) {
            $found = ReportTemplate::query()
                ->atPlace($place)
                ->whereHas('proposals', fn ($query) => $query->whereKey($proposal->id))
                ->get();

            foreach ($found as $template) {
                $templates[$template->id] = $template;
            }
        }

        return array_values($templates);
    }
}
