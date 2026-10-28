<?php

use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\DonorReport;
use App\Models\HrEmployee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Donor reporting: donor reports not yet submitted, overdue first then
 * soonest due — donor, project (or proposal), report type, days left or
 * late, and where it stands (draft: nothing generated yet; ready: its
 * snapshot is generated, waiting to be submitted). One row per report:
 * when a report was regenerated as a new version, only the newest open
 * version shows. Funding-agreement viewers and report aggregators (MEAL)
 * see every open report; everyone else only those they are responsible for.
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * @return Collection<int, DonorReport>
     */
    #[Computed]
    public function rows(): Collection
    {
        $user = auth()->user();
        $seesAll = $this->canView('funding-agreements') || $user->can('reports.aggregate');

        if (! $seesAll && $user->employee_id === null) {
            return new Collection;
        }

        $today = CarbonImmutable::today();

        return DonorReport::query()
            ->select(['id', 'donor_proposal_id', 'title', 'type', 'period_start', 'period_end', 'version', 'due_on', 'responsible_employee_id', 'snapshot'])
            ->with(['donorProposal:id,donor_id,proposal_id', 'donorProposal.donor:id,name', 'donorProposal.proposal:id,name', 'donorProposal.proposal.projects:id,name'])
            ->unless($seesAll, fn ($query) => $query->where('responsible_employee_id', $user->employee_id))
            ->whereNull('submitted_on')
            ->orderByDesc('version')
            ->get()
            ->unique(fn (DonorReport $report): string => $report->period_start === null
                ? 'report-'.$report->id
                : implode('|', [$report->donor_proposal_id, $report->type->value, $report->period_start->toDateString(), $report->period_end?->toDateString()]))
            ->sortBy(fn (DonorReport $report): string => ($report->due_on->lt($today) ? '0' : '1').$report->due_on->toDateString().str_pad((string) $report->id, 10, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * employee id => name, for the responsible people on the listed reports.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function people(): array
    {
        $ids = $this->rows->pluck('responsible_employee_id')->filter()->unique()->values()->all();

        return HrEmployee::query()->whereKey($ids)->get(HrEmployee::NameColumns)
            ->mapWithKeys(fn (HrEmployee $employee): array => [$employee->id => $employee->name])
            ->all();
    }

    public function stateOf(DonorReport $report): string
    {
        return match (true) {
            $report->due_on->lt(CarbonImmutable::today()) => 'overdue',
            $report->snapshot !== null => 'ready',
            default => 'draft',
        };
    }

    public function projectOf(DonorReport $report): string
    {
        $proposal = $report->donorProposal?->proposal;

        return $proposal?->projects->first()?->name ?? $proposal?->name ?? '—';
    }

    /**
     * @return array<string, string>
     */
    protected function facetsOf(mixed $row): array
    {
        return array_filter([
            'donor' => $row->donorProposal?->donor?->name,
            'project' => $this->projectOf($row),
            'status' => __('desk.donor_report_state.'.$this->stateOf($row)),
        ]);
    }
};
?>

@php
    $rows = $this->rows;
    $today = CarbonImmutable::today();
    $late = $rows->filter(fn ($report): bool => $report->due_on->lt($today))->count();
    $pg = $this->paginate($rows, self::PerPage);
@endphp

<x-desk.panel :facets="$pg['facets']" id="duties" register="reporting-duties.index" :size="$size" :index="$index" :total="$total" :count="$late > 0 ? $late : ($rows->count() ?: null)">
    @if ($rows->isNotEmpty())
        <p class="dk-pl-sum">{{ trans_choice('desk.duties_summary', $late, ['late' => $late, 'open' => $rows->count()]) }}</p>
    @endif
    @if ($pg['total'] > 0)
        <ul class="dk-dr">
            @foreach ($pg['slice'] as $report)
                @php
                    $days = (int) $today->diffInDays($report->due_on, false);
                    $state = $this->stateOf($report);
                    $person = $report->responsible_employee_id === null ? null : ($this->people[$report->responsible_employee_id] ?? null);
                    $donor = $report->donorProposal?->donor?->name ?? '—';
                    $project = $this->projectOf($report);
                @endphp
                <li wire:key="dr-{{ $report->id }}">
                    <a href="{{ route('donor-reports.show', $report) }}" wire:navigate class="dk-dr-n" title="{{ $report->title }}">{{ $report->title }}</a>
                    <span @class(['dk-dr-t dk-num', 'dk-bad' => $days < 0, 'dk-warn' => $days >= 0 && $days <= 7]) title="{{ $report->due_on->translatedFormat(__('formats.date')) }}">{{ $days < 0 ? trans_choice('desk.days_late', -$days, ['count' => -$days]) : trans_choice('desk.days_left', $days, ['count' => $days]) }}</span>
                    <span class="dk-dr-m dk-is" title="{{ $donor }} · {{ $project }}@if (filled($person)) · {{ $person }}@endif">{{ $donor }} · {{ $project }} · {{ $report->type->label() }}@if (filled($person)) · {{ $person }}@endif</span>
                    <span @class(['dk-pill dk-dr-s', 'dk-bad' => $state === 'overdue', 'dk-act' => $state === 'ready', 'dk-mute' => $state === 'draft'])>{{ __('desk.donor_report_state.'.$state) }}</span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="dk-pempty">{{ __('desk.panel.duties.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
