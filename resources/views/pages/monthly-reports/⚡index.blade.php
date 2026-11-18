<?php

use App\Actions\MonthlyReports\OpenMonthlyReport;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReopenRequestStatus;
use App\Livewire\Concerns\FiltersReportPlaces;
use App\Models\HrCity;
use App\Models\MonthlyReport;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Support\MonthlyReportGenerator;
use App\Support\MonthlyReportNames;
use App\Support\MonthlyReportPeriods;
use App\Support\MonthlyReportPreparers;
use App\Support\Reporting\ExtractionFilter;
use App\Support\Reporting\PlaceBridge;
use App\Support\Reporting\ReportExtraction;
use App\Support\Reporting\ReportPlace;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::desk'), Title('titles.monthly_reports')] class extends Component {
    use FiltersReportPlaces;

    #[Url(except: '')]
    public string $tab = '';

    /** The D-79 filters (Y-05), on both tabs, read through ReportExtraction. */
    #[Url(except: '')]
    public string $proposal = '';

    #[Url(except: '')]
    public string $city = '';

    #[Url(except: '')]
    public string $program = '';

    /** A place: 'c{hrCenterId}', 'field-{cityId}' (Field work) or 'unset' (Center not set). */
    #[Url(except: '')]
    public string $center = '';

    /** The first month listed, 'Y-m'. */
    #[Url(except: '')]
    public string $from = '';

    /** The last month listed, 'Y-m'; open lists the months still to come too. */
    #[Url(except: '')]
    public string $to = '';

    /** Legacy (before Y-05): one month, translated to From / To on mount. */
    #[Url(except: '')]
    public string $month = '';

    /** Legacy (before Y-05): a project id, translated to its place on mount. */
    #[Url(except: '')]
    public string $project = '';

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

    /** A center pre-selects its city. */
    public function updatedCenter(string $value): void
    {
        $cityId = $this->cityOfCenter($value);

        if ($cityId !== null) {
            $this->city = (string) $cityId;
        }
    }

    /** A city narrows the centers: a center elsewhere is cleared. */
    public function updatedCity(string $value): void
    {
        $centerCity = $this->cityOfCenter($this->center);

        if ($value !== '' && $centerCity !== null && (string) $centerCity !== $value) {
            $this->center = '';
        }
    }

    public function updatedFrom(): void
    {
        $this->checkWindow();
    }

    public function updatedTo(): void
    {
        $this->checkWindow();
    }

    /**
     * MEAL lands on its queue when it prepares nothing itself (T17B Q3).
     * Someone who cannot validate never lands there, even from a link: the
     * queue would only ever be empty for them. Old `?month=` and `?project=`
     * links are translated once, then dropped from the URL.
     */
    public function mount(): void
    {
        $this->translateLegacyFilters();

        $offered = $this->canValidate() ? ['mine', 'validate'] : ['mine'];

        if (! in_array($this->tab, $offered, true)) {
            $this->tab = $this->myTemplateIds() === [] && $this->canValidate() ? 'validate' : 'mine';
        }

        $this->checkWindow();
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
     * authorizes `open` on the template and builds the month's lines.
     */
    public function fillMonth(int $monthlyReportId): void
    {
        $report = MonthlyReport::query()->with('template')->findOrFail($monthlyReportId);

        try {
            $opened = app(OpenMonthlyReport::class)->handle($report->template, $report->period);
        } catch (ValidationException $exception) {
            // The refusal used to land on a "month" error this page never shows, so Fill looked dead.
            Flux::toast(variant: 'danger', text: collect($exception->errors())->flatten()->first());

            return;
        }

        $this->redirectRoute('monthly-reports.show', $opened, navigate: true);
    }

    /**
     * The templates the user prepares (D-84): a responsible of a row at the
     * template's place, or a supervisor of one (MonthlyReportPreparers).
     *
     * @return list<int>
     */
    protected function myTemplateIds(): array
    {
        return once(fn (): array => app(MonthlyReportPreparers::class)->templateIdsFor(auth()->user()));
    }

    /**
     * Whether a Proposal, City, Program, Center, From or To filter is on.
     */
    #[Computed]
    public function extractionFiltersAreActive(): bool
    {
        return $this->proposal !== '' || $this->city !== '' || $this->program !== '' || $this->center !== '' || $this->from !== '' || $this->to !== '';
    }

    /**
     * The page's filters as ReportExtraction reads them (D-79). A Field work
     * center brings its own city; an unknown value is no filter.
     */
    #[Computed]
    public function extractionFilter(): ExtractionFilter
    {
        $center = $this->centerArgumentsOf($this->center);

        return new ExtractionFilter(
            proposalId: $this->existingId($this->proposal, Proposal::class),
            cityId: $center['cityId'] ?? $this->existingId($this->city, null, array_values($this->allCities()->keys()->all())),
            programId: $this->existingId($this->program, Program::class),
            centerId: $center['centerId'] ?? null,
            from: $this->monthOf($this->from),
            to: $this->monthOf($this->to),
            cityWide: $center['cityWide'] ?? false,
            centerUnset: $center['centerUnset'] ?? false,
        );
    }

    /**
     * The monthly reports the filters select, as an id set, through the one
     * extraction query (contracts §14.1): this page builds no `where` of its
     * own. Null while no filter is on (nothing to narrow); empty while From
     * is after To. An open To lists the months still to come (Y-05 Q1).
     *
     * @return array<int, true>|null
     */
    #[Computed]
    public function extractedReportIds(): ?array
    {
        if (! $this->extractionFiltersAreActive) {
            return null;
        }

        if ($this->windowIsReversed()) {
            return [];
        }

        return ReportExtraction::for($this->extractionFilter)->reports(openEndUnbounded: true)
            ->pluck('mr.id')
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => true])
            ->all();
    }

    /**
     * Whether the filters keep this report.
     */
    protected function isExtracted(int $monthlyReportId): bool
    {
        return $this->extractedReportIds === null || isset($this->extractedReportIds[$monthlyReportId]);
    }

    /**
     * Every generated month of the templates the user prepares, one group
     * per template (D-76), named by its place and its proposals so two
     * reports of one place are told apart. Each month carries its state,
     * due date, overdue marker and submit blocker. A fixed number of
     * queries: the templates and their proposals, the reports, the
     * responsibles once for `can('open')`, the names, the working days.
     *
     * Every month is read whatever the filters: the submit blocker reads the
     * months before it (filteredMonths() narrows afterwards).
     *
     * `hasIndicators` is false when no month has a line, so "no indicators"
     * is said once for the template. A template with no proposal has no
     * preparer (D-84), so it is never listed here.
     *
     * @return list<array{template: ReportTemplate, place: string, proposals: list<array{id: int, slug: string, name: string, number: string|null}>, title: string, hasIndicators: bool, months: list<array{report: MonthlyReport, key: string, state: string, due: ?CarbonImmutable, overdue: bool, blocked: ?string, can_fill: bool}>}>
     */
    #[Computed]
    public function myMonths(): array
    {
        $templateIds = $this->myTemplateIds();

        if ($templateIds === []) {
            return [];
        }

        $reports = MonthlyReport::query()
            ->whereIn('report_template_id', $templateIds)
            ->withCount('lines')
            ->withExists(['reopenRequests as reopen_pending' => fn (Builder $query) => $query->where('status', ReopenRequestStatus::Pending)])
            ->orderBy('period')
            ->get(['id', 'slug', 'report_template_id', 'period', 'status', 'returned_at', 'revised_due_on'])
            ->groupBy('report_template_id');

        if ($reports->isEmpty()) {
            return [];
        }

        // The month's unit is its template (D-76): its window, working days, preparers and names decide.
        $templates = ReportTemplate::query()
            ->whereKey($reports->keys()->all())
            ->with(['proposals' => fn ($query) => $query->select([...MonthlyReportGenerator::WindowColumns, 'proposals.slug', 'proposals.name', 'proposals.internal_project_number'])])
            ->get()
            ->keyBy('id');
        $workingDays = MonthlyReportPeriods::workingDaysOf($templates);
        $responsibles = app(MonthlyReportPreparers::class)->responsiblesByTemplate($templates);
        $names = app(MonthlyReportNames::class)->forTemplates($templates);

        $user = auth()->user();
        $groups = [];

        foreach ($templates as $templateId => $template) {
            /** @var Collection<int, MonthlyReport> $templateReports */
            $templateReports = new Collection($reports->get($templateId, new Collection)->all());
            $periods = MonthlyReportPeriods::fromLoaded($template, $templateReports, $workingDays[$templateId] ?? null);
            $mayOpen = $user->can('open', [MonthlyReport::class, $template, $responsibles[$templateId] ?? []]);
            $months = [];

            foreach ($templateReports as $report) {
                $report->setRelation('template', $template);
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
                // A month OpenMonthlyReport would refuse (no period, or outside it) gets its reason, not a Fill that silently fails.
                $refusal = $editable ? OpenMonthlyReport::refusal($template, $report->period) : null;

                $months[] = [
                    'report' => $report,
                    'key' => $report->period->format('Y-m'),
                    'state' => $state,
                    'due' => $due,
                    'overdue' => $editable && $due !== null && CarbonImmutable::today()->greaterThan($due),
                    'blocked' => $editable ? $refusal ?? $periods->canSubmit($report) : null,
                    'can_fill' => $editable && $mayOpen && $refusal === null,
                ];
            }

            $groups[] = [
                'template' => $template,
                'place' => $names[$templateId]['place'],
                'proposals' => $names[$templateId]['proposals'],
                'title' => $names[$templateId]['proposalsText'] !== '' ? $names[$templateId]['proposalsText'] : __('monthly_reports.no_proposal'),
                'hasIndicators' => $templateReports->contains(fn (MonthlyReport $report): bool => (int) $report->getAttribute('lines_count') > 0),
                'months' => $months,
            ];
        }

        // Two templates of one place sit together, told apart by their proposals.
        usort($groups, fn (array $a, array $b): int => [mb_strtolower($a['place']), mb_strtolower($a['title']), $a['template']->id]
            <=> [mb_strtolower($b['place']), mb_strtolower($b['title']), $b['template']->id]);

        return $groups;
    }

    /**
     * "My reports" narrowed by the extraction filters: each template keeps
     * the months they select (`months`) and its whole run for the month
     * strip (`strip`); a template left with no month is dropped.
     *
     * @return list<array{template: ReportTemplate, place: string, proposals: list<array{id: int, slug: string, name: string, number: string|null}>, title: string, hasIndicators: bool, strip: list<array<string, mixed>>, months: list<array{report: MonthlyReport, key: string, state: string, due: ?CarbonImmutable, overdue: bool, blocked: ?string, can_fill: bool}>}>
     */
    #[Computed]
    public function filteredMonths(): array
    {
        $groups = [];

        foreach ($this->myMonths as $group) {
            $months = array_values(array_filter($group['months'], fn (array $row): bool => $this->isExtracted($row['report']->id)));

            if ($months !== []) {
                $groups[] = [...$group, 'strip' => $group['months'], 'months' => $months];
            }
        }

        return $groups;
    }

    /**
     * The months waiting on the preparer, gathered from every template at
     * the top of "My reports": the ones they can fill, overdue first, then
     * oldest first. A reader who cannot fill gets none, so no list.
     *
     * @return list<array{group: array<string, mixed>, row: array{report: MonthlyReport, key: string, state: string, due: ?CarbonImmutable, overdue: bool, blocked: ?string, can_fill: bool}}>
     */
    #[Computed]
    public function needsYou(): array
    {
        $items = [];

        // A big figure already narrows the list to one kind of month; repeating some of them on top would only blur it.
        if ($this->show !== '') {
            return [];
        }

        foreach ($this->filteredMonths as $group) {
            foreach ($group['months'] as $row) {
                if ($row['can_fill']) {
                    $items[] = ['group' => $group, 'row' => $row];
                }
            }
        }

        usort($items, fn (array $a, array $b): int => [! $a['row']['overdue'], $a['row']['report']->period] <=> [! $b['row']['overdue'], $b['row']['report']->period]);

        return $items;
    }

    /**
     * The big figures over the open tab, each one a button that narrows the
     * list to what it counts (showOnly()). They read every filter except the
     * figure itself (the status on "To validate", `show` on both), so a click
     * lists exactly that many (Y-05, as the reports register does).
     *
     * @return list<array{key: string, value: int, label: string, tone: string, pressed: bool}>
     */
    #[Computed]
    public function figures(): array
    {
        if ($this->tab === 'validate') {
            if ($this->validatable->isEmpty()) {
                return [];
            }

            $submitted = $this->filteredValidatable->filter(fn (MonthlyReport $report): bool => $report->status === MonthlyReportStatus::Submitted);

            return [
                ['key' => 'waiting', 'value' => $submitted->count(), 'label' => __('monthly_reports.figure_waiting'), 'tone' => $submitted->isNotEmpty() ? 'dk-warn' : '', 'pressed' => $this->status === 'submitted' && $this->show === ''],
                ['key' => 'not_seen', 'value' => $submitted->whereNull('seen_by_meal_at')->count(), 'label' => __('monthly_reports.figure_not_seen'), 'tone' => '', 'pressed' => $this->show === 'not_seen'],
                ['key' => 'validated', 'value' => $this->filteredValidatable->count() - $submitted->count(), 'label' => __('monthly_reports.figure_validated'), 'tone' => 'dk-good', 'pressed' => $this->status === 'validated'],
            ];
        }

        if ($this->myMonths === []) {
            return [];
        }

        $rows = collect($this->filteredMonths)->flatMap(fn (array $group): array => $group['months']);
        $overdue = $rows->where('overdue', true)->count();

        return array_values(array_filter([
            $rows->contains('can_fill', true) ? ['key' => 'to_fill', 'value' => $rows->where('can_fill', true)->count(), 'label' => __('monthly_reports.figure_to_fill'), 'tone' => '', 'pressed' => $this->show === 'to_fill'] : null,
            ['key' => 'overdue', 'value' => $overdue, 'label' => __('monthly_reports.figure_overdue'), 'tone' => $overdue > 0 ? 'dk-bad' : '', 'pressed' => $this->show === 'overdue'],
            ['key' => 'with_meal', 'value' => $rows->where('state', 'submitted')->count(), 'label' => __('monthly_reports.figure_with_meal'), 'tone' => '', 'pressed' => $this->show === 'with_meal'],
            ['key' => 'validated', 'value' => $rows->whereIn('state', ['validated', 'reopen_pending'])->count(), 'label' => __('monthly_reports.figure_validated'), 'tone' => 'dk-good', 'pressed' => $this->show === 'validated'],
        ]));
    }

    /**
     * "My reports" as it is drawn: each template with its months narrowed
     * to the filters and the big figure picked, the validated months set
     * apart to fold away (unless "validated" is the figure picked), and
     * every month kept for the template's month strip. A template left with
     * no month is dropped.
     *
     * @return list<array{template: ReportTemplate, place: string, proposals: list<array{id: int, slug: string, name: string, number: string|null}>, title: string, hasIndicators: bool, strip: list<array<string, mixed>>, open: list<array<string, mixed>>, folded: list<array<string, mixed>>}>
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

        foreach ($this->filteredMonths as $group) {
            $rows = array_values(array_filter($group['months'], $matches));

            if ($rows === []) {
                continue;
            }

            $fold = $this->show === '';

            $groups[] = [
                'template' => $group['template'],
                'place' => $group['place'],
                'proposals' => $group['proposals'],
                'title' => $group['title'],
                'hasIndicators' => $group['hasIndicators'],
                'strip' => $group['strip'],
                'open' => $fold ? array_values(array_filter($rows, fn (array $row): bool => $row['state'] !== 'validated')) : $rows,
                'folded' => $fold ? array_values(array_filter($rows, fn (array $row): bool => $row['state'] === 'validated')) : [],
            ];
        }

        return $groups;
    }

    /**
     * Every report this validator may act on, in a status a validator deals
     * with: submitted or validated. A draft is still the preparer's and is
     * never listed here, whichever status is chosen. The filters' options
     * are built from this, not from the filtered queue, so a choice never
     * drops out of its own list.
     *
     * @return Collection<int, MonthlyReport>
     */
    #[Computed]
    public function validatable(): Collection
    {
        return MonthlyReport::query()
            ->validatableBy(auth()->user())
            ->where('status', '!=', MonthlyReportStatus::Draft)
            ->with(['template:id,city_id,program_id,center_id,city_wide', 'submitter:id,hr_user_id'])
            ->orderBy('period')
            ->orderBy('id')
            ->get(['id', 'slug', 'report_template_id', 'period', 'status', 'submitted_at', 'submitted_by', 'seen_by_meal_at']);
    }

    /**
     * The validator's reports the extraction filters keep (Y-05).
     *
     * @return Collection<int, MonthlyReport>
     */
    #[Computed]
    public function filteredValidatable(): Collection
    {
        return $this->extractedReportIds === null
            ? $this->validatable
            : $this->validatable->filter(fn (MonthlyReport $report): bool => $this->isExtracted($report->id))->values();
    }

    /**
     * The queue's templates named by place and proposals, read once.
     *
     * @return array<int, array{place: string, proposals: list<array{id: int, slug: string, name: string, number: string|null}>, proposalsText: string}>
     */
    #[Computed]
    public function queueNames(): array
    {
        return app(MonthlyReportNames::class)->forTemplates($this->validatable->pluck('template')->filter());
    }

    /**
     * The validator queue (contracts §13.1 item 6) after the extraction
     * filters, submitted by default.
     *
     * @return Collection<int, MonthlyReport>
     */
    #[Computed]
    public function queue(): Collection
    {
        return $this->selectedStatus === null
            ? $this->filteredValidatable
            : $this->filteredValidatable->filter(fn (MonthlyReport $report): bool => $report->status === $this->selectedStatus)->values();
    }

    /**
     * The queue after the "Not seen yet" figure, in memory.
     *
     * @return Collection<int, MonthlyReport>
     */
    #[Computed]
    public function filteredQueue(): Collection
    {
        return $this->queue
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
     * The templates the open tab can list (its scope before any filter),
     * with their place columns: the options offer nothing that would list
     * nothing.
     *
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    #[Computed]
    public function scopeTemplates(): \Illuminate\Support\Collection
    {
        $ids = $this->tab === 'validate'
            ? $this->validatable->pluck('report_template_id')->unique()->values()->all()
            : $this->myTemplateIds();

        return $ids === [] ? collect() : DB::table('report_templates')->whereIn('id', $ids)->get(['id', 'city_id', 'program_id']);
    }

    /**
     * @return \Illuminate\Support\Collection<int, stdClass> rows of id, name
     */
    #[Computed]
    public function proposalOptions(): \Illuminate\Support\Collection
    {
        $ids = $this->scopeTemplates->pluck('id')->all();

        return $ids === [] ? collect() : DB::table('proposals')
            ->whereIn('id', DB::table('proposal_report_template')->whereIn('report_template_id', $ids)->select('proposal_id'))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);
    }

    /**
     * City names of the scope's places.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function cityOptions(): array
    {
        $ids = $this->scopeTemplates->pluck('city_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->all();

        return $this->allCities()->only($ids)->mapWithKeys(fn (HrCity $city): array => [(int) $city->id => (string) $city->name])->all();
    }

    /**
     * Program names of the scope's places.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function programOptions(): array
    {
        $ids = $this->scopeTemplates->pluck('program_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->all();

        return collect(Program::options()->all())
            ->filter(fn (Program $program): bool => in_array($program->id, $ids, true))
            ->mapWithKeys(fn (Program $program): array => [$program->id => $program->name])
            ->all();
    }

    /**
     * The scope's places (D-79, D-82, D-88), narrowed by City and Program.
     *
     * @return list<array{value: string, label: string}>
     */
    #[Computed]
    public function centerOptions(): array
    {
        $filter = $this->extractionFilter;

        return $this->centerOptionsFor($filter->cityWide ? null : $filter->cityId, $filter->programId, $this->scopeTemplates->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all());
    }

    /**
     * The monthly matrix for a template's place, with this list's proposal
     * and window (Y-05: a report row links back to the matrix).
     */
    protected function matrixUrlFor(ReportTemplate $template): string
    {
        $place = $template->city_id === null || $template->program_id === null
            ? null
            : new ReportPlace((int) $template->city_id, (int) $template->program_id, $template->center_id === null ? null : (int) $template->center_id, (bool) $template->city_wide);
        $filter = $this->extractionFilter;

        return route('reports.monthly-matrix', array_filter([
            'proposal' => $filter->proposalId,
            'city' => $place?->cityId,
            'program' => $place?->programId,
            'center' => $place === null ? null : $this->centerValueOf($place),
            'from' => $filter->from?->format('Y-m'),
            'to' => $filter->to?->format('Y-m'),
        ], fn (mixed $value): bool => $value !== null));
    }

    public function clearFilters(): void
    {
        $this->reset('proposal', 'city', 'program', 'center', 'from', 'to', 'status', 'show');
        $this->resetErrorBag('from');
    }

    /**
     * Whether From and To are both set and From is after To.
     */
    protected function windowIsReversed(): bool
    {
        $from = $this->monthOf($this->from);
        $to = $this->monthOf($this->to);

        return $from !== null && $to !== null && $from->greaterThan($to);
    }

    /**
     * Refuses a From after To with a field error (never swapped).
     */
    protected function checkWindow(): void
    {
        $this->resetErrorBag('from');

        if ($this->windowIsReversed()) {
            $this->addError('from', __('reports.matrix.from_after_to'));
        }
    }

    /**
     * The links of before Y-05 still open: `?month=` becomes From = To =
     * that month, `?project=` the project's place (its city, program and
     * center, through the bridge until Y-17). Both are then dropped.
     */
    protected function translateLegacyFilters(): void
    {
        if ($this->month !== '' && $this->monthOf($this->month) !== null && $this->from === '' && $this->to === '') {
            $this->from = $this->month;
            $this->to = $this->month;
        }

        if ($this->project !== '' && ctype_digit($this->project) && strlen($this->project) <= 18) {
            $place = app(PlaceBridge::class)->placeOfProject((int) $this->project);

            if ($place !== null) {
                $this->city = (string) $place->cityId;
                $this->program = (string) $place->programId;
                $this->center = $this->centerValueOf($place);
            }
        }

        $this->month = '';
        $this->project = '';
    }
};
?>

@php
    $extractionFiltersAreActive = $this->extractionFiltersAreActive;
    $filtersAreActive = $extractionFiltersAreActive || ($tab === 'validate' && ($status !== 'submitted' || $show === 'not_seen'));
    $listFilter = $this->extractionFilter;
    $centerValue = match (true) {
        $listFilter->cityWide && $listFilter->cityId !== null => 'field-'.$listFilter->cityId,
        $listFilter->centerUnset => 'unset',
        $listFilter->centerId !== null => 'c'.$listFilter->centerId,
        default => '',
    };
    $monthFieldClass = 'block h-9 w-full min-w-0 touch-manipulation rounded-[10px] border border-(--line-2) bg-(--paper) px-2.5 text-[12.5px] font-semibold text-(--ink) hover:border-(--ink-3) max-[720px]:h-11 aria-invalid:border-status-critical';
    $monthLabelClass = 'mb-1.5 block text-[11px] font-semibold uppercase leading-4 tracking-[0.1em] text-secondary-medium dark:text-ink-muted';
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
        {{-- The D-79 filters on both tabs (Y-05), read through ReportExtraction like the monthly matrix. --}}
        <x-slot:filters>
            <div class="contents" data-list-filters>
                <x-ui.header-filter-select :label="__('reports.matrix.proposal')" wire:model.live="proposal" :value="(string) ($listFilter->proposalId ?? '')">
                    <option value="">{{ __('reports.matrix.all_proposals') }}</option>
                    @foreach ($this->proposalOptions as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('common.city')" wire:model.live="city" :value="(string) ($listFilter->cityId ?? '')">
                    <option value="">{{ __('monthly_reports.any_city') }}</option>
                    @foreach ($this->cityOptions as $cityId => $cityName)
                        <option value="{{ $cityId }}">{{ $cityName }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('reports.matrix.program')" wire:model.live="program" :value="(string) ($listFilter->programId ?? '')">
                    <option value="">{{ __('reports.matrix.all_programs') }}</option>
                    @foreach ($this->programOptions as $programId => $programName)
                        <option value="{{ $programId }}">{{ $programName }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('reports.matrix.center')" wire:model.live="center" :value="$centerValue">
                    <option value="">{{ __('reports.matrix.all_centers') }}</option>
                    @foreach ($this->centerOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <div class="min-w-0" data-list-from>
                    <label for="monthly-reports-from" class="{{ $monthLabelClass }}">{{ __('reports.matrix.from') }}</label>
                    <input id="monthly-reports-from" type="month" wire:model.live="from" class="{{ $monthFieldClass }}" dir="ltr" @error('from') aria-invalid="true" aria-describedby="monthly-reports-from-error" @enderror />
                    @error('from')
                        <p id="monthly-reports-from-error" class="mt-1 flex items-center gap-1 text-xs text-status-critical" data-list-from-error>
                            <flux:icon.exclamation-triangle variant="micro" class="size-4 shrink-0" aria-hidden="true" />
                            <span>{{ $message }}</span>
                        </p>
                    @enderror
                </div>

                <div class="min-w-0" data-list-to>
                    <label for="monthly-reports-to" class="{{ $monthLabelClass }}">{{ __('reports.matrix.to') }}</label>
                    <input id="monthly-reports-to" type="month" wire:model.live="to" class="{{ $monthFieldClass }}" dir="ltr" />
                </div>

                @if ($tab === 'validate')
                    <x-ui.header-filter-select :label="__('common.status')" wire:model.live="status" :value="$this->selectedStatus?->value ?? 'all'">
                        <option value="submitted">{{ __('monthly_reports.status_to_validate') }}</option>
                        <option value="validated">{{ MonthlyReportStatus::Validated->label() }}</option>
                        <option value="all">{{ __('monthly_reports.status_all') }}</option>
                    </x-ui.header-filter-select>
                @endif

                @if ($filtersAreActive)
                    <div class="flex items-end">
                        <button type="button" class="dk-btn dk-ghost dk-sm" wire:click="clearFilters"><x-desk.icon name="x" />{{ __('desk.register.clear') }}</button>
                    </div>
                @endif
            </div>
        </x-slot:filters>


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
