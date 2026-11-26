<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Enums\MediaCollection;
use App\Models\ProposalLocation;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

/**
 * The money and schedule readings of the project directory: who may see
 * committed budgets, the funding totals, the per-card funding envelope, the
 * timeline window and delivery progress.
 *
 * Used by projects/index only. It needs applyFilters() from
 * FiltersProjectDirectory, and the page's `projects()` list.
 *
 * @property-read LengthAwarePaginator<int, ProposalLocation> $projects  #[Computed] projects() on the page
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
     * The row's delivery progress, or null when nothing measures it.
     * A place carries no progress measure since milestones were removed, so
     * every row reads as unmeasured.
     */
    protected function progressFor(ProposalLocation $project): ?float
    {
        return null;
    }

    /**
     * Committed funding for the filtered portfolio, per currency.
     *
     * A proposal covers several places (D-93), so the total is taken over the
     * DISTINCT proposals the filtered rows reach. Adding up a per-row figure
     * instead overcounts by roughly the number of places each proposal has.
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

        $rowIds = $this->applyFilters(ProposalLocation::query())->toBase()->select('proposal_locations.id');

        return DB::table('donor_proposal')
            ->whereIn('proposal_id', fn ($query) => $query
                ->select('proposal_id')
                ->from('proposal_locations')
                ->whereIn('id', $rowIds))
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
        return (int) $this->applyFilters(ProposalLocation::query())->sum('proposal_locations.target_beneficiaries');
    }

    /**
     * The funding envelope of every row on the visible page, in one read.
     *
     * A proposal's budget is not attributable to one place, so the row
     * reports the envelope covering it and says how many other places the
     * same proposal covers (D-93).
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

        $rows = DB::table('proposal_locations')
            ->join('donor_proposal', 'donor_proposal.proposal_id', '=', 'proposal_locations.proposal_id')
            ->join('donors', 'donors.id', '=', 'donor_proposal.donor_id')
            ->join('proposals', 'proposals.id', '=', 'proposal_locations.proposal_id')
            ->whereIn('proposal_locations.id', $visibleIds)
            ->select([
                'proposal_locations.id as project_id',
                'proposal_locations.proposal_id',
                'donors.name as donor_name',
                'proposals.name as proposal_name',
            ])
            ->when($canViewMoney, fn ($query) => $query->addSelect([
                'donor_proposal.currency',
                'donor_proposal.budget',
            ]))
            ->selectSub(
                DB::table('proposal_locations as shares')
                    ->selectRaw('count(*)')
                    ->whereColumn('shares.proposal_id', 'proposal_locations.proposal_id'),
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
     * It is read from the rows already on the page, so switching to the
     * timeline costs no extra query.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    #[Computed]
    public function timelineWindow(): ?array
    {
        $dates = $this->projects
            ->getCollection()
            ->flatMap(fn (ProposalLocation $project): array => [$project->start_date, $project->end_date])
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
