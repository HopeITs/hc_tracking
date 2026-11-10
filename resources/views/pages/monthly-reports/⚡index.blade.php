<?php

use App\Actions\MonthlyReports\OpenMonthlyReport;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReopenRequestStatus;
use App\Models\HrCity;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Support\MonthlyReportPeriods;
use App\Support\SupervisionMap;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::desk'), Title('titles.monthly_reports')] class extends Component {
    #[Url(except: '')]
    public string $tab = '';

    #[Url(except: '')]
    public string $city = '';

    #[Url(except: '')]
    public string $project = '';

    #[Url(except: '')]
    public string $month = '';

    #[Url(except: 'submitted')]
    public string $status = 'submitted';

    /**
     * The big figure the list is narrowed to ("overdue", "with_meal"…), or ''
     * for everything. On "To validate" only "not_seen" lives here; the other
     * figures there move the status filter instead.
     */
    #[Url(except: '')]
    public string $show = '';

    /**
     * A big figure was clicked: narrow the open tab to what it counts, or
     * clear it when it is already the one shown.
     */
    public function showOnly(string $key): void
    {
        if ($this->tab === 'validate') {
            if (! in_array($key, ['waiting', 'not_seen', 'validated'], true)) {
                return;
            }

            $this->status = $key === 'validated' ? 'validated' : 'submitted';
            $this->show = $key === 'not_seen' && $this->show !== 'not_seen' ? 'not_seen' : '';

            return;
        }

        if (in_array($key, ['to_fill', 'overdue', 'with_meal', 'validated'], true)) {
            $this->show = $this->show === $key ? '' : $key;
        }
    }

    public function updatedTab(): void
    {
        $this->show = '';
    }

    /**
     * MEAL lands on its queue when it prepares nothing itself (T17B Q3).
     * Someone who cannot validate never lands there, even from a link: the
     * queue would only ever be empty for them.
     */
    public function mount(): void
    {
        $offered = $this->canValidate() ? ['mine', 'validate'] : ['mine'];

        if (! in_array($this->tab, $offered, true)) {
            $this->tab = $this->myProjectIds() === [] && $this->canValidate() ? 'validate' : 'mine';
        }
    }

    /**
     * Whether this user has a validation queue at all: the first guard of
     * MonthlyReport::validatableBy(). Without it "To validate" was offered to
     * every Team Member and Public Relations Manager, and could only ever say
     * "Nothing to validate" (page review, 5 Nov 2026).
     */
    #[Computed]
    public function canValidate(): bool
    {
        $user = auth()->user();

        return $user->can('reports.approve') && ! $user->hasRole('General Manager');
    }

    /**
     * Open (prepare) a generated month and go to it. OpenMonthlyReport
     * authorizes `open` on the project and builds the month's lines.
     */
    public function fillMonth(int $monthlyReportId): void
    {
        $report = MonthlyReport::query()->with('project')->findOrFail($monthlyReportId);

        try {
            $opened = app(OpenMonthlyReport::class)->handle($report->project, $report->period);
        } catch (ValidationException $exception) {
            // The refusal used to land on a "month" error this page never shows, so Fill looked dead.
            Flux::toast(variant: 'danger', text: collect($exception->errors())->flatten()->first());

            return;
        }

        $this->redirectRoute('monthly-reports.show', $opened, navigate: true);
    }

    /**
     * The projects the user prepares: owned (owner or program manager) and,
     * through SupervisionMap, the projects of the people they supervise.
     *
     * @return list<int>
     */
    protected function myProjectIds(): array
    {
        $user = auth()->user();
        $ids = $user->ownedProjectIds();

        if ($user->employee_id !== null) {
            $supervisees = SupervisionMap::superviseesOf($user->employee_id);

            if ($supervisees !== []) {
                $ids = [...$ids, ...Project::query()->whereIn('user_id', $supervisees)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()];
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * Every generated month of the user's projects, grouped by project, with
     * its state, due date, overdue marker and submit blocker. Two queries
     * (projects, reports); due-ness comes from MonthlyReportPeriods.
     *
     * `notice` is what holds the whole project back (no start or end date),
     * said once over its months rather than under each of them;
     * `hasIndicators` is false when no month has a line, so the "no
     * indicators" sentence is said once for the project too.
     *
     * @return list<array{project: Project, notice: ?string, hasIndicators: bool, months: list<array{report: MonthlyReport, key: string, state: string, due: ?CarbonImmutable, overdue: bool, blocked: ?string, can_fill: bool}>}>
     */
    #[Computed]
    public function myMonths(): array
    {
        $projectIds = $this->myProjectIds();

        if ($projectIds === []) {
            return [];
        }

        $projects = Project::query()
            ->whereIn('id', $projectIds)
            ->orderBy('name')
            ->get(['id', 'slug', 'name', 'user_id', 'program_id', 'center_id', 'city_id', 'working_days', 'start_date', 'end_date']);

        $reports = MonthlyReport::query()
            ->whereIn('project_id', $projectIds)
            ->withCount('lines')
            ->withExists(['reopenRequests as reopen_pending' => fn (Builder $query) => $query->where('status', ReopenRequestStatus::Pending)])
            ->orderBy('period')
            ->get(['id', 'slug', 'project_id', 'period', 'status', 'returned_at', 'revised_due_on'])
            ->groupBy('project_id');

        $user = auth()->user();
        $groups = [];

        foreach ($projects as $project) {
            /** @var Collection<int, MonthlyReport> $projectReports */
            $projectReports = $reports->get($project->id, new Collection);

            if ($projectReports->isEmpty()) {
                continue;
            }

            $periods = MonthlyReportPeriods::fromLoaded($project, $projectReports);
            $mayOpen = $user->can('open', [MonthlyReport::class, $project]);
            $notice = $project->start_date === null || $project->end_date === null ? __('monthly_reports.project_has_no_period') : null;
            $months = [];

            foreach ($projectReports as $report) {
                $report->setRelation('project', $project);
                $started = $periods->hasStarted($report->period);
                $hasLines = (int) $report->getAttribute('lines_count') > 0;
                $state = match (true) {
                    ! $started => 'upcoming',
                    $report->status === MonthlyReportStatus::Validated => (bool) $report->getAttribute('reopen_pending') ? 'reopen_pending' : 'validated',
                    $report->status === MonthlyReportStatus::Submitted => 'submitted',
                    $report->returned_at !== null => 'returned',
                    default => 'draft',
                };
                $editable = in_array($state, ['draft', 'returned'], true);
                $due = $hasLines ? $periods->effectiveDueDate($report) : null;
                // A month OpenMonthlyReport would refuse (no project period, or outside it) gets its reason, not a Fill that silently fails.
                $refusal = $editable ? OpenMonthlyReport::refusal($project, $report->period) : null;

                $months[] = [
                    'report' => $report,
                    'key' => $report->period->format('Y-m'),
                    'state' => $state,
                    'due' => $due,
                    'overdue' => $editable && $due !== null && CarbonImmutable::today()->greaterThan($due),
                    // The project-wide notice is said once over the months, not again under each.
                    'blocked' => match (true) {
                        ! $editable, $refusal !== null && $refusal === $notice => null,
                        default => $refusal ?? $periods->canSubmit($report),
                    },
                    'can_fill' => $editable && $mayOpen && $refusal === null,
                ];
            }

            $groups[] = [
                'project' => $project,
                'notice' => $notice,
                'hasIndicators' => $projectReports->contains(fn (MonthlyReport $report): bool => (int) $report->getAttribute('lines_count') > 0),
                'months' => $months,
            ];
        }

        return $groups;
    }

    /**
     * The months waiting on the preparer, gathered from every project at the
     * top of "My reports": the ones they can fill, overdue first, then oldest
     * first. A reader who cannot fill gets none, so no list.
     *
     * @return list<array{project: Project, row: array{report: MonthlyReport, key: string, state: string, due: ?CarbonImmutable, overdue: bool, blocked: ?string, can_fill: bool}}>
     */
    #[Computed]
    public function needsYou(): array
    {
        $items = [];

        // A big figure already narrows the list to one kind of month; repeating some of them on top would only blur it.
        if ($this->show !== '') {
            return [];
        }

        foreach ($this->myMonths as $group) {
            foreach ($group['months'] as $row) {
                if ($row['can_fill']) {
                    $items[] = ['project' => $group['project'], 'row' => $row];
                }
            }
        }

        usort($items, fn (array $a, array $b): int => [! $a['row']['overdue'], $a['row']['report']->period] <=> [! $b['row']['overdue'], $b['row']['report']->period]);

        return $items;
    }

    /**
     * The big figures over the open tab, each one a button that narrows the
     * list to what it counts (showOnly()). "To validate" counts the whole
     * queue, not the filtered rows, so the figures keep the picture while the
     * count badge follows the filters.
     *
     * @return list<array{key: string, value: int, label: string, tone: string, pressed: bool}>
     */
    #[Computed]
    public function figures(): array
    {
        if ($this->tab === 'validate') {
            $submitted = $this->validatable->filter(fn (MonthlyReport $report): bool => $report->status === MonthlyReportStatus::Submitted);

            if ($this->validatable->isEmpty()) {
                return [];
            }

            return [
                ['key' => 'waiting', 'value' => $submitted->count(), 'label' => __('monthly_reports.figure_waiting'), 'tone' => $submitted->isNotEmpty() ? 'dk-warn' : '', 'pressed' => $this->status === 'submitted' && $this->show === ''],
                ['key' => 'not_seen', 'value' => $submitted->whereNull('seen_by_meal_at')->count(), 'label' => __('monthly_reports.figure_not_seen'), 'tone' => '', 'pressed' => $this->show === 'not_seen'],
                ['key' => 'validated', 'value' => $this->validatable->count() - $submitted->count(), 'label' => __('monthly_reports.figure_validated'), 'tone' => 'dk-good', 'pressed' => $this->status === 'validated'],
            ];
        }

        $rows = collect($this->myMonths)->flatMap(fn (array $group): array => $group['months']);

        if ($rows->isEmpty()) {
            return [];
        }

        $overdue = $rows->where('overdue', true)->count();

        return array_values(array_filter([
            $rows->contains('can_fill', true) ? ['key' => 'to_fill', 'value' => $rows->where('can_fill', true)->count(), 'label' => __('monthly_reports.figure_to_fill'), 'tone' => '', 'pressed' => $this->show === 'to_fill'] : null,
            ['key' => 'overdue', 'value' => $overdue, 'label' => __('monthly_reports.figure_overdue'), 'tone' => $overdue > 0 ? 'dk-bad' : '', 'pressed' => $this->show === 'overdue'],
            ['key' => 'with_meal', 'value' => $rows->where('state', 'submitted')->count(), 'label' => __('monthly_reports.figure_with_meal'), 'tone' => '', 'pressed' => $this->show === 'with_meal'],
            ['key' => 'validated', 'value' => $rows->whereIn('state', ['validated', 'reopen_pending'])->count(), 'label' => __('monthly_reports.figure_validated'), 'tone' => 'dk-good', 'pressed' => $this->show === 'validated'],
        ]));
    }

    /**
     * "My reports" as it is drawn: each project with its months narrowed to
     * the big figure picked, the validated months set apart to fold away
     * (unless "validated" is the figure picked), and every month kept for
     * the project's month strip. A project left with no month is dropped.
     *
     * @return list<array{project: Project, notice: ?string, hasIndicators: bool, strip: list<array<string, mixed>>, open: list<array<string, mixed>>, folded: list<array<string, mixed>>}>
     */
    #[Computed]
    public function visibleGroups(): array
    {
        $matches = fn (array $row): bool => match ($this->show) {
            'to_fill' => $row['can_fill'],
            'overdue' => $row['overdue'],
            'with_meal' => $row['state'] === 'submitted',
            'validated' => in_array($row['state'], ['validated', 'reopen_pending'], true),
            default => true,
        };
        $groups = [];

        foreach ($this->myMonths as $group) {
            $rows = array_values(array_filter($group['months'], $matches));

            if ($rows === []) {
                continue;
            }

            $fold = $this->show === '';

            $groups[] = [
                'project' => $group['project'],
                'notice' => $group['notice'],
                'hasIndicators' => $group['hasIndicators'],
                'strip' => $group['months'],
                'open' => $fold ? array_values(array_filter($rows, fn (array $row): bool => $row['state'] !== 'validated')) : $rows,
                'folded' => $fold ? array_values(array_filter($rows, fn (array $row): bool => $row['state'] === 'validated')) : [],
            ];
        }

        return $groups;
    }

    /**
     * Every report this validator may act on, in a status a validator deals
     * with: submitted or validated. A draft is still the preparer's and is
     * never listed here, whichever status is chosen.
     *
     * The city, project and month options are built from this, not from the
     * status-filtered queue: built from the queue, a month chosen before the
     * status changed dropped out of its own list, so its trigger fell back to
     * "Any month" while the rows were still narrowed to it (page review,
     * 5 Nov 2026).
     *
     * @return Collection<int, MonthlyReport>
     */
    #[Computed]
    public function validatable(): Collection
    {
        return MonthlyReport::query()
            ->validatableBy(auth()->user())
            ->where('status', '!=', MonthlyReportStatus::Draft)
            ->with(['project:id,slug,name,city_id', 'submitter:id,hr_user_id'])
            ->orderBy('period')
            ->orderBy('id')
            ->get(['id', 'slug', 'project_id', 'period', 'status', 'submitted_at', 'submitted_by', 'seen_by_meal_at']);
    }

    /**
     * The validator queue (contracts §13.1 item 6), submitted by default.
     *
     * @return Collection<int, MonthlyReport>
     */
    #[Computed]
    public function queue(): Collection
    {
        return $this->selectedStatus === null
            ? $this->validatable
            : $this->validatable->filter(fn (MonthlyReport $report): bool => $report->status === $this->selectedStatus)->values();
    }

    /**
     * The queue after the city, project and month filters, in memory.
     *
     * @return Collection<int, MonthlyReport>
     */
    #[Computed]
    public function filteredQueue(): Collection
    {
        return $this->queue
            ->when($this->city !== '', fn (Collection $rows) => $rows->filter(fn (MonthlyReport $report): bool => (string) $report->project->city_id === $this->city))
            ->when($this->project !== '', fn (Collection $rows) => $rows->filter(fn (MonthlyReport $report): bool => (string) $report->project_id === $this->project))
            ->when($this->month !== '', fn (Collection $rows) => $rows->filter(fn (MonthlyReport $report): bool => $report->period->format('Y-m') === $this->month))
            ->when($this->show === 'not_seen', fn (Collection $rows) => $rows->filter(fn (MonthlyReport $report): bool => $report->seen_by_meal_at === null))
            ->values();
    }

    /**
     * The status the queue is filtered by, null for every status.
     */
    #[Computed]
    public function selectedStatus(): ?MonthlyReportStatus
    {
        return $this->status === 'all' ? null : (MonthlyReportStatus::tryFrom($this->status) ?? MonthlyReportStatus::Submitted);
    }

    /**
     * City names of the queued projects, for the filter and the rows.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function cityNames(): array
    {
        $ids = $this->validatable->map(fn (MonthlyReport $report): ?int => $report->project->city_id === null ? null : (int) $report->project->city_id)->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return HrCity::query()->whereIn('id', $ids)->get()->mapWithKeys(fn (HrCity $city): array => [(int) $city->id => (string) $city->name])->sort()->all();
    }

    /**
     * @return array<string, string> 'Y-m' => translated month
     */
    #[Computed]
    public function monthOptions(): array
    {
        return $this->validatable->mapWithKeys(fn (MonthlyReport $report): array => [$report->period->format('Y-m') => $report->period->translatedFormat('F Y')])->sortKeys()->all();
    }

    public function clearFilters(): void
    {
        $this->reset('city', 'project', 'month', 'status', 'show');
    }
};
?>

@php
    $filtersAreActive = $city !== '' || $project !== '' || $month !== '' || $status !== 'submitted' || ($tab === 'validate' && $show === 'not_seen');
@endphp

<x-desk.register
    class="@container/directory"
    heading-id="monthly-reports-heading"
    :title="__('titles.monthly_reports')"
    :count="$tab === 'validate' ? $this->filteredQueue->count() : null"
    data-monthly-reports-intro
    :view="$tab"
    view-model="tab"
    :views="array_filter([
        'mine' => ['icon' => 'file', 'label' => __('monthly_reports.tab_mine')],
        'validate' => $this->canValidate ? ['icon' => 'check', 'label' => __('monthly_reports.tab_validate')] : null,
    ])"
>
        @if ($tab === 'validate')
            <x-slot:filters>
                <x-ui.header-filter-select :label="__('common.city')" wire:model.live="city" :value="$city">
                    <option value="">{{ __('monthly_reports.any_city') }}</option>
                    @foreach ($this->cityNames as $cityId => $cityName)
                        <option value="{{ $cityId }}">{{ $cityName }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('common.project')" wire:model.live="project" :value="$project">
                    <option value="">{{ __('targets.any_project') }}</option>
                    @foreach ($this->validatable->pluck('project')->unique('id')->sortBy('name') as $projectOption)
                        <option value="{{ $projectOption->id }}">{{ $projectOption->name }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('monthly_reports.month')" wire:model.live="month" :value="$month">
                    <option value="">{{ __('monthly_reports.any_month') }}</option>
                    @foreach ($this->monthOptions as $monthKey => $monthLabel)
                        <option value="{{ $monthKey }}">{{ $monthLabel }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('common.status')" wire:model.live="status" :value="$this->selectedStatus?->value ?? 'all'">
                    <option value="submitted">{{ __('monthly_reports.status_to_validate') }}</option>
                    <option value="validated">{{ MonthlyReportStatus::Validated->label() }}</option>
                    <option value="all">{{ __('monthly_reports.status_all') }}</option>
                </x-ui.header-filter-select>

                @if ($filtersAreActive)
                    <button type="button" class="dk-btn dk-ghost dk-sm" wire:click="clearFilters"><x-desk.icon name="x" />{{ __('desk.register.clear') }}</button>
                @endif
            </x-slot:filters>
        @endif


    <div class="flex max-w-3xl flex-col gap-8">
        {{-- The headline figures as large type that also filters the list: still type on the page, not stat tiles (.ai/rules/pages.md). --}}
        @if ($this->figures !== [])
            <div class="dk-figs-lg" role="group" aria-label="{{ __('monthly_reports.figures_label') }}" data-figures>
                @foreach ($this->figures as $figure)
                    <button type="button" wire:click="showOnly('{{ $figure['key'] }}')" @class([$figure['tone']]) data-figure="{{ $figure['key'] }}" data-value="{{ $figure['value'] }}" aria-pressed="{{ $figure['pressed'] ? 'true' : 'false' }}" wire:key="figure-{{ $figure['key'] }}"><b>{{ $figure['value'] }}</b><span>{{ $figure['label'] }}</span></button>
                @endforeach
            </div>
        @endif

        @if ($tab === 'validate')
            @include('pages.monthly-reports._index-validate')
        @else
            @include('pages.monthly-reports._index-mine')
        @endif
    </div>
</x-desk.register>
