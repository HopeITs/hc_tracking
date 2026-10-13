<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Models\HrCity;
use App\Models\HrEmployee;
use App\Models\HrProgram;
use App\Models\Project;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The project directory's filters: the reset hooks, the status and lens
 * toggles, and the one place the portfolio is narrowed (applyFilters), plus the
 * lifecycle and triage counts that read through it.
 *
 * Used by projects/index only. The page keeps the #[Url] filter properties
 * (search, city, program, strategy, owner, status, view, sort, lens); the list
 * query (`projects()`) and the funding figures call applyFilters() from here.
 *
 * @property string $search
 * @property string $city
 * @property string $program
 * @property string $strategy
 * @property string $owner
 * @property string $status
 * @property string $view
 * @property string $sort
 * @property string $lens
 * @property-read array{cities: array<int>, owners: list<int>} $searchMatches  #[Computed] searchMatches() below
 */
trait FiltersProjectDirectory
{
    /** The views this page can render the filtered portfolio in. */
    private const VIEWS = ['board', 'timeline', 'table'];

    /** The orders the portfolio can be read in. */
    private const SORTS = ['name', 'start_date', 'end_date', 'updated_at'];

    /** The single-select triage lenses of the cockpit strip. */
    private const LENSES = ['unfunded', 'overdue', 'slipping', 'risks'];

    /**
     * The cities the directory can be filtered by.
     *
     * @return Collection<int, HrCity>
     */
    #[Computed]
    public function cityOptions(): Collection
    {
        return HrCity::query()->select(['id', 'name_en', 'name_ar'])->orderBy(HrCity::nameColumn())->orderBy('id')->get();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCity(): void
    {
        $this->resetPage();
    }

    public function updatedProgram(): void
    {
        $this->resetPage();
    }

    public function updatedStrategy(): void
    {
        $this->resetPage();
    }

    public function updatedOwner(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * Fall back to the board rather than rendering nothing for a view name
     * that is not one of ours.
     */
    public function updatedView(): void
    {
        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'board';
        }
    }

    public function updatedSort(): void
    {
        if (! in_array($this->sort, self::SORTS, true)) {
            $this->sort = 'name';
        }

        $this->resetPage();
    }

    public function updatedLens(): void
    {
        if (! in_array($this->lens, self::LENSES, true)) {
            $this->lens = '';
        }

        $this->resetPage();
    }

    /**
     * Select a lifecycle segment, or clear it when it is already selected.
     */
    public function toggleStatus(string $status): void
    {
        $this->status = $this->status === $status ? '' : $status;
        $this->resetPage();
    }

    /**
     * Lenses are single-select: combining them produces empty sets that read
     * as a bug rather than as an answer.
     */
    public function toggleLens(string $lens): void
    {
        $this->lens = $this->lens === $lens || ! in_array($lens, self::LENSES, true) ? '' : $lens;
        $this->resetPage();
    }

    /**
     * Reset every filter, including the lens.
     *
     * `view` and `sort` survive: they are reading preferences, not filters.
     */
    public function clearFilters(): void
    {
        $this->search = '';
        $this->city = '';
        $this->program = '';
        $this->strategy = '';
        $this->owner = '';
        $this->status = '';
        $this->lens = '';
        $this->resetPage();
    }

    /**
     * The HR ids a name search resolves to, read once per request.
     *
     * Cities and employees live in the HR database, so a name search resolves
     * to ids here instead of a whereHas() across connections. Memoising them
     * keeps `applyFilters()` free to run for the list, the rail and the
     * triage strip without paying for the same two reads each time.
     *
     * @return array{cities: array<int>, owners: list<int>}
     */
    #[Computed]
    public function searchMatches(): array
    {
        $search = trim($this->search);

        if ($search === '') {
            return ['cities' => [], 'owners' => []];
        }

        return [
            'cities' => HrCity::query()
                ->where('name_en', 'like', Like::contains($search))
                ->orWhere('name_ar', 'like', Like::contains($search))
                ->pluck('id')
                ->all(),
            'owners' => HrEmployee::idsMatchingName($search),
        ];
    }

    /**
     * The one place the portfolio is narrowed down.
     *
     * The list, the lifecycle rail and the triage tiles all read through this
     * method, so a filter can never mean one thing in the board and another in
     * the counts above it.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    protected function applyFilters(Builder $query, bool $includeStatus = true, bool $includeLens = true): Builder
    {
        $search = trim($this->search);
        $matches = $this->searchMatches;

        return $query
            ->when($search !== '', function (Builder $query) use ($search, $matches): void {
                $query->where(function (Builder $query) use ($search, $matches): void {
                    $query
                        ->where('name', 'like', Like::contains($search))
                        ->orWhereIn('city_id', $matches['cities'])
                        ->orWhereIn('program_id', HrProgram::idsMatchingName($search))
                        ->orWhereIn('user_id', $matches['owners']);
                });
            })
            ->when($this->city !== '', fn (Builder $query) => $query->where('city_id', (int) $this->city))
            ->when($this->program !== '', fn (Builder $query) => $query->where('program_id', (int) $this->program))
            ->when($this->strategy !== '', fn (Builder $query) => $query->whereHas('program', fn ($query) => $query->where('strategy_id', (int) $this->strategy)))
            ->when($this->owner !== '', fn (Builder $query) => $query->where('user_id', (int) $this->owner))
            ->when($includeStatus && $this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($includeLens && $this->lens !== '', fn (Builder $query) => $this->applyLens($query));
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    protected function applyLens(Builder $query): Builder
    {
        return match ($this->lens) {
            'unfunded' => $query->doesntHave('proposals'),
            'overdue' => $query->where('status', ProjectStatus::Active->value)->whereDate('end_date', '<', today()),
            'slipping' => $query->whereHas('milestones', fn ($query) => $query
                ->whereDate('planned_start_date', '<', today())
                ->whereNotIn('status', [MilestoneStatus::Reached->value, MilestoneStatus::Cancelled->value])),
            'risks' => $query->whereHas('risks', fn ($query) => $query->where('happened', true)),
            default => $query,
        };
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    protected function applySort(Builder $query): Builder
    {
        if ($this->view === 'board') {
            $cases = ProjectStatus::cases();
            $arms = [];

            foreach (array_keys($cases) as $position) {
                $arms[] = 'when status = ? then '.$position;
            }

            $query->orderByRaw('case '.implode(' ', $arms).' else '.count($cases).' end', array_column($cases, 'value'));
        }

        // Nulls sort last in both date orders, which is what the raw
        // "is null" ordering in front of each column buys.
        return match ($this->sort) {
            'start_date' => $query->orderByRaw('start_date is null')->orderBy('start_date')->orderBy('name'),
            'end_date' => $query->orderByRaw('end_date is null')->orderBy('end_date')->orderBy('name'),
            'updated_at' => $query->orderByDesc('updated_at')->orderByDesc('id'),
            default => $query->orderBy('name')->orderBy('id'),
        };
    }

    /**
     * How many projects sit in each lifecycle state.
     *
     * The rail ignores the status filter so it always shows where the rest of
     * the filtered portfolio sits; every other filter still applies.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        return $this->applyFilters(Project::query(), includeStatus: false)
            ->toBase()
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * The four triage counts, in one read.
     *
     * They ignore both the status filter and the active lens: a lens that
     * shrank its own tile would make the number jump the moment it is clicked
     * and the strip would read as broken.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function triageCounts(): array
    {
        $today = today()->toDateString();

        $row = $this->applyFilters(Project::query(), includeStatus: false, includeLens: false)
            ->toBase()
            ->selectRaw(
                'sum(case when not exists (select 1 from project_proposal where project_proposal.project_id = projects.id) then 1 else 0 end) as unfunded_count, '
                .'sum(case when projects.status = ? and projects.end_date < ? then 1 else 0 end) as overdue_count, '
                .'sum(case when exists (select 1 from milestones where milestones.project_id = projects.id and milestones.planned_start_date < ? and milestones.status not in (?, ?)) then 1 else 0 end) as slipping_count, '
                .'sum(case when exists (select 1 from risks where risks.project_id = projects.id and risks.happened = 1) then 1 else 0 end) as risks_count',
                [
                    ProjectStatus::Active->value,
                    $today,
                    $today,
                    MilestoneStatus::Reached->value,
                    MilestoneStatus::Cancelled->value,
                ],
            )
            ->first();

        return [
            'unfunded' => (int) ($row->unfunded_count ?? 0),
            'overdue' => (int) ($row->overdue_count ?? 0),
            'slipping' => (int) ($row->slipping_count ?? 0),
            'risks' => (int) ($row->risks_count ?? 0),
        ];
    }
}
