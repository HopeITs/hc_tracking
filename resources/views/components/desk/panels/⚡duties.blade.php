<?php

use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\DonorReport;
use App\Models\HrEmployee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Donor reporting: donor reports not yet submitted, soonest due first.
 * Funding-agreement viewers see every open report (the artifact's view for
 * the people who aggregate and own the money); everyone else sees only the
 * reports they are responsible for, like the reporting-duties page.
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * @return Collection<int, DonorReport>
     */
    #[Computed]
    public function rows(): Collection
    {
        $employeeId = auth()->user()->employee_id;

        if (! $this->canView('funding-agreements') && $employeeId === null) {
            return new Collection;
        }

        return DonorReport::query()
            ->select(['id', 'donor_proposal_id', 'title', 'due_on', 'responsible_employee_id'])
            ->with(['donorProposal:id,donor_id,proposal_id', 'donorProposal.donor:id,name', 'donorProposal.proposal:id,name'])
            ->unless($this->canView('funding-agreements'), fn ($query) => $query->where('responsible_employee_id', $employeeId))
            ->whereNull('submitted_on')
            ->orderBy('due_on')
            ->orderBy('id')
            ->get();
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
};
?>

@php
    $rows = $this->rows;
    $today = CarbonImmutable::today();
    $late = $rows->filter(fn ($report): bool => $report->due_on->lt($today))->count();
    $pg = $this->paginate($rows, self::PerPage);
@endphp

<x-desk.panel :facets="$pg['facets']" id="duties" register="reporting-duties.index" :size="$size" :index="$index" :total="$total" :count="$late > 0 ? $late : ($rows->count() ?: null)">
    @if ($pg['total'] > 0)
        <ul class="dk-plist">
            @foreach ($pg['slice'] as $report)
                @php
                    $days = (int) $today->diffInDays($report->due_on, false);
                    $person = $report->responsible_employee_id === null ? null : ($this->people[$report->responsible_employee_id] ?? null);
                @endphp
                <li wire:key="dr-{{ $report->id }}">
                    <div>
                        <strong><a href="{{ route('donor-reports.show', $report) }}" wire:navigate>{{ $report->title }}</a></strong>
                        <span class="dk-is">{{ $report->donorProposal?->donor?->name ?? '—' }} · {{ $report->donorProposal?->proposal?->name ?? '—' }}@if (filled($person)) · {{ $person }}@endif</span>
                    </div>
                    <span @class(['dk-small', 'dk-bad' => $days < 0, 'dk-warn' => $days >= 0 && $days <= 7, 'dk-muted' => $days > 7])>{{ $report->due_on->translatedFormat(__('desk.date_short')) }}<br>{{ \App\Support\Desk\RelativeDay::for($report->due_on) }}</span>
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
