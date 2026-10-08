<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Targets;

use App\Models\AnnualPlan;
use App\Models\Donor;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Support\Like;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

/**
 * The target directory's filters: the reset hooks, the achievement toggle, the
 * one query every filter narrows (filteredTargets), the header options, and
 * the pulse, count and result page read through it.
 *
 * Used by targets/index only. The page keeps the #[Url] filter properties.
 * updatedTargetSourceType() resets the form's source and calls
 * syncFormProject() from NarrowsTargetFormOptions; ReadsTargets loads the visible
 * targets through matchingTargets() and resultPage. The page provides
 * resetPage() (WithPagination).
 *
 * @property string $search
 * @property string $sourceType
 * @property string $project
 * @property string $period
 * @property string $status
 * @property string $donor
 * @property string $program
 * @property string $year
 * @property string $indicator
 * @property string $result
 * @property string $targetSourceId
 * @property-read array<int, array{id: int, label: string}> $sourceOptions
 * @property-read Collection<int, Project> $formProjectOptions
 */
trait FiltersTargets
{
    /**
     * Achievement statuses, derived from approved reports against the target.
     */
    public const STATUSES = ['achieved', 'in_progress', 'not_started'];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearIndicatorScope(): void
    {
        $this->indicator = '';
        $this->result = '';
        $this->resetPage();
    }

    public function updatedSourceType(): void
    {
        $this->resetPage();
    }

    public function updatedProject(): void
    {
        $this->resetPage();
    }

    public function updatedPeriod(): void
    {
        $this->resetPage();
    }

    public function updatedDonor(): void
    {
        $this->resetPage();
    }

    public function updatedProgram(): void
    {
        $this->resetPage();
    }

    public function updatedYear(): void
    {
        $this->resetPage();
    }

    /**
     * Toggle the achievement filter from the pulse; choosing the active one clears it.
     */
    public function filterStatus(string $status): void
    {
        $this->status = $this->status === $status || ! in_array($status, self::STATUSES, true) ? '' : $status;
        $this->resetPage();
    }

    /**
     * A source chosen under one type is meaningless under the other, so
     * switching type clears it rather than carrying an id across.
     */
    public function updatedTargetSourceType(): void
    {
        $this->targetSourceId = '';
        unset($this->sourceOptions, $this->formProjectOptions);
        $this->syncFormProject();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->sourceType = '';
        $this->project = '';
        $this->period = '';
        $this->status = '';
        $this->donor = '';
        $this->program = '';
        $this->year = '';
        $this->indicator = '';
        $this->result = '';
        $this->resetPage();
    }

    /**
     * Achievement is PlanIndicator's one shared rule — approved reports only;
     * numeric summed, percentage the latest level — in its SQL form, so the
     * status filter and the pulse can count on it.
     */
    private function statusSql(string $status): string
    {
        return PlanIndicator::achievementStatusSql($status);
    }

    /**
     * Targets matching every filter except achievement status.
     *
     * @return Builder<PlanIndicator>
     */
    protected function filteredTargets(): Builder
    {
        $search = trim($this->search);

        return PlanIndicator::query()
            // The indicator, the result it measures, or the proposal it was committed in.
            ->when($search !== '', function ($query) use ($search): void {
                $like = Like::contains($search);

                $query->where(fn ($query) => $query
                    ->whereHas('indicator', fn ($query) => $query
                        ->where('name', 'like', $like)
                        ->orWhereHas('result', fn ($query) => $query->where('statement', 'like', $like)))
                    ->orWhere(fn ($query) => $query
                        ->where('source_type', 'proposal')
                        ->whereIn('source_id', Proposal::query()->select('id')->where('name', 'like', $like))));
            })
            ->when($this->indicator !== '', fn ($query) => $query->where('indicator_id', (int) $this->indicator))
            ->when($this->result !== '', fn ($query) => $query
                ->whereIn('indicator_id', Indicator::query()->select('id')->where('result_id', (int) $this->result)))
            ->when($this->sourceType !== '', fn ($query) => $query->where('source_type', $this->sourceType))
            ->when($this->project === 'none', fn ($query) => $query->whereNull('project_id'))
            ->when(
                $this->project !== '' && $this->project !== 'none',
                fn ($query) => $query->where('project_id', (int) $this->project)
            )
            ->when($this->period !== '', fn ($query) => $query->where('period', $this->period))
            // A donor funds proposals, so only proposal targets can carry one.
            ->when($this->donor !== '', fn ($query) => $query
                ->where('source_type', 'proposal')
                ->whereIn('source_id', DB::table('donor_proposal')->select('proposal_id')->where('donor_id', (int) $this->donor)))
            // A program owns a target through its annual plan, the project delivering
            // it, or the result it measures — so an organisation-wide proposal target
            // still shows under the program whose logframe it serves.
            ->when($this->program !== '', fn ($query) => $query->where(function ($query): void {
                $programId = (int) $this->program;

                $query
                    ->where(fn ($query) => $query
                        ->where('source_type', 'annual_plan')
                        ->whereIn('source_id', AnnualPlan::query()->select('id')->where('program_id', $programId)))
                    ->orWhereIn('project_id', Project::query()->select('id')->where('program_id', $programId))
                    ->orWhereIn('indicator_id', Indicator::query()->select('indicators.id')
                        ->join('results', 'results.id', '=', 'indicators.result_id')
                        ->where('results.program_id', $programId));
            }))
            // A plan belongs to one year; a proposal covers every year its planned dates span.
            ->when($this->year !== '', fn ($query) => $query->where(function ($query): void {
                $year = (int) $this->year;

                $query
                    ->where(fn ($query) => $query
                        ->where('source_type', 'annual_plan')
                        ->whereIn('source_id', AnnualPlan::query()->select('id')->where('year', $year)))
                    ->orWhere(fn ($query) => $query
                        ->where('source_type', 'proposal')
                        ->whereIn('source_id', Proposal::query()
                            ->select('id')
                            ->whereYear('planned_date_begin', '<=', $year)
                            ->whereYear('planned_date_end', '>=', $year)));
            }));
    }

    /** @return Collection<int, Donor> */
    #[Computed]
    public function donorOptions(): Collection
    {
        return Donor::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, Program> */
    #[Computed]
    public function programOptions(): Collection
    {
        return Program::options();
    }

    /**
     * Every year a plan or proposal covers, newest first.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function yearOptions(): array
    {
        $planYears = AnnualPlan::query()->distinct()->pluck('year')->map(fn ($year): int => (int) $year);

        $proposalYears = Proposal::query()
            ->select(['planned_date_begin', 'planned_date_end'])
            ->get()
            ->flatMap(fn (Proposal $proposal): array => range(
                $proposal->planned_date_begin->year,
                max($proposal->planned_date_begin->year, $proposal->planned_date_end->year)
            ));

        return $planYears->merge($proposalYears)->unique()->sortDesc()->values()->all();
    }

    /**
     * Targets matching every filter, including achievement status.
     *
     * @return Builder<PlanIndicator>
     */
    protected function matchingTargets(): Builder
    {
        return $this->filteredTargets()->when(
            in_array($this->status, self::STATUSES, true),
            fn ($query) => $query->whereRaw($this->statusSql($this->status))
        );
    }

    /**
     * Portfolio-level counts for the pulse, over the filtered set.
     *
     * @return array{total: int, achieved: int, in_progress: int, not_started: int, plans: int, proposals: int, reviewed: int, drafts: int}
     */
    #[Computed]
    public function pulse(): array
    {
        $row = $this->filteredTargets()
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(case when '.$this->statusSql('achieved').' then 1 else 0 end), 0) as achieved')
            ->selectRaw('coalesce(sum(case when '.$this->statusSql('in_progress').' then 1 else 0 end), 0) as in_progress')
            ->selectRaw("coalesce(sum(case when source_type = 'annual_plan' then 1 else 0 end), 0) as plans")
            ->selectRaw("coalesce(sum(case when source_type = 'proposal' then 1 else 0 end), 0) as proposals")
            ->selectRaw("coalesce(sum((select count(*) from reports r where r.plan_indicator_id = plan_indicators.id and r.status = 'reviewed')), 0) as reviewed")
            ->selectRaw("coalesce(sum((select count(*) from reports r where r.plan_indicator_id = plan_indicators.id and r.status = 'draft')), 0) as drafts")
            ->first();

        $total = (int) $row->total;
        $achieved = (int) $row->achieved;
        $inProgress = (int) $row->in_progress;

        return [
            'total' => $total,
            'achieved' => $achieved,
            'in_progress' => $inProgress,
            'not_started' => max(0, $total - $achieved - $inProgress),
            'plans' => (int) $row->plans,
            'proposals' => (int) $row->proposals,
            'reviewed' => (int) $row->reviewed,
            'drafts' => (int) $row->drafts,
        ];
    }

    /**
     * How many targets match every filter, for the header count.
     */
    #[Computed]
    public function matchingCount(): int
    {
        return $this->matchingTargets()->count();
    }

    /**
     * One page of results that have matching targets.
     *
     * The page is cut by result, not by target, so a result's targets are
     * never split across two pages.
     *
     * @return LengthAwarePaginator<int, Indicator>
     */
    #[Computed]
    public function resultPage(): LengthAwarePaginator
    {
        return Indicator::query()
            ->whereIn('id', $this->matchingTargets()->select('indicator_id'))
            ->select('result_id')
            ->distinct()
            ->orderBy('result_id')
            ->paginate(6, ['result_id']);
    }
}
