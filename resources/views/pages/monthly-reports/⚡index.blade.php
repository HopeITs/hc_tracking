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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.monthly_reports')] class extends Component {
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
     * MEAL lands on its queue when it prepares nothing itself (T17B Q3).
     */
    public function mount(): void
    {
        if (! in_array($this->tab, ['mine', 'validate'], true)) {
            $this->tab = $this->myProjectIds() === [] && auth()->user()->can('reports.approve') ? 'validate' : 'mine';
        }
    }

    /**
     * Open (prepare) a generated month and go to it. OpenMonthlyReport
     * authorizes `open` on the project and builds the month's lines.
     */
    public function fillMonth(int $monthlyReportId): void
    {
        $report = MonthlyReport::query()->with('project')->findOrFail($monthlyReportId);
        $opened = app(OpenMonthlyReport::class)->handle($report->project, $report->period);

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
     * @return list<array{project: Project, months: list<array{report: MonthlyReport, key: string, state: string, due: ?CarbonImmutable, overdue: bool, blocked: ?string, can_fill: bool}>}>
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
            ->get(['id', 'slug', 'name', 'user_id', 'program_id', 'working_days', 'start_date', 'end_date']);

        $reports = MonthlyReport::query()
            ->whereIn('project_id', $projectIds)
            ->withCount('lines')
            ->withExists(['reopenRequests as reopen_pending' => fn (Builder $query) => $query->where('status', ReopenRequestStatus::Pending)])
            ->orderBy('period')
            ->get(['id', 'slug', 'project_id', 'period', 'status', 'returned_at'])
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
                $due = $hasLines ? $periods->dueDate($report->period) : null;

                $months[] = [
                    'report' => $report,
                    'key' => $report->period->format('Y-m'),
                    'state' => $state,
                    'due' => $due,
                    'overdue' => $editable && $due !== null && CarbonImmutable::today()->greaterThan($due),
                    'blocked' => $editable ? $periods->canSubmit($report) : null,
                    'can_fill' => $editable && $mayOpen,
                ];
            }

            $groups[] = ['project' => $project, 'months' => $months];
        }

        return $groups;
    }

    /**
     * The validator queue (contracts §13.1 item 6), submitted by default.
     *
     * @return Collection<int, MonthlyReport>
     */
    #[Computed]
    public function queue(): Collection
    {
        return MonthlyReport::query()
            ->validatableBy(auth()->user())
            ->when($this->selectedStatus !== null, fn (Builder $query) => $query->where('status', $this->selectedStatus))
            ->with(['project:id,slug,name,city_id', 'submitter:id,hr_user_id'])
            ->orderBy('period')
            ->orderBy('id')
            ->get(['id', 'slug', 'project_id', 'period', 'status', 'submitted_at', 'submitted_by', 'seen_by_meal_at']);
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
        $ids = $this->queue->map(fn (MonthlyReport $report): ?int => $report->project->city_id === null ? null : (int) $report->project->city_id)->filter()->unique()->values()->all();

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
        return $this->queue->mapWithKeys(fn (MonthlyReport $report): array => [$report->period->format('Y-m') => $report->period->translatedFormat('F Y')])->sortKeys()->all();
    }

    public function clearFilters(): void
    {
        $this->reset('city', 'project', 'month', 'status');
    }
};
?>

@php
    $filtersAreActive = $city !== '' || $project !== '' || $month !== '' || $status !== 'submitted';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="monthly-reports-heading" data-monthly-reports-intro>
    <x-ui.page-header
        :title="__('titles.monthly_reports')"
        heading-id="monthly-reports-heading"
        :count="$tab === 'validate' ? $this->filteredQueue->count() : null"
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
                    @foreach ($this->queue->pluck('project')->unique('id')->sortBy('name') as $projectOption)
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
            </x-slot:filters>
        @endif

        <x-slot:actions>
            <x-ui.view-toggle
                wire-model="tab"
                :active="$tab"
                :label="__('monthly_reports.tabs_label')"
                :options="[
                    ['key' => 'mine', 'label' => __('monthly_reports.tab_mine'), 'icon' => 'document-text'],
                    ['key' => 'validate', 'label' => __('monthly_reports.tab_validate'), 'icon' => 'check-badge'],
                ]"
            />
        </x-slot:actions>
    </x-ui.page-header>

    @if ($tab === 'validate')
        @include('pages.monthly-reports._index-validate')
    @else
        @include('pages.monthly-reports._index-mine')
    @endif
</section>
