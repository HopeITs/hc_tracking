<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Enums\ProjectStatus;
use App\Models\HrCity;
use App\Models\HrEmployee;
use App\Models\Program;
use App\Models\ProposalLocation;
use App\Models\Sector;
use App\Support\Desk\ProjectStage;
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
 * Y-16 (D-93): the directory lists **location rows**. Every filter keeps its
 * URL name so saved links keep working, and each reads the row's own twin of
 * what it read on the project: `owner` the responsible employee, `name` the
 * row's proposal (a row is of exactly one), `unfunded` a row whose proposal
 * has no agreement yet.
 *
 * @property string $search
 * @property string $city
 * @property string $program
 * @property string $strategy
 * @property string $owner
 * @property string $sector
 * @property string $status
 * @property string $view
 * @property string $sort
 * @property string $lens
 * @property string $stage
 * @property-read array{cities: array<string, list<int>>, owners: array<string, list<int>>} $searchMatches  #[Computed] searchMatches() below
 */
trait FiltersProjectDirectory
{
    /** The views this page can render the filtered portfolio in. */
    private const VIEWS = ['list', 'timeline'];

    /** The orders the portfolio can be read in. */
    private const SORTS = ['name', 'start_date', 'end_date', 'updated_at'];

    /** The single-select triage lenses of the cockpit strip. */
    private const LENSES = ['unfunded', 'overdue', 'risks'];

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

    /**
     * Every sector for the filter, inactive ones included (older projects
     * still carry them), in the managers' order. Loaded once per request.
     *
     * @return Collection<int, Sector>
     */
    #[Computed]
    public function sectorOptions(): Collection
    {
        return Sector::query()->ordered()->get(['id', 'name_ar', 'name_en', 'is_active']);
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

    public function updatedSector(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * Only the Desk's seven stages (0-6) filter; anything else clears it.
     */
    public function updatedStage(): void
    {
        if (! in_array($this->stage, array_map('strval', range(0, 6)), true)) {
            $this->stage = '';
        }

        $this->resetPage();
    }

    /**
     * The location rows on the selected stage (App\Support\Desk\ProjectStage),
     * read once per request however many queries filter by it.
     *
     * @return list<int>
     */
    #[Computed]
    public function stageProjectIds(): array
    {
        return $this->stage === '' ? [] : ProjectStage::locationIdsAt((int) $this->stage);
    }

    /**
     * Fall back to the list rather than rendering nothing for a view name
     * that is not one of ours (old `?view=board` and `?view=table` links too).
     */
    public function updatedView(): void
    {
        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'list';
        }
    }

    /**
     * Normalise a view name read from the query string on first load, which
     * Livewire hydrates without calling updatedView().
     */
    public function mountFiltersProjectDirectory(): void
    {
        $this->updatedView();
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
        $this->sector = '';
        $this->status = '';
        $this->lens = '';
        $this->stage = '';
        $this->resetPage();
    }

    /**
     * The HR ids a name search resolves to, read once per request.
     *
     * Cities and employees live in the HR database, so a name search resolves
     * to ids here instead of a whereHas() across connections. Memoising them
     * keeps `applyFilters()` free to run for the list, the rail and the
     * triage strip without paying for the same two reads each time. Keyed by
     * each folded search word (every keyboard-layout reading), since each
     * word may be found in a different field.
     *
     * @return array{cities: array<string, list<int>>, owners: array<string, list<int>>}
     */
    #[Computed]
    public function searchMatches(): array
    {
        $search = trim($this->search);

        if ($search === '') {
            return ['cities' => [], 'owners' => []];
        }

        $cities = [];
        $owners = [];

        foreach (Like::searchWords($search) as $word) {
            $cities[$word] = HrCity::query()
                ->where(fn (Builder $query) => $query->where(Like::word('name_en', $word))->orWhere(Like::word('name_ar', $word)))
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $owners[$word] = HrEmployee::idsMatchingName($word, acrossLayouts: false);
        }

        return ['cities' => $cities, 'owners' => $owners];
    }

    /**
     * The one place the portfolio is narrowed down.
     *
     * The list, the lifecycle rail and the triage tiles all read through this
     * method, so a filter can never mean one thing in the board and another in
     * the counts above it.
     *
     * @param  Builder<ProposalLocation>  $query
     * @return Builder<ProposalLocation>
     */
    protected function applyFilters(Builder $query, bool $includeStatus = true, bool $includeLens = true): Builder
    {
        $search = trim($this->search);
        $matches = $this->searchMatches;

        return $query
            ->when($search !== '', function (Builder $query) use ($search, $matches): void {
                $query->where(Like::everyWord($search, fn (Builder $query, string $word) => $query
                    // A row is named by its proposal, its place and its programme.
                    ->whereHas('proposal', fn (Builder $proposal) => $proposal->where(Like::word('proposals.name', $word)))
                    ->orWhereIn('city_id', $matches['cities'][$word] ?? [])
                    ->orWhereIn('program_id', Program::idsMatchingName($word, acrossLayouts: false))
                    ->orWhereIn('responsible_employee_id', $matches['owners'][$word] ?? [])));
            })
            ->when($this->city !== '', fn (Builder $query) => $query->where('city_id', (int) $this->city))
            ->when($this->program !== '', fn (Builder $query) => $query->where('program_id', (int) $this->program))
            ->when($this->strategy !== '', fn (Builder $query) => $query->whereHas('program', fn ($query) => $query->where('strategy_id', (int) $this->strategy)))
            ->when($this->owner !== '', fn (Builder $query) => $query->where('responsible_employee_id', (int) $this->owner))
            ->when($this->sector !== '', fn (Builder $query) => $query->whereHas('sectors', fn (Builder $query) => $query->whereKey((int) $this->sector)))
            ->when($this->stage !== '', fn (Builder $query) => $query->whereIn('proposal_locations.id', $this->stageProjectIds))
            ->when($includeStatus && $this->status !== '', fn (Builder $query) => $query->where('proposal_locations.status', $this->status))
            ->when($includeLens && $this->lens !== '', fn (Builder $query) => $this->applyLens($query));
    }

    /**
     * @param  Builder<ProposalLocation>  $query
     * @return Builder<ProposalLocation>
     */
    protected function applyLens(Builder $query): Builder
    {
        return match ($this->lens) {
            // Every row has a proposal (D-84, D-87), so "unfunded" now means
            // the place whose proposal carries no donor agreement yet.
            'unfunded' => $query->whereDoesntHave('proposal.donorProposals'),
            'overdue' => $query->where('proposal_locations.status', ProjectStatus::Active->value)->whereDate('end_date', '<', today()),
            'risks' => $query->whereHas('risks', fn ($query) => $query->where('happened', true)),
            default => $query,
        };
    }

    /**
     * @param  Builder<ProposalLocation>  $query
     * @return Builder<ProposalLocation>
     */
    protected function applySort(Builder $query): Builder
    {
        // Nulls sort last in both date orders, which is what the raw
        // "is null" ordering in front of each column buys. A row's name is
        // its proposal's (D-93), read through a join so it can be ordered on.
        $byName = fn (Builder $query): Builder => $query
            ->leftJoin('proposals', 'proposals.id', '=', 'proposal_locations.proposal_id')
            ->orderBy('proposals.name');

        return match ($this->sort) {
            'start_date' => $byName($query->orderByRaw('proposal_locations.start_date is null')->orderBy('proposal_locations.start_date')),
            'end_date' => $byName($query->orderByRaw('proposal_locations.end_date is null')->orderBy('proposal_locations.end_date')),
            'updated_at' => $query->orderByDesc('proposal_locations.updated_at')->orderByDesc('proposal_locations.id'),
            default => $byName($query)->orderBy('proposal_locations.id'),
        };
    }

    /**
     * How many location rows sit in each lifecycle state.
     *
     * The rail ignores the status filter so it always shows where the rest of
     * the filtered portfolio sits; every other filter still applies.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        return $this->applyFilters(ProposalLocation::query(), includeStatus: false)
            ->toBase()
            ->groupBy('proposal_locations.status')
            ->selectRaw('proposal_locations.status, count(*) as aggregate')
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

        $row = $this->applyFilters(ProposalLocation::query(), includeStatus: false, includeLens: false)
            ->toBase()
            ->selectRaw(
                'sum(case when not exists (select 1 from donor_proposal where donor_proposal.proposal_id = proposal_locations.proposal_id) then 1 else 0 end) as unfunded_count, '
                .'sum(case when proposal_locations.status = ? and proposal_locations.end_date < ? then 1 else 0 end) as overdue_count, '
                .'sum(case when exists (select 1 from risks where risks.proposal_location_id = proposal_locations.id and risks.happened = 1) then 1 else 0 end) as risks_count',
                [
                    ProjectStatus::Active->value,
                    $today,
                ],
            )
            ->first();

        return [
            'unfunded' => (int) ($row->unfunded_count ?? 0),
            'overdue' => (int) ($row->overdue_count ?? 0),
            'risks' => (int) ($row->risks_count ?? 0),
        ];
    }
}
