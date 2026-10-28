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
 * Monthly reports: which project has not submitted or not had validated
 * which month. At L, W and XL a project × month grid (six months at L,
 * twelve at W and XL); at S and M a "late first" list of the reports that
 * need someone (late, sent back, with MEAL). The legend is a filter: one
 * click shows only that state, a second click shows all again; the filter
 * popup offers the same choice next to the program.
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * The report states, in the order the status filter and summary show them.
     */
    public const array States = ['late', 'returned', 'submitted', 'validated', 'draft'];

    public string $state = '';

    public function updatedState(string $state): void
    {
        $this->state = in_array($state, self::States, true) ? $state : '';
        $this->page = 0;
    }

    /**
     * @return list<string>
     */
    protected function filterProperties(): array
    {
        return ['state'];
    }

    public function isWide(): bool
    {
        return in_array($this->size, ['L', 'W', 'XL'], true);
    }

    /**
     * @return list<CarbonImmutable>
     */
    public function months(): array
    {
        $current = CarbonImmutable::today()->startOfMonth();
        $count = $this->size === 'L' ? 6 : 12;

        return array_map(fn (int $back): CarbonImmutable => $current->subMonths($back), range($count - 1, 0));
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
                'program:id',
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

    /**
     * Every report in the shown months that needs someone (late, sent back,
     * with MEAL), late first then oldest month, for the S/M list.
     *
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, array{project: Project, report: MonthlyReport, state: string}>
     */
    public function attention(Collection $projects): Collection
    {
        $from = $this->months()[0];
        $states = $this->state === '' ? ['late', 'returned', 'submitted'] : [$this->state];

        return $projects
            ->flatMap(fn (Project $project): Collection => $project->monthlyReports
                ->filter(fn (MonthlyReport $report): bool => $report->period->greaterThanOrEqualTo($from) && in_array($this->stateOf($report), $states, true))
                ->map(fn (MonthlyReport $report): array => ['project' => $project, 'report' => $report, 'state' => $this->stateOf($report)]))
            ->sortBy([
                fn (array $a, array $b): int => array_search($a['state'], self::States, true) <=> array_search($b['state'], self::States, true),
                fn (array $a, array $b): int => $a['report']->period <=> $b['report']->period,
            ])
            ->values();
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

    /**
     * @return array<string, string>
     */
    protected function facetsOf(mixed $row): array
    {
        $project = $row instanceof Project ? $row : $row['project'];

        return array_filter(['program' => $project->program?->name], fn (?string $name): bool => $name !== null && $name !== '');
    }
};
?>

@php
    $months = $this->months();
    $from = $months[0];
    $projects = $this->rows;
    $inWindow = $projects->flatMap(fn ($project) => $project->monthlyReports->filter(fn ($report) => $report->period->greaterThanOrEqualTo($from)));
    $counts = $inWindow->countBy(fn ($report) => $this->stateOf($report));
    $wide = $this->isWide();
    $glyphs = ['validated' => '✓', 'submitted' => '•', 'late' => '!', 'returned' => '↺', 'draft' => ''];

    if ($wide) {
        $list = $state === '' ? $projects : $projects->filter(fn ($project) => $project->monthlyReports->contains(fn ($report) => $report->period->greaterThanOrEqualTo($from) && $this->stateOf($report) === $state))->values();
    } else {
        $list = $this->attention($projects);
    }

    $pg = $this->paginate($list, self::PerPage);
@endphp

<x-desk.panel :facets="$pg['facets']" :filtered="$state !== ''" id="matrix" register="reports.monthly-matrix" :size="$size" :index="$index" :total="$total" :count="($counts['late'] ?? 0) > 0 ? $counts['late'] : null">
    <x-slot:filters>
        <div class="contents" data-filter-defaults='{"state":""}'>
            <x-ui.header-filter-select :label="__('desk.facet.status')" wire:model="state" :value="$state" deferred>
                <option value="">{{ __('desk.filter_all') }}</option>
                @foreach (self::States as $option)
                    <option value="{{ $option }}">{{ __('desk.report_state.'.$option) }} ({{ $counts[$option] ?? 0 }})</option>
                @endforeach
            </x-ui.header-filter-select>
        </div>
    </x-slot:filters>

    <p class="dk-mx-sum">{!! __('desk.matrix_summary', ['late' => '<b class="dk-num dk-bad">'.($counts['late'] ?? 0).'</b>', 'waiting' => '<b class="dk-num">'.($counts['submitted'] ?? 0).'</b>']) !!}</p>

    @if ($pg['total'] === 0)
        <p class="dk-pempty">{{ __('desk.matrix_empty') }}</p>
    @elseif ($wide)
        <div class="dk-mx2" {{ $this->fitAttributes($pg) }} style="--months:{{ count($months) }}" role="table" aria-label="{{ __('desk.panel.matrix.title') }}">
            <div class="dk-mx2-r dk-mx2-h" role="row" data-fit-head>
                <span role="columnheader">{{ __('desk.project') }}</span>
                @foreach ($months as $month)
                    <span role="columnheader" title="{{ $month->translatedFormat('F Y') }}">{{ mb_substr($month->translatedFormat('M'), 0, 3) }}</span>
                @endforeach
                <span role="columnheader">{{ __('desk.validated') }}</span>
            </div>
            @foreach ($pg['slice'] as $project)
                @php
                    $byMonth = $project->monthlyReports->keyBy(fn ($report) => $report->period->format('Y-m'));
                    $owner = $project->user?->name;
                    $officer = $this->officers[$project->id] ?? '';
                @endphp
                <div class="dk-mx2-r" role="row" wire:key="mx-{{ $project->id }}">
                    <span class="dk-mx2-n" role="rowheader"><a href="{{ route('projects.show', $project) }}" wire:navigate title="{{ $project->name }}">{{ $project->name }}</a><span class="dk-is" title="{{ __('desk.matrix_people', ['owner' => $owner ?? '—', 'officer' => $officer !== '' ? $officer : '—']) }}">{{ $owner ?? '—' }} → {{ $officer !== '' ? $officer : '—' }}</span></span>
                    @foreach ($months as $month)
                        @php
                            $report = $byMonth->get($month->format('Y-m'));
                            $cell = $report === null ? null : $this->stateOf($report);
                        @endphp
                        <span role="cell" class="dk-mx2-c">
                            @if ($report !== null)
                                <a href="{{ route('monthly-reports.show', $report) }}" wire:navigate @class(['dk-mc dk-'.$cell, 'dk-dim' => $state !== '' && $state !== $cell]) title="{{ $month->translatedFormat('F Y') }}: {{ __('desk.report_state.'.$cell) }}" aria-label="{{ $month->translatedFormat('F') }}: {{ __('desk.report_state.'.$cell) }}">{{ $glyphs[$cell] }}@if ($report->has_pending_reopen)<i class="dk-ro"></i>@endif</a>
                            @else
                                <span class="dk-mc dk-future" aria-label="{{ $month->translatedFormat('F') }}: —"></span>
                            @endif
                        </span>
                    @endforeach
                    <span role="cell" class="dk-mx2-v dk-num">{{ $project->monthlyReports->where('status', MonthlyReportStatus::Validated)->count() }}/{{ $project->monthlyReports->count() }}</span>
                </div>
            @endforeach
        </div>
    @else
        <ul class="dk-mx-list" {{ $this->fitAttributes($pg) }}>
            @foreach ($pg['slice'] as $row)
                <li wire:key="mxl-{{ $row['report']->id }}">
                    <a href="{{ route('monthly-reports.show', $row['report']) }}" wire:navigate class="dk-mx-ln" title="{{ $row['project']->name }}">{{ $row['project']->name }}</a>
                    <span class="dk-pill dk-mxp-{{ $row['state'] }}">{{ __('desk.report_state.'.$row['state']) }}</span>
                    <span class="dk-is">{{ $row['report']->period->translatedFormat('F Y') }} · {{ $row['project']->user?->name ?? '—' }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
