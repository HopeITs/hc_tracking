<?php

use App\Enums\MilestoneStatus;
use App\Enums\ProposalStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\HrEmployee;
use App\Models\Proposal;
use App\Support\Desk\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Proposal pipeline: a one-line stage summary, then the proposals in fixed
 * columns — name, donor, status (always at the same x), amount (agreed, or
 * requested; money viewers only, .ai/rules/pages.md "Hide money"), next
 * deadline and owner. The next deadline is the proposal's earliest open
 * milestone, else its on-hold review date. The owner is whoever owns its
 * first linked project (proposals carry no owner of their own). Status,
 * donor and owner are filter-popup dropdowns; the status options carry
 * their counts.
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * The pill tone per status.
     */
    private const array Tones = [
        'concept note review' => 'act', 'concept note submitted' => 'act', 'proposal review' => 'act', 'submitted' => 'act',
        'on hold' => 'warn', 'approved' => 'good', 'active' => 'good', 'final reports' => 'good', 'completed' => 'mute', 'rejected' => 'bad',
    ];

    public string $status = '';

    public function updatedStatus(string $status): void
    {
        $this->status = ProposalStatus::tryFrom($status)?->value ?? '';
        $this->page = 0;
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        $byStatus = $this->visible(Proposal::query(), 'proposals')->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(ProposalStatus::cases())->mapWithKeys(fn (ProposalStatus $status): array => [$status->value => (int) ($byStatus[$status->value] ?? 0)])->all();
    }

    /**
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->visible(Proposal::query(), 'proposals')
            ->select(['id', 'slug', 'name', 'status', 'donor_id', 'requested_budget', 'requested_currency', 'status_remind_on'])
            ->with([
                'donor:id,name',
                'donorProposals:id,proposal_id,donor_id,budget,currency',
                'donorProposals.donor:id,name',
                'projects:id,user_id',
                'milestones' => fn ($query) => $query->select(['id', 'proposal_id', 'planned_end_date'])->whereIn('status', [MilestoneStatus::Planned, MilestoneStatus::Ongoing])->whereNotNull('planned_end_date'),
            ])
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->latest('id')
            ->get();
    }

    /**
     * employee id => name, for the owners shown.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function owners(): array
    {
        $ids = $this->rows->map(fn (Proposal $proposal): ?int => $proposal->projects->first()?->user_id)->filter()->unique()->values()->all();

        return HrEmployee::query()->whereKey($ids)->get(HrEmployee::NameColumns)->mapWithKeys(fn (HrEmployee $employee): array => [$employee->id => $employee->name])->all();
    }

    public function tone(ProposalStatus $status): string
    {
        return self::Tones[$status->value] ?? '';
    }

    /**
     * The donor the proposal is written for, or, failing that, the donors on
     * its signed agreements; '' when neither is known.
     */
    public function donorName(Proposal $proposal): string
    {
        return $proposal->donor?->name
            ?? $proposal->donorProposals->map(fn ($link) => $link->donor?->name)->filter()->implode(', ');
    }

    public function ownerName(Proposal $proposal): string
    {
        $employeeId = $proposal->projects->first()?->user_id;

        return $employeeId === null ? '' : ($this->owners[$employeeId] ?? '');
    }

    public function deadline(Proposal $proposal): ?CarbonImmutable
    {
        return $proposal->milestones->min('planned_end_date') ?? $proposal->status_remind_on;
    }

    /**
     * Donor and owner, for the filter popup's dropdowns (status has its own).
     *
     * @return array<string, string>
     */
    protected function facetsOf(mixed $row): array
    {
        return array_filter(['donor' => $this->donorName($row), 'owner' => $this->ownerName($row)]);
    }
};
?>

@php
    $counts = $this->counts;
    $pg = $this->paginate($this->rows, self::PerPage);
    $showMoney = $this->canView('funding-agreements');
    $today = CarbonImmutable::today();
    $summary = collect($counts)->filter()->sortDesc()->take(4)->map(fn (int $count, string $status): string => $count.' '.mb_strtolower(ProposalStatus::from($status)->label()))->implode(' · ');
@endphp

<x-desk.panel :facets="$pg['facets']" :filtered="$status !== ''" id="pipeline" register="proposals.index" :size="$size" :index="$index" :total="$total" :count="array_sum($counts)">
    <x-slot:filters>
        <div class="contents" data-filter-defaults='{"status":""}'>
            <x-ui.header-filter-select :label="__('desk.facet.status')" wire:model="status" :value="$status" deferred>
                <option value="">{{ __('desk.filter_all') }} ({{ array_sum($counts) }})</option>
                @foreach (ProposalStatus::cases() as $option)
                    @if ($counts[$option->value] > 0 || $status === $option->value)
                        <option value="{{ $option->value }}">{{ $option->label() }} ({{ $counts[$option->value] }})</option>
                    @endif
                @endforeach
            </x-ui.header-filter-select>
        </div>
    </x-slot:filters>

    @if ($summary !== '')
        <p class="dk-pl-sum dk-num">{{ $summary }}</p>
    @endif

    @if ($pg['total'] > 0)
        <ul @class(['dk-pl', 'dk-pl-money' => $showMoney])>
            @foreach ($pg['slice'] as $proposal)
                @php
                    $tone = $this->tone($proposal->status);
                    $agreement = $proposal->donorProposals->first();
                    $donor = $this->donorName($proposal);
                    $owner = $this->ownerName($proposal);
                    $deadline = $this->deadline($proposal);
                    $days = $deadline === null ? null : (int) $today->diffInDays($deadline->startOfDay(), false);
                @endphp
                <li wire:key="p-{{ $proposal->id }}">
                    <a href="{{ route('proposals.show', $proposal) }}" wire:navigate class="dk-pl-n" title="{{ $proposal->name }}">{{ $proposal->name }}</a>
                    <span class="dk-pl-d" title="{{ $donor }}" data-owner="{{ $owner !== '' ? ' · '.$owner : '' }}">{{ $donor !== '' ? $donor : __('desk.no_donor_yet') }}</span>
                    <span class="dk-pl-s"><span @class(['dk-pill', $tone !== '' ? 'dk-'.$tone : null]) title="{{ $proposal->status->label() }}">{{ $proposal->status->label() }}</span></span>
                    @if ($showMoney)
                        <span class="dk-pl-m dk-num">
                            @if ($agreement !== null && (float) $agreement->budget > 0)
                                {{ Money::format($agreement->budget, $agreement->currency) }}
                            @elseif ((float) $proposal->requested_budget > 0 && $proposal->requested_currency !== null)
                                {{ Money::format($proposal->requested_budget, $proposal->requested_currency) }}
                            @else
                                —
                            @endif
                        </span>
                    @endif
                    <span @class(['dk-pl-t dk-num', 'dk-bad' => $days !== null && $days < 0]) title="{{ $deadline?->translatedFormat(__('formats.date')) ?? __('desk.no_deadline') }}">{{ $days === null ? '—' : ($days < 0 ? trans_choice('desk.days_late', -$days, ['count' => -$days]) : trans_choice('desk.days_left', $days, ['count' => $days])) }}</span>
                    <span class="dk-pl-o" title="{{ $owner !== '' ? $owner : __('desk.no_owner') }}">@if ($owner !== '')<span class="dk-av dk-av-xs">{{ collect(preg_split('/\s+/u', trim($owner)))->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('') }}</span>@endif</span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="dk-pempty">{{ __('desk.nothing_here') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
