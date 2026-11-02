<?php

use App\Enums\DonorReportState;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Proposal;
use App\Support\DonorReportResponsibles;
use App\Support\Like;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
| Donor reports register (contracts §14.3): every report the viewer may open.
| funding-agreements.view and reports.aggregate holders see them all; anyone
| else sees only the reports they are responsible for, exactly as the show
| page's policy decides. No money: donor and proposal names are plain text,
| and the proposal links to its page only for proposals.view holders.
*/
new #[Layout('layouts::desk'), Title('titles.donor_reports')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $donor = '';

    #[Url(except: '')]
    public string $proposal = '';

    #[Url(except: '')]
    public string $state = '';

    public function mount(): void
    {
        $this->normaliseFilters();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'donor', 'proposal', 'state'], true)) {
            $this->normaliseFilters();
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'donor', 'proposal', 'state']);
        $this->resetPage();
    }

    /**
     * The page of reports, soonest due first, with the agreement's donor and
     * proposal for the names.
     *
     * @return LengthAwarePaginator<int, DonorReport>
     */
    #[Computed]
    public function reports(): LengthAwarePaginator
    {
        $today = CarbonImmutable::today()->toDateString();

        return $this->visible()
            ->with(['donorProposal:id,proposal_id,donor_id', 'donorProposal.proposal:id,name,slug', 'donorProposal.donor:id,name'])
            ->when($this->search !== '', fn (Builder $query) => $query->whereHas(
                'donorProposal.proposal',
                fn (Builder $proposals) => $proposals->where('name', 'like', Like::contains($this->search)),
            ))
            ->when($this->donor !== '', fn (Builder $query) => $query->whereHas('donorProposal', fn (Builder $agreements) => $agreements->where('donor_id', (int) $this->donor)))
            ->when($this->proposal !== '', fn (Builder $query) => $query->whereHas('donorProposal', fn (Builder $agreements) => $agreements->where('proposal_id', (int) $this->proposal)))
            ->when($this->state === DonorReportState::Submitted->value, fn (Builder $query) => $query->whereNotNull('submitted_on'))
            ->when($this->state === DonorReportState::Overdue->value, fn (Builder $query) => $query->whereNull('submitted_on')->whereDate('due_on', '<', $today))
            ->when($this->state === DonorReportState::Due->value, fn (Builder $query) => $query->whereNull('submitted_on')->whereDate('due_on', '>=', $today))
            ->orderByRaw('submitted_on is not null')
            ->orderBy('due_on')
            ->orderBy('id')
            ->paginate(25);
    }

    /**
     * Whether the viewer has any report at all, before filters.
     */
    #[Computed]
    public function hasAnyReport(): bool
    {
        return $this->visible()->exists();
    }

    /**
     * The donors of the viewer's reports, for the filter.
     *
     * @return Collection<int, Donor>
     */
    #[Computed]
    public function donorOptions(): Collection
    {
        return Donor::query()
            ->whereIn('id', DonorProposal::query()->select('donor_id')->whereIn('id', $this->visible()->select('donor_proposal_id')))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * The proposals of the viewer's reports, for the filter.
     *
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function proposalOptions(): Collection
    {
        return Proposal::query()
            ->whereIn('id', DonorProposal::query()->select('proposal_id')->whereIn('id', $this->visible()->select('donor_proposal_id')))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * The responsible people of this page's reports, read from HR once.
     *
     * @return array<int, array{employee: \App\Models\HrEmployee|null, user_id: int|null, reachable: bool}>
     */
    #[Computed]
    public function responsibles(): array
    {
        return DonorReportResponsibles::resolve(
            $this->reports->getCollection()->pluck('responsible_employee_id')->filter()->map(fn (int $employeeId): int => $employeeId)->all(),
        );
    }

    /**
     * The reports the viewer may open (DonorReportPolicy::view).
     *
     * @return Builder<DonorReport>
     */
    private function visible(): Builder
    {
        $user = auth()->user();

        return DonorReport::query()->unless(
            $user->can('funding-agreements.view') || $user->can('reports.aggregate'),
            fn (Builder $query) => $query->where('responsible_employee_id', $user->employee_id ?? 0),
        );
    }

    private function normaliseFilters(): void
    {
        if (! in_array($this->state, DonorReportState::values(), true)) {
            $this->state = '';
        }

        foreach (['donor', 'proposal'] as $filter) {
            if (! ctype_digit($this->{$filter})) {
                $this->{$filter} = '';
            }
        }
    }
};
?>

@php
    $muted = 'text-secondary-medium dark:text-ink-muted';
    $canOpenProposals = auth()->user()->can('proposals.view');
    $responsibles = $this->responsibles;
@endphp

<x-desk.register
    class="@container"
    heading-id="donor-reports-heading"
    :title="__('titles.donor_reports')"
    :count="$this->reports->total()"
    data-donor-reports
>
    <x-slot:search>
        <x-desk.register-search :label="__('donor_reports.search_reports')" :placeholder="__('donor_reports.search_by_proposal')" wire:model.live.debounce.300ms="search" />
    </x-slot:search>
    <x-slot:filters>
        <x-ui.header-filter-select :label="__('funding_agreements.donor')" wire:model.live="donor" :value="$donor">
            <option value="">{{ __('funding_agreements.all_donors') }}</option>
            @foreach ($this->donorOptions as $donorOption)
                <option value="{{ $donorOption->id }}">{{ $donorOption->name }}</option>
            @endforeach
        </x-ui.header-filter-select>

        <x-ui.header-filter-select :label="__('common.proposal')" wire:model.live="proposal" :value="$proposal">
            <option value="">{{ __('donor_reports.all_proposals') }}</option>
            @foreach ($this->proposalOptions as $proposalOption)
                <option value="{{ $proposalOption->id }}">{{ $proposalOption->name }}</option>
            @endforeach
        </x-ui.header-filter-select>

        <x-ui.header-filter-select :label="__('common.state')" wire:model.live="state" :value="$state">
            <option value="">{{ __('donor_reports.all_states') }}</option>
            @foreach (DonorReportState::cases() as $stateOption)
                <option value="{{ $stateOption->value }}">{{ $stateOption->label() }}</option>
            @endforeach
        </x-ui.header-filter-select>
    </x-slot:filters>

    @if (! $this->hasAnyReport)
        <x-ui.empty-state icon="document-check" :heading="__('donor_reports.none')" :body="__('donor_reports.none_body')" />
    @elseif ($this->reports->isEmpty())
        <x-ui.empty-state icon="document-check" :heading="__('donor_reports.no_matching')" :body="__('common.change_the_search_or_filters_and_try_again')" />
    @else
        <x-ui.data-table :caption="__('titles.donor_reports')" data-tour="donor-reports-list">
            <x-slot:head>
                <th>{{ __('common.proposal') }}</th>
                <th class="w-40!">{{ __('funding_agreements.donor') }}</th>
                <th>{{ __('donor_reports.report') }}</th>
                <th class="w-36!">{{ __('common.period') }}</th>
                <th class="w-32!">{{ __('common.state') }}</th>
                <th class="w-44!">{{ __('donor_reports.responsible') }}</th>
                <th class="w-36!">{{ __('funding_agreements.due') }}</th>
                <th class="w-36!">{{ __('donor_reports.submitted_on') }}</th>
                <th class="w-16!"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($this->reports as $report)
                @php
                    $agreement = $report->donorProposal;
                    $reportProposal = $agreement?->proposal;
                    $responsible = $report->responsible_employee_id === null ? null : ($responsibles[$report->responsible_employee_id]['employee'] ?? null);
                @endphp
                <tr wire:key="donor-report-{{ $report->id }}" data-donor-report="{{ $report->id }}">
                    <td>
                        @if ($reportProposal !== null && $canOpenProposals)
                            <a href="{{ route('proposals.show', $reportProposal) }}" wire:navigate class="font-semibold underline decoration-secondary-light underline-offset-2 hover:decoration-current" dir="auto">{{ $reportProposal->name }}</a>
                        @else
                            <span class="font-semibold" dir="auto">{{ $reportProposal?->name ?? '—' }}</span>
                        @endif
                    </td>
                    <td dir="auto">{{ $agreement?->donor?->name ?? '—' }}</td>
                    <td>
                        <span dir="auto">{{ $report->title }}</span>
                        <span class="block text-xs {{ $muted }}">
                            {{ $report->type->label() }} · {{ $report->audience->label() }}
                            @if ($report->isComposed())
                                · {{ __('donor_reports.version_number', ['version' => $report->version]) }}
                            @endif
                        </span>
                    </td>
                    <td class="tabular-nums" dir="ltr">
                        @if ($report->isComposed())
                            {{ $report->period_start->format('Y-m') }} – {{ $report->period_end->format('Y-m') }}
                        @else
                            —
                        @endif
                    </td>
                    <td data-donor-report-state="{{ DonorReportState::of($report)->value }}">
                        <x-ui.enum-badge :case="DonorReportState::of($report)" />
                    </td>
                    <td>
                        @if ($responsible !== null)
                            <span dir="auto">{{ $responsible->name }}</span>
                        @else
                            <span class="{{ $muted }}">{{ __('common.not_assigned') }}</span>
                        @endif
                    </td>
                    <td>{{ $report->due_on->translatedFormat(__('formats.date')) }}</td>
                    <td>{{ $report->submitted_on?->translatedFormat(__('formats.date')) ?? '—' }}</td>
                    <td>
                        <span class="flex items-center justify-end">
                            <x-ui.button tone="ghost" size="sm" icon="eye" class="size-8!" :href="route('donor-reports.show', $report)" wire:navigate :aria-label="__('common.view_name', ['name' => $report->title])" />
                        </span>
                    </td>
                </tr>
            @endforeach
        </x-ui.data-table>

        <div class="mt-4">
            <flux:pagination :paginator="$this->reports" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#donor-reports-heading" />
        </div>
    @endif
</x-desk.register>
