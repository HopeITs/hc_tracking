<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Enums\AssumptionStatus;
use App\Enums\ResultType;
use App\Models\Assumption;
use App\Models\HrCenter;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\ProjectActivityStatus;
use App\Models\Result;
use App\Models\Risk;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The proposal page's logical framework box, read once per request and
 * shaped for the three layouts (Results chain, Matrix, Activity board).
 *
 * Builds on BuildsResultsFramework::allResults() (results with indicators,
 * targets and reports) and adds risks, assumptions and the activities'
 * statuses in three more queries. Nothing here queries per row.
 *
 * @property-read Collection<int, Result> $allResults  #[Computed] allResults()
 * @property-read array{results: array<int, array<string, mixed>>, activities: array<int, array<string, mixed>>, roots: list<int>, children: array<int, list<int>>, counts: array<string, int>} $framework  #[Computed] framework()
 */
trait ReadsProposalFramework
{
    /** The layers a result can show, in chip order. */
    public const LAYERS = ['indicators', 'targets', 'activities', 'risks', 'assumptions'];

    /**
     * @return array{results: array<int, array<string, mixed>>, activities: array<int, array<string, mixed>>, roots: list<int>, children: array<int, list<int>>, counts: array<string, int>}
     */
    #[Computed]
    public function framework(): array
    {
        $today = CarbonImmutable::today();
        $all = $this->allResults;
        $ids = $all->pluck('id')->all();

        $risks = Risk::query()->select(['id', 'name', 'result_id', 'happened', 'likelihood', 'severity'])->where('proposal_id', $this->proposalId)->orderBy('id')->get()->groupBy('result_id');
        $assumptions = Assumption::query()->where('proposal_id', $this->proposalId)->orderBy('id')->get()->groupBy('result_id');
        $statuses = ProjectActivityStatus::query()->whereIn('result_id', $ids)->get(['result_id', 'status'])->groupBy('result_id');

        $activities = [];
        $results = [];
        $children = [];
        $counts = ['indicators' => 0, 'targets' => 0, 'activities' => 0, 'risks' => 0, 'assumptions' => 0];

        foreach ($all as $result) {
            $indicators = $result->indicators->map(fn (Indicator $indicator): array => $this->describeIndicator($indicator))->values()->all();

            if ($result->type === ResultType::Activity) {
                $activities[$result->id] = $this->describeActivity($result, $indicators, $statuses->get($result->id), $today);
                $counts['activities']++;

                continue;
            }

            $children[$result->parent_id ?? 0][] = $result->id;
            $counts['indicators'] += count($indicators);
            $counts['targets'] += array_sum(array_map(fn (array $indicator): int => count($indicator['centres']), $indicators));

            $results[$result->id] = [
                'id' => $result->id,
                'type' => $result->type,
                'parent' => $result->parent_id,
                'statement' => $result->statement,
                'indicators' => $indicators,
                'risks' => ($risks->get($result->id) ?? collect())->map(fn (Risk $risk): array => [
                    'id' => $risk->id, 'name' => $risk->name, 'happened' => $risk->happened, 'likelihood' => $risk->likelihood, 'severity' => $risk->severity,
                ])->values()->all(),
                'assumptions' => ($assumptions->get($result->id) ?? collect())->map(fn (Assumption $assumption): array => [
                    'id' => $assumption->id, 'statement' => $assumption->statement, 'status' => $assumption->status,
                ])->values()->all(),
                'activities' => [],
            ];
        }

        foreach ($activities as $activity) {
            if ($activity['parent'] !== null && isset($results[$activity['parent']])) {
                $results[$activity['parent']]['activities'][] = $activity['id'];
            }
        }

        foreach ($results as $id => $result) {
            $results[$id]['issues'] = $this->issuesOf($result, $activities);
        }

        $counts['risks'] = $risks->flatten()->count();
        $counts['assumptions'] = $assumptions->flatten()->count();

        // A result whose parent is not in this framework is shown as a root.
        $roots = array_values(array_filter(array_keys($results), fn (int $id): bool => $results[$id]['parent'] === null || ! isset($results[$results[$id]['parent']])));

        return ['results' => $results, 'activities' => $activities, 'roots' => $roots, 'children' => $children, 'counts' => $counts];
    }

    /**
     * @return array{id: int, name: string, unit: string|null, target: float|null, actual: float|null, percent: float|null, frequency: string, centres: list<array{name: string, target: float}>, unreported: bool}
     */
    private function describeIndicator(Indicator $indicator): array
    {
        $proposalTargets = $indicator->planIndicators->filter(fn (PlanIndicator $target): bool => $target->source_type === 'proposal' && $target->source_id === $this->proposalId);

        return [
            'id' => $indicator->id,
            'name' => $indicator->name,
            'unit' => $indicator->unit,
            'target' => $indicator->target === null ? null : (float) $indicator->target,
            'actual' => $indicator->progress->last()?->value === null ? null : (float) $indicator->progress->last()->value,
            'percent' => $indicator->percentAchieved(),
            'frequency' => $indicator->frequency->label(),
            'centres' => $proposalTargets
                ->filter(fn (PlanIndicator $target): bool => $target->center_id !== null)
                ->map(fn (PlanIndicator $target): array => ['name' => HrCenter::remembered($target->center_id)?->name ?? '#'.$target->center_id, 'target' => (float) $target->target])
                ->values()->all(),
            'unreported' => $proposalTargets->isNotEmpty() && $proposalTargets->every(fn (PlanIndicator $target): bool => $target->reports->isEmpty()),
        ];
    }

    /**
     * The activity's schedule and its status read from the projects:
     * Done once any project finished it, In progress once any started it,
     * Late when its own end date passed before it was done.
     *
     * @param  list<array<string, mixed>>  $indicators
     * @param  \Illuminate\Support\Collection<int, ProjectActivityStatus>|null  $rows
     * @return array<string, mixed>
     */
    private function describeActivity(Result $result, array $indicators, $rows, CarbonImmutable $today): array
    {
        $values = collect($rows ?? [])->map(fn (ProjectActivityStatus $row): ?ActivityStatus => $row->status);
        $done = $values->contains(fn (?ActivityStatus $status): bool => in_array($status, [ActivityStatus::Completed, ActivityStatus::Conducted], true));
        $started = $values->contains(fn (?ActivityStatus $status): bool => in_array($status, [ActivityStatus::Started, ActivityStatus::Ongoing], true));

        $status = match (true) {
            $done => 'done',
            $result->ends_on !== null && $result->ends_on->lt($today) => 'late',
            $started => 'progress',
            default => 'not_started',
        };

        return [
            'id' => $result->id,
            'name' => $result->statement,
            'parent' => $result->parent_id,
            'mode' => $result->starts_on !== null ? 'dates' : ($result->activity_kind === ActivityKind::Event ? 'event' : 'continuous'),
            'starts' => $result->starts_on,
            'ends' => $result->ends_on,
            'status' => $status,
            'indicators' => $indicators,
        ];
    }

    /**
     * The red chips on a result's header.
     *
     * @param  array<string, mixed>  $result
     * @param  array<int, array<string, mixed>>  $activities
     * @return list<string>
     */
    private function issuesOf(array $result, array $activities): array
    {
        $issues = [];

        if (in_array($result['type'], [ResultType::Outcome, ResultType::Output], true) && $result['indicators'] === []) {
            $issues[] = __('proposals.framework.issue_no_indicator');
        }

        $late = count(array_filter($result['activities'], fn (int $id): bool => ($activities[$id]['status'] ?? null) === 'late'));

        if ($late > 0) {
            $issues[] = trans_choice('proposals.framework.issue_late', $late, ['count' => $late]);
        }

        $happened = count(array_filter($result['risks'], fn (array $risk): bool => $risk['happened']));

        if ($happened > 0) {
            $issues[] = trans_choice('proposals.framework.issue_risks_happened', $happened, ['count' => $happened]);
        }

        $atRisk = count(array_filter($result['assumptions'], fn (array $assumption): bool => $assumption['status'] !== AssumptionStatus::Holds));

        if ($atRisk > 0) {
            $issues[] = trans_choice('proposals.framework.issue_assumptions', $atRisk, ['count' => $atRisk]);
        }

        $unreported = count(array_filter($result['indicators'], fn (array $indicator): bool => $indicator['unreported']));

        if ($unreported > 0) {
            $issues[] = trans_choice('proposals.framework.issue_unreported', $unreported, ['count' => $unreported]);
        }

        return $issues;
    }
}
