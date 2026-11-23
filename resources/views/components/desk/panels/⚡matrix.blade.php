<?php

use App\Enums\Locale;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReopenRequestStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\HrEmployee;
use App\Models\MonthlyReport;
use App\Models\ReportTemplate;
use App\Support\MealCoverage;
use App\Support\MonthlyReportNames;
use App\Support\MonthlyReportPreparers;
use App\Support\Reporting\PlaceBridge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Monthly reports: which report template (D-76) has not submitted or not had
 * validated which month. A row is a template, named by its place and the
 * proposals it covers, so two reports of one place are told apart (Y-03).
 * At L, W and XL a template × month grid of twelve months, where
 * CSS shows only the newest that fit the card's rendered width (so a narrow
 * card keeps its names readable); at S and M a "late first" list of the reports that
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

        return array_map(fn (int $back): CarbonImmutable => $current->subMonths($back), range(11, 0));
    }

    /**
     * The templates that have monthly reports, each with its reports and
     * program; sorted by place, then proposals.
     *
     * @return Collection<int, ReportTemplate>
     */
    #[Computed]
    public function rows(): Collection
    {
        if (! $this->canView('reports')) {
            return new Collection;
        }

        $templates = ReportTemplate::query()
            ->select(['id', 'project_id', 'city_id', 'program_id', 'center_id', 'city_wide'])
            ->whereIn('id', MonthlyReport::query()->select('report_template_id'))
            ->with([
                'program:id',
                'monthlyReports' => fn ($query) => $query->select(['id', 'slug', 'report_template_id', 'period', 'status', 'returned_at', 'revised_due_on'])
                    ->withExists(['reopenRequests as has_pending_reopen' => fn ($reopen) => $reopen->where('status', ReopenRequestStatus::Pending)]),
            ])
            ->get();

        $names = $this->names;

        return $templates
            ->sortBy(fn (ReportTemplate $template): string => mb_strtolower(($names[$template->id]['place'] ?? '').'|'.($names[$template->id]['proposalsText'] ?? '')))
            ->values();
    }

    /**
     * template id => place and proposals (MonthlyReportNames), read once.
     *
     * @return array<int, array{place: string, proposals: list<array{id: int, slug: string, name: string, number: string|null}>, proposalsText: string}>
     */
    #[Computed]
    public function names(): array
    {
        if (! $this->canView('reports')) {
            return [];
        }

        return app(MonthlyReportNames::class)->forTemplates(
            ReportTemplate::query()
                ->select(['id', 'city_id', 'program_id', 'center_id', 'city_wide'])
                ->whereIn('id', MonthlyReport::query()->select('report_template_id'))
                ->get()
        );
    }

    /**
     * template id => who prepares (the first responsible, D-84) and the
     * responsible MEAL officer (through the legacy project until coverage
     * moves to places), as names. A fixed number of queries.
     *
     * @return array<int, array{owner: string, officer: string}>
     */
    #[Computed]
    public function people(): array
    {
        $templates = $this->rows;

        if ($templates->isEmpty()) {
            return [];
        }

        $responsibles = app(MonthlyReportPreparers::class)->responsiblesByTemplate($templates);
        $officers = $templates->mapWithKeys(fn ($template): array => [
            $template->id => MealCoverage::responsibleForPlace($template->place()),
        ])->all();
        $owners = array_map(fn (array $ids): ?int => $ids[0] ?? null, $responsibles);
        $names = HrEmployee::query()->whereKey(array_values(array_unique(array_filter([...array_values($owners), ...array_values($officers)]))))->get(HrEmployee::NameColumns)
            ->mapWithKeys(fn (HrEmployee $employee): array => [$employee->id => $employee->name]);

        $result = [];

        foreach ($templates as $template) {
            $owner = $owners[$template->id] ?? null;
            $officer = $officers[$template->id] ?? null;
            $result[$template->id] = [
                'owner' => $owner === null ? '' : (string) $names->get($owner, ''),
                'officer' => $officer === null ? '' : (string) $names->get($officer, ''),
            ];
        }

        return $result;
    }

    /**
     * Every report in the shown months that needs someone (late, sent back,
     * with MEAL), late first then oldest month, for the S/M list.
     *
     * @param  Collection<int, ReportTemplate>  $templates
     * @return Collection<int, array{template: ReportTemplate, report: MonthlyReport, state: string}>
     */
    public function attention(Collection $templates): Collection
    {
        $from = $this->months()[0];
        $states = $this->state === '' ? ['late', 'returned', 'submitted'] : [$this->state];

        return $templates
            ->flatMap(fn (ReportTemplate $template): Collection => $template->monthlyReports
                ->filter(fn (MonthlyReport $report): bool => $report->period->greaterThanOrEqualTo($from) && in_array($this->stateOf($report), $states, true))
                ->map(fn (MonthlyReport $report): array => ['template' => $template, 'report' => $report, 'state' => $this->stateOf($report)]))
            ->sortBy([
                fn (array $a, array $b): int => array_search($a['state'], self::States, true) <=> array_search($b['state'], self::States, true),
                fn (array $a, array $b): int => $a['report']->period <=> $b['report']->period,
            ])
            ->values();
    }

    /**
     * One column header: three letters of the month, or in Arabic its number,
     * because three letters of the Levantine names collide (two months read
     * the same). The full name stays in the header's title.
     */
    public function monthLabel(CarbonImmutable $month): string
    {
        return app()->getLocale() === Locale::Arabic->value ? $month->format('n') : mb_substr($month->translatedFormat('M'), 0, 3);
    }

    public function stateOf(MonthlyReport $report): string
    {
        return match (true) {
            $report->status === MonthlyReportStatus::Validated => 'validated',
            $report->status === MonthlyReportStatus::Submitted => 'submitted',
            // Overdue rule: a report sent back late (returned or reopened) keeps its state until
            // its new deadline ends, then it is late; the month having passed does not make it late.
            $report->revised_due_on?->lessThan(CarbonImmutable::today()) === true => 'late',
            $report->revised_due_on !== null => $report->returned_at !== null ? 'returned' : 'draft',
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
        $template = $row instanceof ReportTemplate ? $row : $row['template'];

        return array_filter(['program' => $template->program?->name], fn (?string $name): bool => $name !== null && $name !== '');
    }
};
?>

@php
    $months = $this->months();
    $from = $months[0];
    $templates = $this->rows;
    $names = $this->names;
    $people = $this->people;
    $inWindow = $templates->flatMap(fn ($template) => $template->monthlyReports->filter(fn ($report) => $report->period->greaterThanOrEqualTo($from)));
    $counts = $inWindow->countBy(fn ($report) => $this->stateOf($report));
    $wide = $this->isWide();
    $glyphs = ['validated' => '✓', 'submitted' => '•', 'late' => '!', 'returned' => '↺', 'draft' => ''];

    if ($wide) {
        $list = $state === '' ? $templates : $templates->filter(fn ($template) => $template->monthlyReports->contains(fn ($report) => $report->period->greaterThanOrEqualTo($from) && $this->stateOf($report) === $state))->values();
    } else {
        $list = $this->attention($templates);
    }

    $pg = $this->paginate($list, self::PerPage);
@endphp

<x-desk.panel :empty="$counts->isEmpty()" :facets="$pg['facets']" :filtered="$state !== ''" id="matrix" register="reports.monthly-matrix" :size="$size" :cols="$gridCols" :height="$gridRows" :index="$index" :total="$total" :count="($counts['late'] ?? 0) > 0 ? $counts['late'] : null">
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
        {{-- A narrow card hides the oldest months (desk.css): this line says so, and each row's marker keeps a late report in a hidden month visible. --}}
        <p class="dk-mx-recent">{{ __('desk.matrix_latest_months') }}</p>
        <div class="dk-mx2" {{ $this->fitAttributes($pg) }} role="table" aria-label="{{ __('desk.panel.matrix.title') }}">
            <div class="dk-mx2-r dk-mx2-h" role="row" data-fit-head>
                <span role="columnheader">{{ __('desk.project') }}</span>
                @foreach ($months as $month)
                    <span role="columnheader" title="{{ $month->translatedFormat('F Y') }}">{{ $this->monthLabel($month) }}</span>
                @endforeach
                <span role="columnheader">{{ __('desk.validated') }}</span>
            </div>
            @foreach ($pg['slice'] as $template)
                @php
                    $byMonth = $template->monthlyReports->keyBy(fn ($report) => $report->period->format('Y-m'));
                    $place = $names[$template->id]['place'] ?? '';
                    $covers = ($names[$template->id]['proposalsText'] ?? '') !== '' ? $names[$template->id]['proposalsText'] : __('monthly_reports.no_proposal');
                    $owner = ($people[$template->id]['owner'] ?? '') !== '' ? $people[$template->id]['owner'] : '—';
                    $officer = ($people[$template->id]['officer'] ?? '') !== '' ? $people[$template->id]['officer'] : '—';
                    $lateHere = $template->monthlyReports->filter(fn ($report) => $report->period->greaterThanOrEqualTo($from) && $this->stateOf($report) === 'late')->count();
                @endphp
                {{-- A row is a template: its place, then the proposals it covers, so two reports of one place are told apart. --}}
                <div class="dk-mx2-r" role="row" wire:key="mx-{{ $template->id }}" data-matrix-template="{{ $template->id }}">
                    <span class="dk-mx2-n" role="rowheader"><a href="{{ route('monthly-reports.index') }}" wire:navigate title="{{ $place }} · {{ $covers }}" dir="auto">{{ $place }}</a><span class="dk-is" title="{{ $covers }} · {{ __('desk.matrix_people', ['owner' => $owner, 'officer' => $officer]) }}"><span dir="auto" data-matrix-proposals>{{ $covers }}</span> · @if ($lateHere > 0)<b class="dk-mx2-late dk-num">{{ trans_choice('desk.matrix_row_late', $lateHere, ['count' => $lateHere]) }}</b>@endif{{ $owner }} → {{ $officer }}</span></span>
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
                    <span role="cell" class="dk-mx2-v dk-num">{{ $template->monthlyReports->where('status', MonthlyReportStatus::Validated)->count() }}/{{ $template->monthlyReports->count() }}</span>
                </div>
            @endforeach
        </div>
    @else
        <ul class="dk-mx-list" {{ $this->fitAttributes($pg) }}>
            @foreach ($pg['slice'] as $row)
                <li wire:key="mxl-{{ $row['report']->id }}">
                    @php
                        $rowNames = $names[$row['template']->id] ?? ['place' => '', 'proposalsText' => ''];
                        $rowCovers = $rowNames['proposalsText'] !== '' ? $rowNames['proposalsText'] : __('monthly_reports.no_proposal');
                    @endphp
                    <a href="{{ route('monthly-reports.show', $row['report']) }}" wire:navigate class="dk-mx-ln" title="{{ $rowCovers }} · {{ $rowNames['place'] }}" dir="auto">{{ $rowCovers }}</a>
                    <span class="dk-pill dk-mxp-{{ $row['state'] }}">{{ __('desk.report_state.'.$row['state']) }}</span>
                    <span class="dk-is">{{ $row['report']->period->translatedFormat('F Y') }} · <span dir="auto">{{ $rowNames['place'] }}</span> · {{ ($people[$row['template']->id]['owner'] ?? '') !== '' ? $people[$row['template']->id]['owner'] : '—' }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
