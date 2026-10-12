<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Reports;

use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Report;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;

/**
 * The portfolio side of reports/index: the pulse figures above the feed, the
 * project-and-target grouping behind the portfolio view, and the label of the
 * target the feed is narrowed to.
 *
 * Used by reports/index only. The page provides the #[Url] view and target
 * properties and the private query helpers scopedReports(), visibleReports()
 * and targetRelations() (shared with its paginated list). ManagesReportForm
 * provides targetLabel().
 *
 * @property string $view
 * @property string $target
 */
trait ReadsReportPortfolio
{
    /**
     * @return Builder<Report>
     */
    abstract private function scopedReports(): Builder;

    /**
     * @return Builder<Report>
     */
    abstract private function visibleReports(): Builder;

    /**
     * @return array<int, mixed>
     */
    abstract private function targetRelations(): array;

    abstract protected function targetLabel(PlanIndicator $target): string;

    /**
     * The portfolio pulse: how the reports matching the filters break down by
     * status and MEAL visibility, how many drafts are late, and the average
     * achievement of the targets they report against.
     *
     * Achievement is averaged per target, not per report — a target reported
     * quarterly must not weigh four times as much as one reported yearly — and
     * each target is capped at 150% first, so one wildly over-achieved target
     * cannot drag the average somewhere meaningless.
     *
     * @return array{total: int, statusCounts: SupportCollection<string, int>, seen: int, unseen: int, overdue: int, avgAchievement: int|null}
     */
    #[Computed]
    public function insights(): array
    {
        $statusCounts = $this->scopedReports()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = (int) $statusCounts->sum();
        $unseen = (int) $this->scopedReports()->where('is_seen_by_meal', false)->count();
        $overdue = (int) $this->scopedReports()->overdue()->count();

        $achievements = PlanIndicator::query()
            ->whereIn('id', $this->scopedReports()->select('plan_indicator_id'))
            ->where('target', '>', 0)
            ->selectRaw('target, '.PlanIndicator::achievedValueSql().' as achieved')
            ->toBase()
            ->get()
            ->map(fn (object $row): float => min(150.0, (float) $row->achieved / (float) $row->target * 100));

        return [
            'total' => $total,
            'statusCounts' => $statusCounts,
            'seen' => $total - $unseen,
            'unseen' => $unseen,
            'overdue' => $overdue,
            'avgAchievement' => $achievements->isEmpty() ? null : (int) round($achievements->avg()),
        ];
    }

    /**
     * Reports grouped by project, then by target, for the portfolio view.
     *
     * Both levels sort worst-achievement-first: a manager opening the
     * portfolio should see what needs attention before anything else,
     * without having to hunt for it. A project or target with no scorable
     * achievement (no positive target) sorts after every scored one, then
     * alphabetically, so it is never mistaken for a top performer.
     *
     * Only queried when that view is active: the list view's paginated
     * `reports()` stays the cheap default. Every filter the list respects
     * applies here too, so switching views never changes what is being looked
     * at, only how it is shaped.
     *
     * @return SupportCollection<int, array{project: Project|null, reportCount: int, avgAchievement: int|null, indicators: SupportCollection<int, array{planIndicator: PlanIndicator, reports: Collection<int, Report>, latest: Report, trend: array<int, float>, achievement: int|null}>}>
     */
    #[Computed]
    public function portfolio(): SupportCollection
    {
        if ($this->view !== 'portfolio') {
            return collect();
        }

        $reports = $this->visibleReports()
            ->select(['id', 'plan_indicator_id', 'user_id', 'status', 'planned_date', 'value', 'submit_date', 'is_seen_by_meal'])
            ->with($this->targetRelations())
            ->orderByDesc('planned_date')
            ->orderByDesc('id')
            ->get();

        return $reports
            ->groupBy(fn (Report $report): int => $report->planIndicator->project_id ?? 0)
            ->map(function (Collection $projectReports): array {
                $indicators = $projectReports
                    ->groupBy('plan_indicator_id')
                    ->map(function (Collection $rows): array {
                        /** @var PlanIndicator $planIndicator */
                        $planIndicator = $rows->first()->planIndicator;
                        $percent = $planIndicator->percentAchieved();

                        return [
                            'planIndicator' => $planIndicator,
                            'reports' => $rows->values(),
                            'latest' => $rows->first(),
                            // Oldest first, for a sparkline that reads left-to-right in time.
                            'trend' => $rows->sortBy('planned_date')->pluck('value')->map(fn ($value): float => (float) $value)->values()->all(),
                            'achievement' => $percent === null ? null : (int) floor($percent),
                        ];
                    })
                    // A stable sort: the alphabetical pass runs first so ties in
                    // achievement (including every unscored target) fall back
                    // to it, then the achievement pass reorders around that.
                    ->sortBy(fn (array $group): string => $group['planIndicator']->indicator->name)
                    ->sortBy(fn (array $group): int => $group['achievement'] ?? PHP_INT_MAX)
                    ->values();

                $scored = $indicators->pluck('achievement')->filter(fn (?int $value): bool => $value !== null);

                return [
                    'project' => $projectReports->first()->planIndicator->project,
                    'reportCount' => $projectReports->count(),
                    'avgAchievement' => $scored->isEmpty() ? null : (int) round($scored->average()),
                    'indicators' => $indicators,
                ];
            })
            ->sortBy(fn (array $group): string => $group['project']->name ?? '')
            ->sortBy(fn (array $group): int => $group['avgAchievement'] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * The target the feed is narrowed to, labelled as the form labels it.
     */
    #[Computed]
    public function scopedTargetLabel(): ?string
    {
        if ($this->target === '') {
            return null;
        }

        $target = PlanIndicator::query()
            ->with(['indicator:id,name,measure', 'project:id,name', 'source'])
            ->find((int) $this->target);

        return $target === null ? null : $this->targetLabel($target);
    }
}
