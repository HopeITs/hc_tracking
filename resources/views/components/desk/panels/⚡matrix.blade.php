<?php

use App\Enums\MonthlyReportStatus;
use App\Enums\ProjectStatus;
use App\Enums\ReopenRequestStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\HrEmployee;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Support\MealCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Monthly reports: every project that reports monthly, one cell per month
 * for the last seven months — validated, with MEAL, sent back, late (a
 * past month still in draft) or open — and how many of its reports are
 * validated. A dot marks a pending reopen request.
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * @return list<CarbonImmutable>
     */
    public function months(): array
    {
        $current = CarbonImmutable::today()->startOfMonth();

        return array_map(fn (int $back): CarbonImmutable => $current->subMonths($back), range(6, 0));
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function rows(): Collection
    {
        if (! $this->canView('reports')) {
            return new Collection;
        }

        return $this->visible(Project::query(), 'projects')
            ->select(['id', 'slug', 'name', 'user_id', 'city_id', 'center_id', 'program_id'])
            ->whereIn('id', MonthlyReport::query()->select('project_id'))
            ->where('status', '!=', ProjectStatus::Archived)
            ->with([
                'user:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'monthlyReports' => fn ($query) => $query->select(['id', 'slug', 'project_id', 'period', 'status', 'returned_at'])
                    ->withExists(['reopenRequests as has_pending_reopen' => fn ($reopen) => $reopen->where('status', ReopenRequestStatus::Pending)]),
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * project id => responsible MEAL officer's name.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function officers(): array
    {
        $byProject = $this->rows->mapWithKeys(fn (Project $project): array => [$project->id => MealCoverage::responsibleFor($project)]);
        $names = HrEmployee::query()->whereKey($byProject->filter()->unique()->values()->all())->get(HrEmployee::NameColumns)
            ->mapWithKeys(fn (HrEmployee $employee): array => [$employee->id => $employee->name]);

        return $byProject->map(fn (?int $employeeId): string => $employeeId === null ? '' : (string) $names->get($employeeId, ''))->all();
    }

    public function stateOf(MonthlyReport $report): string
    {
        return match (true) {
            $report->status === MonthlyReportStatus::Validated => 'validated',
            $report->status === MonthlyReportStatus::Submitted => 'submitted',
            $report->returned_at !== null => 'returned',
            $report->period->lt(CarbonImmutable::today()->startOfMonth()) => 'late',
            default => 'draft',
        };
    }
};
?>

@php
    $rows = $this->rows;
    $months = $this->months();
    $glyphs = ['validated' => '✓', 'submitted' => '•', 'late' => '!', 'returned' => '↺', 'draft' => ''];
    $lateCount = $rows->sum(fn ($project) => $project->monthlyReports->filter(fn ($report) => $this->stateOf($report) === 'late')->count());
    $pg = $this->paginate($rows, 6);
@endphp

<x-desk.panel id="matrix" register="reports.monthly-matrix" :size="$size" :index="$index" :total="$total" :count="$lateCount > 0 ? $lateCount : null">
    <div class="dk-mx-wrap">
        <table class="dk-mx">
            <thead>
                <tr>
                    <th scope="col">{{ __('desk.project') }}</th>
                    @foreach ($months as $month)
                        <th scope="col">{{ $month->translatedFormat('M') }}</th>
                    @endforeach
                    <th scope="col">{{ __('desk.validated') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pg['slice'] as $project)
                    @php
                        $byMonth = $project->monthlyReports->keyBy(fn ($report) => $report->period->format('Y-m'));
                        $owner = $project->user?->name;
                        $officer = $this->officers[$project->id] ?? '';
                    @endphp
                    <tr wire:key="mx-{{ $project->id }}">
                        <th scope="row"><a href="{{ route('projects.show', $project) }}" wire:navigate>{{ $project->name }}</a>@if (filled($owner) || $officer !== '')<span class="dk-is">{{ $owner ?? '—' }} → {{ $officer !== '' ? $officer : '—' }}</span>@endif</th>
                        @foreach ($months as $month)
                            @php
                                $report = $byMonth->get($month->format('Y-m'));
                                $state = $report === null ? null : $this->stateOf($report);
                            @endphp
                            <td>
                                @if ($report !== null)
                                    <a href="{{ route('monthly-reports.show', $report) }}" wire:navigate class="dk-mc dk-{{ $state }}" aria-label="{{ $month->translatedFormat('F') }}: {{ __('desk.report_state.'.$state) }}">{{ $glyphs[$state] }}@if ($report->has_pending_reopen)<i class="dk-ro"></i>@endif</a>
                                @else
                                    <span class="dk-mc dk-future"></span>
                                @endif
                            </td>
                        @endforeach
                        <td class="dk-num">{{ $project->monthlyReports->where('status', MonthlyReportStatus::Validated)->count() }}/{{ $project->monthlyReports->count() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="dk-legend">
        @foreach (['validated', 'submitted', 'returned', 'late', 'draft'] as $state)
            <span><i class="dk-mc dk-{{ $state }}"></i>{{ __('desk.report_state.'.$state) }}</span>
        @endforeach
    </div>

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
