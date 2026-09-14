<?php

use App\Enums\ProjectStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Report;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Donor')] class extends Component {
    public int $donorId;

    public function mount(Donor $donor): void
    {
        $this->donorId = $donor->id;
    }

    #[Computed]
    public function donor(): Donor
    {
        return Donor::query()
            ->select(['id', 'name', 'type', 'contact_person', 'email', 'phone', 'country', 'notes', 'updated_at'])
            ->withCount('donorProposals')
            ->findOrFail($this->donorId);
    }

    /** @return Collection<int, DonorProposal> */
    #[Computed]
    public function agreements(): Collection
    {
        return DonorProposal::query()
            ->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'project_num'])
            ->where('donor_id', $this->donorId)
            ->with(['proposal:id,name,status', 'transfers:id,donor_proposal_id,net_amount_received,amount_received_currency'])
            ->withCount('transfers')
            ->orderBy('id')
            ->get();
    }

    /**
     * Committed budget per currency. Grouped, never summed across currencies.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function budgetByCurrency(): array
    {
        return $this->agreements
            ->groupBy(fn (DonorProposal $agreement): string => $agreement->currency->value)
            ->map(fn (Collection $group): float => (float) $group->sum(fn (DonorProposal $agreement): float => (float) $agreement->budget))
            ->all();
    }

    /**
     * Disbursed amount per currency, read from the tranches actually received
     * against every agreement this donor holds.
     *
     * Grouped on `amount_received_currency` — a different column from the
     * agreement's committed currency, since a USD payment can land as SYP —
     * and never summed across currencies. Only tranches with a non-null
     * `net_amount_received` count as disbursed.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function disbursedByCurrency(): array
    {
        return $this->agreements
            ->flatMap(fn (DonorProposal $agreement) => $agreement->transfers)
            ->filter(fn ($transfer) => $transfer->net_amount_received !== null)
            ->groupBy(fn ($transfer): string => $transfer->amount_received_currency->value)
            ->map(fn ($group): float => (float) $group->sum(fn ($transfer): float => (float) $transfer->net_amount_received))
            ->all();
    }

    /**
     * Distinct project counts funded through this donor's proposals, split by
     * whether the project is still open.
     *
     * A project funded by two of this donor's proposals is counted once: the
     * project ids are deduplicated before the open/closed split.
     *
     * @return array{open: int, closed: int}
     */
    #[Computed]
    public function projectCounts(): array
    {
        $statuses = $this->donor
            ->proposals()
            ->with('projects:id,status')
            ->get()
            ->flatMap(fn ($proposal) => $proposal->projects)
            ->unique('id')
            ->pluck('status');

        return [
            'open' => $statuses->filter(fn (ProjectStatus $status): bool => $status->isOpen())->count(),
            'closed' => $statuses->filter(fn (ProjectStatus $status): bool => ! $status->isOpen())->count(),
        ];
    }

    /**
     * Portfolio-wide count of reports submitted against targets committed
     * under any of this donor's proposals.
     */
    #[Computed]
    public function reportsSubmittedCount(): int
    {
        $proposalIds = $this->donor->proposals()->pluck('proposals.id');

        if ($proposalIds->isEmpty()) {
            return 0;
        }

        return Report::query()
            ->whereHas('planIndicator', function ($query) use ($proposalIds): void {
                $query->where('source_type', 'proposal')
                    ->whereIn('source_id', $proposalIds);
            })
            ->count();
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('Donors'), 'url' => route('donors.index')],
            ['label' => $this->donor->name],
        ]"
        :title="$this->donor->name"
    >
        <x-slot:meta>
            <x-ui.enum-badge :case="$this->donor->type" />
            <span>{{ trans_choice(':count agreement|:count agreements', $this->donor->donor_proposals_count, ['count' => $this->donor->donor_proposals_count]) }}</span>
        </x-slot:meta>
    </x-ui.detail-header>

    <div class="grid gap-3 @2xl/detail:grid-cols-3">
        @foreach ($this->budgetByCurrency as $currencyCode => $total)
            <x-ui.stat-tile :label="__('Committed')" :value="number_format($total, 2)" :unit="$currencyCode" :scope="$currencyCode" wire:key="donor-committed-{{ $currencyCode }}" />
        @endforeach

        @foreach ($this->disbursedByCurrency as $currencyCode => $total)
            <x-ui.stat-tile :label="__('Disbursed')" :value="number_format($total, 2)" :unit="$currencyCode" :scope="$currencyCode" wire:key="donor-disbursed-{{ $currencyCode }}" />
        @endforeach

        <x-ui.stat-tile :label="__('Active projects')" :value="$this->projectCounts['open']" wire:key="donor-projects-open" />
        <x-ui.stat-tile :label="__('Closed projects')" :value="$this->projectCounts['closed']" wire:key="donor-projects-closed" />
        <x-ui.stat-tile :label="__('Reports submitted')" :value="$this->reportsSubmittedCount" wire:key="donor-reports-submitted" />
    </div>

    <x-ui.panel :title="__('Funding agreements')" :count="$this->agreements->count()" :subtitle="__('Budgets are shown per currency and never added together.')">
        @if ($this->agreements->isEmpty())
            <x-ui.empty-state icon="banknotes" :heading="__('No agreements yet')" :body="__('Agreements signed by this donor will appear here.')" />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->agreements as $agreement)
                    <article class="flex flex-col gap-2 p-3.5 @3xl/detail:flex-row @3xl/detail:items-center" wire:key="donor-agreement-{{ $agreement->id }}">
                        <div class="min-w-0 @3xl/detail:me-auto">
                            <a href="{{ route('funding-agreements.show', $agreement) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $agreement->proposal->name }}</a>
                            <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ $agreement->project_num ?? __('No donor reference') }}
                                · {{ trans_choice(':count tranche|:count tranches', $agreement->transfers_count, ['count' => $agreement->transfers_count]) }}
                            </p>
                        </div>
                        <x-ui.enum-badge :case="$agreement->proposal->status" />
                        <x-ui.money :amount="$agreement->budget" :currency="$agreement->currency" />
                    </article>
                @endforeach
            </div>
        @endif
    </x-ui.panel>
</section>
