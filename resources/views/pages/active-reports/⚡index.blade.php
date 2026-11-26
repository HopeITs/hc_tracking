<?php

use App\Enums\ActiveReportType;
use App\Models\Donor;
use App\Models\DonorReport;
use App\Models\Program;
use App\Support\Reporting\ActiveReportRow;
use App\Support\Reporting\ActiveReports;
use App\Support\Reporting\MonthlyTrackerGrid;
use App\Support\Reporting\ReportingDuties;
use App\Support\Reporting\ReportTrackerDoors;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
| Report Tracker (redesign 2026-11-26): first a choice of three doors —
| Monthly reports · My reporting duties · Donor reports — limited to what the
| viewer may open, then a view made for that kind: a places × months grid,
| what waits on me grouped by what to do, or donor reports in lanes by due
| date. A viewer with one kind skips the doors. The last kind chosen is kept
| in the session until logout. Rows come from ActiveReports only (each
| record's own rules decide who sees it); every cell, card and line opens the
| report's own page. Hand-recorded reports are not tracked here.
*/
new #[Layout('layouts::desk'), Title('titles.active_reports')] class extends Component
{
    public const SessionKey = 'active_reports.kind';

    private const MaxMonths = 12;

    /** Months the grid shows at once, so it never scrolls sideways. */
    private const VisibleMonths = 6;

    /** @var list<int> */
    public const PageSizes = [15, 30, 50];

    /** '' (the doors), duties, monthly or donor. */
    #[Url(except: '')]
    public string $kind = '';

    #[Url(except: '')]
    public string $search = '';

    /** The chip of the open view: '' is its first chip. */
    #[Url(except: '')]
    public string $show = '';

    #[Url(except: '')]
    public string $program = '';

    #[Url(except: '')]
    public string $donor = '';

    /** The monthly window, 'Y-m'; '' means the default window. */
    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    /** The page of places in the monthly grid. */
    #[Url(as: 'p', except: 1)]
    public int $page = 1;

    #[Url(as: 'per', except: 15)]
    public int $perPage = 15;

    /** How many months the visible slice sits before the window's last month. */
    public int $monthShift = 0;

    public function mount(): void
    {
        $this->kind = $this->kindFromLegacyLink() ?? $this->kind;

        if ($this->kind === '' && is_string($remembered = session(self::SessionKey))) {
            $this->kind = $remembered;
        }

        $this->normalise();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['kind', 'show', 'program', 'donor'], true)) {
            $this->normalise();
        }

        if (in_array($property, ['kind', 'show', 'program', 'search', 'perPage'], true)) {
            $this->page = 1;
        }
    }

    /**
     * Open a door (or a switcher tab) and remember it until logout.
     */
    public function chooseKind(string $kind): void
    {
        if (! in_array($kind, $this->offeredKinds(), true)) {
            return;
        }

        if ($kind !== $this->kind) {
            $this->show = '';
        }

        $this->kind = $kind;
        session([self::SessionKey => $kind]);
    }

    /**
     * Back to the doors; the remembered kind is forgotten, so the doors are the start again.
     */
    public function backToDoors(): void
    {
        $this->kind = '';
        $this->show = '';
        session()->forget(self::SessionKey);
        $this->normalise();
    }

    public function setChip(string $chip): void
    {
        $this->show = $this->show === $chip ? '' : $chip;
        $this->page = 1;
        $this->normalise();
    }

    /**
     * Step the visible months back (positive) or forward (negative) inside the window.
     */
    public function shiftMonths(int $by): void
    {
        $this->monthShift = max(0, min($this->maxMonthShift(), $this->monthShift + $by));
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, min($this->placePage['pages'], $page));
    }

    private function maxMonthShift(): int
    {
        return max(0, count($this->grid['months']) - self::VisibleMonths);
    }

    /**
     * The months the grid shows now: at most six, ending at the window's last month unless stepped back.
     *
     * @return list<string>
     */
    #[Computed]
    public function visibleMonths(): array
    {
        $months = $this->grid['months'];
        $shift = min($this->monthShift, $this->maxMonthShift());

        return count($months) <= self::VisibleMonths ? $months : array_slice($months, count($months) - self::VisibleMonths - $shift, self::VisibleMonths);
    }

    /**
     * Whether the open chip keeps a monthly cell (a dropped cell is dimmed).
     */
    public function keepsCell(ActiveReportRow $row): bool
    {
        return match ($this->show) {
            'needs' => $row->needsMe,
            'late' => in_array($row->state(), ['late', 'returned'], true),
            'wait' => $row->state() === 'wait',
            'done' => $row->state() === 'done',
            default => $row->state() !== 'done',
        };
    }

    /**
     * One page of places. A chip other than Open drops the places with nothing it keeps in the visible months.
     *
     * @return array{places: list<array<string, mixed>>, total: int, page: int, pages: int, first: int, last: int}
     */
    #[Computed]
    public function placePage(): array
    {
        $visible = $this->visibleMonths;
        $places = array_values(array_filter($this->grid['places'], function (array $place) use ($visible): bool {
            if ($this->show === '') {
                return true;
            }

            foreach ($visible as $month) {
                if (($row = $place['cells'][$month] ?? null) !== null && $this->keepsCell($row)) {
                    return true;
                }
            }

            return false;
        }));
        $size = in_array($this->perPage, self::PageSizes, true) ? $this->perPage : self::PageSizes[0];
        $total = count($places);
        $pages = max(1, (int) ceil($total / $size));
        $page = max(1, min($pages, $this->page));

        return [
            'places' => array_slice($places, ($page - 1) * $size, $size),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'first' => $total === 0 ? 0 : ($page - 1) * $size + 1,
            'last' => min($total, $page * $size),
        ];
    }

    /**
     * Change the monthly window. From after To, a bad month or more than twelve
     * months is refused with a field error and the window stays as it was.
     */
    public function setRange(string $from, string $to): void
    {
        $this->resetErrorBag(['from', 'to']);
        $start = $this->parseMonth($from);
        $end = $this->parseMonth($to);

        if ($start === null || $end === null) {
            $this->addError('from', __('active_reports.range_invalid'));

            return;
        }

        if ($start->greaterThan($end)) {
            $this->addError('from', __('active_reports.range_reversed'));

            return;
        }

        if ($start->diffInMonths($end) >= self::MaxMonths) {
            $this->addError('from', trans_choice('active_reports.range_too_long', self::MaxMonths, ['count' => self::MaxMonths]));

            return;
        }

        [$this->from, $this->to] = [$start->format('Y-m'), $end->format('Y-m')];
        $this->monthShift = 0;
        $this->page = 1;
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'show', 'program', 'donor', 'from', 'to', 'page', 'monthShift']);
        $this->resetErrorBag();
    }

    /**
     * The kinds of report the viewer may have, whatever is open today: monthly
     * for anyone with reports.view (their place decides which rows), donor as
     * DonorReportPolicy::viewAny decides.
     *
     * @return list<string>
     */
    #[Computed]
    public function kinds(): array
    {
        return ReportTrackerDoors::kinds(auth()->user());
    }

    /**
     * What the doors and the switcher offer: My reporting duties only beside two kinds.
     *
     * @return list<string>
     */
    public function offeredKinds(): array
    {
        return ReportTrackerDoors::offered($this->kinds);
    }

    /**
     * Every open report the viewer may open.
     *
     * @return Collection<int, ActiveReportRow>
     */
    #[Computed]
    public function rows(): Collection
    {
        return app(ActiveReports::class)->for(auth()->user());
    }

    /**
     * Every finished report the viewer may open: validated months for the grid, submitted donor reports for their lane.
     *
     * @return Collection<int, ActiveReportRow>
     */
    #[Computed]
    public function closedRows(): Collection
    {
        return app(ActiveReports::class)->for(auth()->user(), closed: true);
    }

    /**
     * @return Collection<int, ActiveReportRow>
     */
    private function ofType(Collection $rows, ActiveReportType $type): Collection
    {
        return ReportTrackerDoors::ofType($rows, $type);
    }

    /**
     * @param  Collection<int, ActiveReportRow>  $rows
     * @return Collection<int, ActiveReportRow>
     */
    private function searched(Collection $rows): Collection
    {
        $needle = mb_strtolower(trim($this->search));

        return $needle === '' ? $rows : $rows->filter(fn (ActiveReportRow $row): bool => str_contains($row->search, $needle))->values();
    }

    /**
     * The default monthly window: the last six started months, reaching back
     * to the oldest open month (at most twelve months) so no backlog hides.
     *
     * @return array{0: string, 1: string}
     */
    #[Computed]
    public function defaultWindow(): array
    {
        return ReportTrackerDoors::defaultWindow($this->rows);
    }

    /**
     * @return array{0: string, 1: string}
     */
    #[Computed]
    public function window(): array
    {
        return $this->from !== '' && $this->to !== '' ? [$this->from, $this->to] : $this->defaultWindow;
    }

    /**
     * The monthly grid as the view shows it: program filter and search applied.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function grid(): array
    {
        $rows = $this->searched($this->ofType($this->rows->concat($this->closedRows), ActiveReportType::Monthly))
            ->when($this->program !== '', fn (Collection $rows) => $rows->filter(fn (ActiveReportRow $row): bool => $row->programId === (int) $this->program));

        return MonthlyTrackerGrid::build($rows, ...$this->window);
    }

    /**
     * Donor reports in lanes: overdue, due within thirty days, later, and
     * submitted with a due date in the last twelve months.
     *
     * @return array{overdue: list<ActiveReportRow>, soon: list<ActiveReportRow>, later: list<ActiveReportRow>, submitted: list<ActiveReportRow>}
     */
    #[Computed]
    public function donorLanes(): array
    {
        return $this->lanesOf(
            $this->searched($this->ofType($this->rows, ActiveReportType::Donor)),
            $this->searched($this->ofType($this->closedRows, ActiveReportType::Donor)),
            mineOnly: $this->show === 'mine',
            donorId: $this->donor === '' ? null : (int) $this->donor,
        );
    }

    /**
     * The donor lanes regrouped by donor: one card each, its reports overdue
     * first, then by due date, sent ones last. Donors with the most overdue lead.
     *
     * @return list<array{key: string, name: string, overdue: int, open: int, sent: int, next: ?CarbonImmutable, rows: list<array{row: ActiveReportRow, lane: string}>}>
     */
    #[Computed]
    public function donorGroups(): array
    {
        $groups = [];

        foreach ($this->donorLanes as $lane => $rows) {
            foreach ($rows as $row) {
                $key = (string) ($row->donorId ?? 'none');
                $groups[$key] ??= ['key' => $key, 'name' => $row->donorName ?? '—', 'overdue' => 0, 'open' => 0, 'sent' => 0, 'next' => null, 'rows' => []];
                $groups[$key]['rows'][] = ['row' => $row, 'lane' => $lane];

                if ($lane === 'submitted') {
                    $groups[$key]['sent']++;

                    continue;
                }

                $groups[$key]['open']++;
                $groups[$key]['overdue'] += $lane === 'overdue' ? 1 : 0;

                if ($row->due !== null && ($groups[$key]['next'] === null || $row->due->lessThan($groups[$key]['next']))) {
                    $groups[$key]['next'] = $row->due;
                }
            }
        }

        usort($groups, fn (array $a, array $b): int => [$b['overdue'], $b['open'] > 0, $a['next']?->getTimestamp() ?? PHP_INT_MAX, mb_strtolower($a['name'])]
            <=> [$a['overdue'], $a['open'] > 0, $b['next']?->getTimestamp() ?? PHP_INT_MAX, mb_strtolower($b['name'])]);

        return $groups;
    }

    /**
     * @param  Collection<int, ActiveReportRow>  $open
     * @param  Collection<int, ActiveReportRow>  $closed
     * @return array{overdue: list<ActiveReportRow>, soon: list<ActiveReportRow>, later: list<ActiveReportRow>, submitted: list<ActiveReportRow>}
     */
    private function lanesOf(Collection $open, Collection $closed, bool $mineOnly = false, ?int $donorId = null): array
    {
        return ReportTrackerDoors::lanes($open, $closed, $mineOnly, $donorId);
    }

    /**
     * @return array{fill: list<\App\Support\Reporting\DutyLine>, check: list<\App\Support\Reporting\DutyLine>, send: list<\App\Support\Reporting\DutyLine>, total: int, late: int}
     */
    #[Computed]
    public function duties(): array
    {
        return ReportingDuties::group($this->searched($this->rows));
    }

    /**
     * The door and switcher numbers: read before any search or filter, so a
     * door always says what clicking it shows at rest.
     *
     * @return array{duties: array{total: int, late: int, check: int}, monthly: array{needs: int, late: int, open: int, done: int}, donor: array{needs: int, late: int, open: int, done: int}}
     */
    #[Computed]
    public function doorCounts(): array
    {
        return ReportTrackerDoors::counts($this->rows, $this->closedRows);
    }

    /**
     * The pictures on the doors: six months of monthly states, the next donor report, the next three duties.
     *
     * @return array{months: list<array{month: string, done: int, wait: int, draft: int, late: int}>, nextDonor: ?ActiveReportRow, nextDuties: list<\App\Support\Reporting\DutyLine>}
     */
    #[Computed]
    public function doorPictures(): array
    {
        [, $to] = $this->defaultWindow;
        $last = $this->parseMonth($to) ?? CarbonImmutable::today()->startOfMonth();
        $grid = MonthlyTrackerGrid::build($this->ofType($this->rows->concat($this->closedRows), ActiveReportType::Monthly), $last->subMonths(5)->format('Y-m'), $to);
        $months = [];

        foreach ($grid['months'] as $month) {
            $states = ['done' => 0, 'wait' => 0, 'draft' => 0, 'late' => 0];

            foreach ($grid['places'] as $place) {
                $state = $place['cells'][$month]?->state();

                if ($state !== null) {
                    $states[$state === 'returned' ? 'late' : $state]++;
                }
            }

            $months[] = ['month' => $month, ...$states];
        }

        $duties = ReportingDuties::group($this->rows);

        return [
            'months' => $months,
            'nextDonor' => $this->ofType($this->rows, ActiveReportType::Donor)->sortBy(fn (ActiveReportRow $row): int => $row->due?->getTimestamp() ?? PHP_INT_MAX)->first(),
            'nextDuties' => array_slice([...$duties['fill'], ...$duties['check'], ...$duties['send']], 0, 3),
        ];
    }

    /**
     * The programs of the viewer's monthly reports, for the filter.
     *
     * @return array<int, string> id => name
     */
    #[Computed]
    public function programOptions(): array
    {
        $ids = $this->ofType($this->rows->concat($this->closedRows), ActiveReportType::Monthly)->pluck('programId')->filter()->unique()->all();

        return Program::options()->filter(fn (Program $program): bool => in_array($program->id, $ids, true))
            ->mapWithKeys(fn (Program $program): array => [$program->id => $program->name])
            ->all();
    }

    /**
     * The donors of the viewer's donor reports, for the filter.
     *
     * @return Collection<int, Donor>
     */
    #[Computed]
    public function donorOptions(): Collection
    {
        $ids = $this->ofType($this->rows->concat($this->closedRows), ActiveReportType::Donor)->pluck('donorId')->filter()->unique()->all();

        return $ids === [] ? new Collection : Donor::query()->whereKey($ids)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * An old Report Tracker link (?tab=, ?type=) answered by the kind it meant.
     */
    private function kindFromLegacyLink(): ?string
    {
        $type = request()->query('type');

        if (is_string($type) && in_array($type, ['monthly', 'donor'], true)) {
            return $type;
        }

        return match (request()->query('tab')) {
            'needs' => 'duties',
            'all', 'done' => '',
            default => null,
        };
    }

    private function normalise(): void
    {
        $offered = $this->offeredKinds();

        if (count($offered) === 1) {
            $this->kind = $offered[0];
        } elseif (! in_array($this->kind, $offered, true)) {
            $this->kind = '';
        }

        $chips = match ($this->kind) {
            'monthly' => ['needs', 'late', 'wait', 'done'],
            'donor' => ['mine'],
            default => [],
        };

        if (! in_array($this->show, $chips, true)) {
            $this->show = '';
        }

        foreach (['program', 'donor'] as $filter) {
            if (! ctype_digit($this->{$filter})) {
                $this->{$filter} = '';
            }
        }

        $start = $this->parseMonth($this->from);
        $end = $this->parseMonth($this->to);

        if ($start === null || $end === null || $start->greaterThan($end) || $start->diffInMonths($end) >= self::MaxMonths) {
            [$this->from, $this->to] = ['', ''];
        }
    }

    private function parseMonth(string $month): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m', $month);

        return $parsed === false ? null : $parsed;
    }
};
?>

@php
    $offered = $this->offeredKinds();
    $single = count($offered) === 1;
    $counts = $this->doorCounts;
    $badges = ['monthly' => $counts['monthly']['needs'], 'duties' => $counts['duties']['total'], 'donor' => $counts['donor']['needs']];
    $titles = ['' => __('titles.active_reports'), 'monthly' => __('active_reports.kind_monthly'), 'duties' => __('active_reports.kind_duties'), 'donor' => __('active_reports.kind_donor')];
    $pageCount = match ($kind) {
        'monthly' => $this->grid['counts']['open'],
        'donor' => $counts['donor']['open'],
        'duties' => $this->duties['total'],
        default => null,
    };
@endphp

<x-desk.register
    class="@container rt-page"
    heading-id="active-reports-heading"
    :title="$titles[$kind]"
    :count="$pageCount"
    data-active-reports
    data-tracker-kind="{{ $kind === '' ? 'doors' : $kind }}"
>
    @if ($kind !== '' && ! $single)
        <x-slot:back>
            <button type="button" class="dk-back" wire:click="backToDoors" data-tracker-back><x-desk.icon name="back" class="dk-flip" />{{ __('active_reports.all_kinds') }}</button>
        </x-slot:back>
    @endif

    @if ($kind !== '')
        <x-slot:actions>
            @include('pages.active-reports._switcher')
        </x-slot:actions>

        <x-slot:search>
            <x-desk.register-search :label="__('active_reports.search_reports')" :placeholder="__('active_reports.search_placeholder')" wire:model.live.debounce.300ms="search" />
        </x-slot:search>

        @if ($kind === 'monthly' || $kind === 'donor')
            <x-slot:filters>
                @include('pages.active-reports._filters')
            </x-slot:filters>
        @endif
    @endif

    @if ($single)
        <p class="rt-note" data-tracker-single><x-desk.icon name="info" /> {{ __('active_reports.single_kind') }}</p>
    @endif

    @if ($kind === '')
        @include('pages.active-reports._doors')
    @elseif ($kind === 'monthly')
        @include('pages.active-reports._monthly-grid')
    @elseif ($kind === 'donor')
        @include('pages.active-reports._donor-lanes')
    @else
        @include('pages.active-reports._duties')
    @endif
</x-desk.register>
