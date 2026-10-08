<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Enums\MediaCollection;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

/**
 * The money and schedule readings of the project directory: who may see
 * committed budgets, the funding totals, the per-card funding envelope, the
 * timeline window and weighted milestone progress.
 *
 * Used by projects/index only. It needs applyFilters() from
 * FiltersProjectDirectory, and the page's `projects()` list.
 *
 * @property-read LengthAwarePaginator<int, Project> $projects  #[Computed] projects() on the page
 */
trait SummarisesProjectFunding
{
    /**
     * Whether the viewer may see committed budgets and currencies. They are
     * agreement data, so they need funding-agreements.view; donor and proposal
     * names stay visible either way.
     */
    protected function canViewMoney(): bool
    {
        return auth()->user()?->can('funding-agreements.view') ?? false;
    }

    /**
     * The file collections this viewer may not count: signed agreements and
     * MOUs are agreement data, so they need funding-agreements.view.
     *
     * @return list<string>
     */
    protected function hiddenCollections(): array
    {
        if (auth()->user()?->can('funding-agreements.view') ?? false) {
            return [];
        }

        return array_values(array_map(
            fn (MediaCollection $collection): string => $collection->value,
            array_filter(MediaCollection::cases(), fn (MediaCollection $collection): bool => $collection->isFundingPaper()),
        ));
    }

    /**
     * Weighted milestone progress, or null when nothing carries a weight.
     *
     * Weights that add up to more than 100 are capped rather than rejected:
     * the figure is a reading of the record, not a validation of it.
     */
    protected function progressFor(Project $project): ?float
    {
        $planned = (float) ($project->planned_weight ?? 0);

        return $planned > 0 ? round(min(100, ((float) ($project->reached_weight ?? 0) / $planned) * 100), 1) : null;
    }

    /**
     * Committed funding for the filtered portfolio, per currency.
     *
     * Every proposal in this database is shared by two or three projects, so
     * the total is taken over the DISTINCT proposals the filtered set reaches.
     * Adding up a per-project figure instead overcounts by roughly 2.25x.
     *
     * Currencies are never added together: USD, EUR and SYP have no conversion
     * anywhere in this system.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function committedFunding(): array
    {
        if (! $this->canViewMoney()) {
            return [];
        }

        $projectIds = $this->applyFilters(Project::query())->toBase()->select('projects.id');

        return DB::table('donor_proposal')
            ->whereIn('proposal_id', fn ($query) => $query
                ->select('proposal_id')
                ->from('project_proposal')
                ->whereIn('project_id', $projectIds))
            ->groupBy('currency')
            ->selectRaw('currency, sum(budget) as total')
            ->pluck('total', 'currency')
            ->map(fn ($total): float => (float) $total)
            ->all();
    }

    /**
     * The headline beneficiary commitment across the filtered portfolio.
     */
    #[Computed]
    public function committedBeneficiaries(): int
    {
        return (int) $this->applyFilters(Project::query())->sum('target_beneficiaries');
    }

    /**
     * The funding envelope of every project on the visible page, in one read.
     *
     * A shared proposal's budget is not attributable to one project, so the
     * card reports the envelope covering it and says how many other projects
     * the same proposal covers.
     *
     * @return array<int, array{currencies: array<string, float>, donors: list<string>, proposals: list<string>, shared: int}>
     */
    #[Computed]
    public function fundingByProject(): array
    {
        $visibleIds = $this->projects->getCollection()->modelKeys();

        if ($visibleIds === []) {
            return [];
        }

        $canViewMoney = $this->canViewMoney();

        $rows = DB::table('project_proposal')
            ->join('donor_proposal', 'donor_proposal.proposal_id', '=', 'project_proposal.proposal_id')
            ->join('donors', 'donors.id', '=', 'donor_proposal.donor_id')
            ->join('proposals', 'proposals.id', '=', 'project_proposal.proposal_id')
            ->whereIn('project_proposal.project_id', $visibleIds)
            ->select([
                'project_proposal.project_id',
                'project_proposal.proposal_id',
                'donors.name as donor_name',
                'proposals.name as proposal_name',
            ])
            ->when($canViewMoney, fn ($query) => $query->addSelect([
                'donor_proposal.currency',
                'donor_proposal.budget',
            ]))
            ->selectSub(
                DB::table('project_proposal as shares')
                    ->selectRaw('count(*)')
                    ->whereColumn('shares.proposal_id', 'project_proposal.proposal_id'),
                'share_count',
            )
            ->get();

        $funding = [];

        foreach ($rows as $row) {
            $projectId = (int) $row->project_id;

            $funding[$projectId] ??= ['currencies' => [], 'donors' => [], 'proposals' => [], 'shared' => 0];

            if ($canViewMoney) {
                $funding[$projectId]['currencies'][$row->currency] = ($funding[$projectId]['currencies'][$row->currency] ?? 0.0) + (float) $row->budget;
            }

            $funding[$projectId]['shared'] = max($funding[$projectId]['shared'], (int) $row->share_count - 1);

            if (! in_array($row->donor_name, $funding[$projectId]['donors'], true)) {
                $funding[$projectId]['donors'][] = $row->donor_name;
            }

            if (! in_array($row->proposal_name, $funding[$projectId]['proposals'], true)) {
                $funding[$projectId]['proposals'][] = $row->proposal_name;
            }
        }

        return $funding;
    }

    /**
     * The date range the timeline plots every bar against.
     *
     * It is read from the projects already on the page, so switching to the
     * timeline costs no extra query.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    #[Computed]
    public function timelineWindow(): ?array
    {
        $dates = $this->projects
            ->getCollection()
            ->flatMap(fn (Project $project): array => [$project->start_date, $project->end_date])
            ->filter()
            ->values();

        if ($dates->isEmpty()) {
            return null;
        }

        $start = $dates->min();
        $end = $dates->max();

        return $end->lte($start)
            ? ['start' => $start, 'end' => $start->addMonth()]
            : ['start' => $start, 'end' => $end];
    }
}
