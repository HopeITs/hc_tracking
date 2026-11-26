<?php

use App\Actions\Proposals\SyncLogframeTask;
use App\Actions\Results\SaveAssumption;
use App\Enums\AssumptionStatus;
use App\Enums\ResultType;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\Indicators\ManagesIndicatorReadings;
use App\Livewire\Concerns\Proposals\CopiesProgramFramework;
use App\Livewire\Concerns\Proposals\EditsCentreTargets;
use App\Livewire\Concerns\Proposals\ManagesFrameworkBoard;
use App\Livewire\Concerns\Proposals\ManagesProposalRisks;
use App\Livewire\Concerns\Proposals\ReadsProposalFramework;
use App\Livewire\Concerns\Results\BuildsResultsFramework;
use App\Livewire\Concerns\Results\BuildsResultsTree;
use App\Livewire\Concerns\Results\ManagesResultForm;
use App\Models\Assumption;
use App\Models\HrEmployee;
use App\Models\Indicator;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\Task;
use App\Support\FrameworkChecks;
use App\Support\MonthlyReportLineBuilder;
use App\Support\ProposalFramework;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The logical framework box inside the proposal card (proposal page redesign,
 * stage 2): the framework task bar, the tools, and the Results chain or
 * Matrix layout, the Activity board (ManagesFrameworkBoard) and the
 * program framework picker.
 *
 * Reads go through ReadsProposalFramework (on BuildsResultsFramework, pinned
 * to this proposal by fixedProposalId()); writes through the existing result
 * form and indicator quick form, SaveAssumption and SyncLogframeTask, each
 * authorising and validating again. View preferences are saved per person
 * in users.page_prefs, never in the browser.
 */
new class extends Component {
    use BuildsResultsFramework;
    use BuildsResultsTree;
    use CopiesProgramFramework;
    use EditsCentreTargets;
    use ManagesFrameworkBoard;
    use ManagesIndicatorReadings;
    use ManagesProposalRisks;
    use ManagesResultForm;
    use ReadsProposalFramework;

    /** The users.page_prefs key of this box's layout preferences. */
    private const PREFS = 'proposal_framework';

    /** How many proposals' open results are remembered per person. */
    private const OPEN_KEEP = 40;

    #[Locked]
    public int $proposalId;

    public string $layout = 'chain';

    /** @var array<string, bool> */
    public array $layers = [];

    public string $density = 'comfortable';

    #[Url(as: 'problems', except: false)]
    public bool $problemsOnly = false;

    /**
     * The outcome tab in front; 0 picks the first one.
     */
    #[Url(as: 'outcome', except: 0)]
    public int $focus = 0;

    /** The output opened under the outcome in front, if any. */
    public ?int $openChild = null;

    /** Which list of the outcome in front is unfolded: 'ind', 'ra', 'all' (after Open all) or ''. */
    public string $drawer = '';

    public string $search = '';

    /** @var list<int> */
    public array $openIds = [];

    public bool $checksOpen = false;

    public bool $showDoneModal = false;

    public string $doneNote = '';

    public bool $showAssumptionModal = false;

    #[Locked]
    public ?int $assumptionId = null;

    public string $assumptionStatement = '';

    public string $assumptionStatus = 'holds';

    public string $assumptionResultId = '';

    public function mount(int $proposalId): void
    {
        $this->proposalId = $proposalId;
        $this->formProposalId = $proposalId;
        $this->applyPrefs();
    }

    protected function fixedProposalId(): ?int
    {
        return $this->proposalId;
    }

    protected function matchesFilters(Result $result, ?string $focus = null): bool
    {
        return true;
    }

    #[Computed]
    public function proposal(): Proposal
    {
        return Proposal::query()->select(['id', 'name', 'status', 'kind'])->findOrFail($this->proposalId);
    }

    /**
     * Whether the proposal's projects span programs, so its roots carry no
     * program ("Multi-program", D-24).
     */
    #[Computed]
    public function isMultiProgram(): bool
    {
        return count(ProposalFramework::programIds($this->proposal)) > 1;
    }

    /**
     * Whether this person may change the structure now.
     */
    #[Computed]
    public function canEdit(): bool
    {
        return auth()->user()?->can('results.update') === true
            || (auth()->user()?->can('results.create') === true && ! ProposalFramework::isStructureLocked($this->proposal));
    }

    /**
     * Whether this person may open an indicator's edit dialog; everyone else
     * reads the rows without a click that would end in a 403.
     */
    #[Computed]
    public function canEditIndicators(): bool
    {
        return auth()->user()?->can('indicators.update') === true;
    }

    /* ---------------- Layout and preferences ---------------- */

    public function setLayout(string $layout): void
    {
        $this->layout = in_array($layout, ['chain', 'matrix', 'board'], true) ? $layout : 'chain';
        $this->savePrefs();
    }

    public function toggleLayer(string $layer): void
    {
        if (in_array($layer, self::LAYERS, true)) {
            $this->layers[$layer] = ! ($this->layers[$layer] ?? false);
            $this->savePrefs();
        }
    }

    public function setDensity(string $density): void
    {
        $this->density = $density === 'compact' ? 'compact' : 'comfortable';
        $this->savePrefs();
    }

    public function resetPrefs(): void
    {
        $this->layout = 'chain';
        $this->layers = $this->defaultLayers();
        $this->density = 'comfortable';
        $this->savePrefs();
    }

    public function toggleOpen(int $resultId): void
    {
        $this->openIds = in_array($resultId, $this->openIds, true)
            ? array_values(array_diff($this->openIds, [$resultId]))
            : [...$this->openIds, $resultId];
        $this->saveOpen();
    }

    public function openAll(): void
    {
        $this->drawer = 'all';
        $this->openIds = array_keys($this->framework['results']);
        $this->saveOpen();
    }

    public function closeAll(): void
    {
        $this->drawer = '';
        $this->openChild = null;
        $this->openIds = [];
        $this->saveOpen();
    }

    /**
     * A "What's left?" check opens the place to fix it.
     */
    public function goCheck(string $key): void
    {
        $this->search = '';
        $this->problemsOnly = false;

        match ($key) {
            'ind' => [$this->layers['indicators'] = true, $this->openAll()],
            'tgt' => [$this->layers['indicators'] = true, $this->layers['targets'] = true, $this->openAll()],
            'act' => $this->setLayout('board'),
            'ra' => [$this->layers['risks'] = true, $this->layers['assumptions'] = true, $this->openAll()],
            default => null,
        };

        if ($key !== 'act') {
            $this->layout = $this->layout === 'board' ? 'chain' : $this->layout;
            $this->savePrefs();
        }

        $this->drawer = match ($key) {
            'ind', 'tgt' => 'ind',
            'ra' => 'ra',
            default => $this->drawer,
        };
    }

    /**
     * Bring an outcome tab to the front, folded.
     */
    public function focusOn(int $resultId): void
    {
        $this->focus = isset($this->framework['results'][$resultId]) ? $resultId : 0;
        $this->openChild = null;
        $this->drawer = '';
    }

    public function toggleChild(int $resultId): void
    {
        $this->openChild = $this->openChild === $resultId ? null : $resultId;
        $this->openIds = array_values(array_diff($this->openIds, [$resultId]));
    }

    public function toggleDrawer(string $drawer): void
    {
        $this->drawer = $this->drawer === $drawer || ! in_array($drawer, ['ind', 'ra'], true) ? '' : $drawer;
    }

    /**
     * The tabs' entries in tree order: an impact heads its outcomes; any
     * other root stands on its own.
     *
     * @return list<array{id: int, heading: bool}>
     */
    #[Computed]
    public function rail(): array
    {
        $framework = $this->framework;
        $rail = [];

        foreach ($framework['roots'] as $root) {
            if ($framework['results'][$root]['type'] === ResultType::Impact) {
                $rail[] = ['id' => $root, 'heading' => true];

                foreach ($framework['children'][$root] ?? [] as $child) {
                    $rail[] = ['id' => $child, 'heading' => false];
                }
            } else {
                $rail[] = ['id' => $root, 'heading' => false];
            }
        }

        return $rail;
    }

    /**
     * Per result: how many results in its branch (itself included) have a
     * problem, and how many indicators the branch carries.
     *
     * @return array<int, array{issues: int, indicators: int}>
     */
    #[Computed]
    public function subtreeTotals(): array
    {
        $framework = $this->framework;
        $totals = [];
        $walk = function (int $id) use (&$walk, &$totals, $framework): array {
            $sum = ['issues' => $framework['results'][$id]['issues'] === [] ? 0 : 1, 'indicators' => count($framework['results'][$id]['indicators'])];

            foreach ($framework['children'][$id] ?? [] as $child) {
                $below = $walk($child);
                $sum['issues'] += $below['issues'];
                $sum['indicators'] += $below['indicators'];
            }

            return $totals[$id] = $sum;
        };

        foreach ($framework['roots'] as $root) {
            $walk($root);
        }

        return $totals;
    }

    /**
     * The outcome tab in front: the one asked for while it is still shown,
     * otherwise the first shown; null when no tab is left.
     */
    #[Computed]
    public function focusedId(): ?int
    {
        $ids = array_column(array_filter($this->rail, fn (array $entry): bool => ! $entry['heading'] && in_array($entry['id'], $this->visibleIds, true)), 'id');

        return in_array($this->focus, $ids, true) ? $this->focus : ($ids[0] ?? null);
    }

    /**
     * A "Needs attention" fix button on the page (Sort them, Set targets,
     * Add an indicator) opens the same place as the matching check, then
     * scrolls to the box and flashes it.
     */
    #[On('framework-go')]
    public function goFromAttention(string $key): void
    {
        if (! in_array($key, ['ind', 'tgt', 'act', 'ra'], true)) {
            return;
        }

        $this->goCheck($key);
        $this->dispatch('pp-flash', target: 'lf');
    }

    /**
     * @return array<string, bool>
     */
    private function defaultLayers(): array
    {
        return ['indicators' => true, 'targets' => false, 'activities' => true, 'risks' => true, 'assumptions' => true];
    }

    private function applyPrefs(): void
    {
        $prefs = auth()->user()?->page_prefs ?? [];
        $mine = is_array($prefs[self::PREFS] ?? null) ? $prefs[self::PREFS] : [];

        $this->layout = in_array($mine['layout'] ?? null, ['chain', 'matrix', 'board'], true) ? $mine['layout'] : 'chain';
        $this->layers = array_merge($this->defaultLayers(), array_intersect_key(array_map('boolval', is_array($mine['layers'] ?? null) ? $mine['layers'] : []), array_flip(self::LAYERS)));
        $this->density = ($mine['density'] ?? null) === 'compact' ? 'compact' : 'comfortable';

        $open = $prefs[self::PREFS.'_open'][(string) $this->proposalId] ?? [];
        $this->openIds = array_values(array_map('intval', is_array($open) ? $open : []));
    }

    private function savePrefs(): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $prefs = $user->page_prefs ?? [];
        $prefs[self::PREFS] = ['layout' => $this->layout, 'layers' => $this->layers, 'density' => $this->density];
        $user->forceFill(['page_prefs' => $prefs])->saveQuietly();
    }

    private function saveOpen(): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $prefs = $user->page_prefs ?? [];
        $open = is_array($prefs[self::PREFS.'_open'] ?? null) ? $prefs[self::PREFS.'_open'] : [];
        unset($open[(string) $this->proposalId]);
        $open[(string) $this->proposalId] = array_values(array_unique(array_map('intval', $this->openIds)));
        $prefs[self::PREFS.'_open'] = array_slice($open, -self::OPEN_KEEP, null, true);
        $user->forceFill(['page_prefs' => $prefs])->saveQuietly();
    }

    /* ---------------- The framework task ---------------- */

    #[Computed]
    public function logframeTask(): ?Task
    {
        return $this->proposal->isPayroll() ? null : app(SyncLogframeTask::class)->taskOf($this->proposal);
    }

    /**
     * @return list<array{key: string, ok: bool, count: int, total: int}>
     */
    #[Computed]
    public function checks(): array
    {
        return FrameworkChecks::for($this->proposalId);
    }

    /**
     * Who holds the task and whether it is the viewer, and who closed it.
     *
     * @return array{mine: bool, assignee: string|null, doneBy: string|null, mayReopen: bool}
     */
    #[Computed]
    public function taskPeople(): array
    {
        $task = $this->logframeTask;
        $employeeId = $task?->taskAssignees()->value('employee_id');
        $me = auth()->user();

        return [
            'mine' => $employeeId !== null && $me?->employee_id !== null && (int) $me->employee_id === (int) $employeeId,
            'assignee' => $employeeId === null ? null : HrEmployee::query()->find((int) $employeeId, HrEmployee::NameColumns)?->name,
            'doneBy' => $task?->completed_by === null ? null : \App\Models\User::query()->find($task->completed_by)?->name,
            'mayReopen' => $me !== null && $task?->status === TaskStatus::Done && app(SyncLogframeTask::class)->mayReopen($me, $this->proposal),
        ];
    }

    public function openDoneModal(): void
    {
        Gate::authorize('results.create');
        $this->resetValidation();
        $this->doneNote = '';
        $this->showDoneModal = true;
    }

    public function markDone(): void
    {
        try {
            app(SyncLogframeTask::class)->confirm($this->proposal, $this->doneNote);
        } catch (ValidationException $exception) {
            $this->addError('doneNote', collect($exception->errors())->flatten()->first());

            return;
        }

        $this->showDoneModal = false;
        $this->checksOpen = false;
        $this->forgetBox();
        $this->dispatch('proposal-framework-changed');
        Flux::toast(variant: 'success', text: __('proposals.framework.done_toast'));
    }

    public function reopenTask(): void
    {
        try {
            app(SyncLogframeTask::class)->reopen($this->proposal);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        }

        $this->forgetBox();
        $this->dispatch('proposal-framework-changed');
        Flux::toast(variant: 'success', text: __('proposals.framework.reopened_toast'));
    }

    /* ---------------- Assumptions ---------------- */

    public function openAssumption(?int $assumptionId = null, ?int $resultId = null): void
    {
        Gate::authorize($assumptionId === null ? 'results.create' : 'results.update');
        $this->resetValidation();

        $assumption = $assumptionId === null ? null : Assumption::query()->where('proposal_id', $this->proposalId)->findOrFail($assumptionId);

        $this->assumptionId = $assumption?->id;
        $this->assumptionStatement = $assumption?->statement ?? '';
        $this->assumptionStatus = $assumption?->status->value ?? AssumptionStatus::Holds->value;
        $this->assumptionResultId = (string) ($assumption?->result_id ?? $resultId ?? '');
        $this->showAssumptionModal = true;
    }

    public function saveAssumption(): void
    {
        $existing = $this->assumptionId === null ? null : Assumption::query()->where('proposal_id', $this->proposalId)->findOrFail($this->assumptionId);

        try {
            app(SaveAssumption::class)->handle($this->proposal, $existing, [
                'statement' => $this->assumptionStatement,
                'status' => $this->assumptionStatus,
                'result_id' => $this->assumptionResultId,
            ]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => [
                ['statement' => 'assumptionStatement', 'status' => 'assumptionStatus', 'result_id' => 'assumptionResultId'][$field] ?? 'assumptionStatement' => $messages,
            ])->all());
        }

        $this->showAssumptionModal = false;
        $this->forgetBox();
        Flux::toast(variant: 'success', text: $existing === null ? __('proposals.framework.assumption_added') : __('proposals.framework.saved'));
    }

    public function setAssumptionStatus(int $assumptionId, string $status): void
    {
        $assumption = Assumption::query()->where('proposal_id', $this->proposalId)->findOrFail($assumptionId);

        app(SaveAssumption::class)->handle($this->proposal, $assumption, [
            'statement' => $assumption->statement,
            'status' => $status,
            'result_id' => $assumption->result_id,
        ]);

        $this->forgetBox();
    }

    public function deleteAssumption(): void
    {
        $assumption = Assumption::query()->where('proposal_id', $this->proposalId)->findOrFail((int) $this->assumptionId);

        app(SaveAssumption::class)->delete($this->proposal, $assumption);

        $this->showAssumptionModal = false;
        $this->forgetBox();
        Flux::toast(variant: 'success', text: __('proposals.framework.deleted'));
    }

    /* ---------------- Reading for the view ---------------- */

    /**
     * Ids of the results the chain shows: matching the search and, when
     * asked, only those with problems; a parent stays to keep its child in place.
     *
     * @return list<int>
     */
    #[Computed]
    public function visibleIds(): array
    {
        $framework = $this->framework;
        $query = mb_strtolower(trim($this->search));
        $matches = function (array $result) use ($query, $framework): bool {
            if ($this->problemsOnly && $result['issues'] === []) {
                return false;
            }

            if ($query === '') {
                return true;
            }

            $text = $result['statement'].' '.implode(' ', array_column($result['indicators'], 'name')).' '.implode(' ', array_column($result['assumptions'], 'statement'))
                .' '.implode(' ', array_map(fn (int $id): string => $framework['activities'][$id]['name'] ?? '', $result['activities']));

            return str_contains(mb_strtolower($text), $query);
        };

        $visible = [];
        $walk = function (int $id) use (&$walk, &$visible, $framework, $matches): bool {
            $any = false;

            foreach ($framework['children'][$id] ?? [] as $child) {
                $any = $walk($child) || $any;
            }

            if ($matches($framework['results'][$id]) || $any) {
                $visible[] = $id;

                return true;
            }

            return false;
        };

        foreach ($framework['roots'] as $root) {
            $walk($root);
        }

        return $visible;
    }

    /**
     * Drop every memoised reading after a write.
     */
    private function forgetBox(): void
    {
        unset($this->allResults, $this->framework, $this->visibleIds, $this->rail, $this->subtreeTotals, $this->focusedId, $this->checks, $this->logframeTask, $this->taskPeople, $this->proposalRisks);
    }

    /** @return Builder<Indicator> */
    protected function readingIndicators(): Builder
    {
        return Indicator::query()->whereIn('result_id', $this->frameworkResults()->select('id'));
    }

    protected function authorizeReadingPeriod(Indicator $indicator, CarbonImmutable $date): void
    {
        if (app(MonthlyReportLineBuilder::class)->canCarryMeasurement($indicator, $date)) {
            throw ValidationException::withMessages(['progressDate' => __('indicators.measurement_belongs_in_monthly_report')]);
        }
    }

    protected function forgetIndicators(): void
    {
        $this->forgetFramework();
    }

    #[On('framework-target-saved')]
    public function refreshFrameworkTargets(): void
    {
        $this->forgetFramework();
    }

    protected function forgetFramework(): void
    {
        foreach (['allResults', 'resultsById', 'childCounts', 'measurements', 'groupedRoots', 'parentOptions', 'contributesToOptions'] as $property) {
            unset($this->{$property});
        }

        $this->forgetBox();
    }

    /**
     * A risk write changes the layer counts and warnings too.
     */
    protected function forgetRisks(): void
    {
        $this->forgetBox();
    }
};
?>

@include('components.proposals._framework-box')
