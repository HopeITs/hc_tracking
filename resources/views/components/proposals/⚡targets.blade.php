<?php

use App\Actions\Targets\AttachLibraryIndicatorToResult;
use App\Actions\Targets\DetachIndicatorFromResult;
use App\Actions\Targets\SaveProposalTargetsGrid;
use App\Enums\DonorAttribution;
use App\Enums\IndicatorMeasure;
use App\Livewire\Concerns\IndicatorLibrary\ManagesLibraryEntryForm;
use App\Models\HrCenter;
use App\Models\HrCity;
use App\Models\HrProgram;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Support\ArabicText;
use App\Support\Like;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The Indicators & Targets tab of proposals/show (T13, contracts §12.1).
 *
 * Two parts: the checklist (library entries of the proposal's programs plus
 * institutional ones, attached to nodes of the proposal's own framework) and
 * the per-center targets grid (rows = instances in the framework, columns =
 * linked projects, labelled center · city). Every write goes through the
 * App\Actions\Targets actions, which authorize, lock the proposal and refuse
 * foreign ids; the flags kept here only shape the page.
 *
 * The grid is held in $grid and sent only on Save, as one batch, with the
 * version each saved cell was loaded at. Reads stay in a fixed number of
 * queries however many cells there are. No money is read.
 */
new class extends Component {
    use ManagesLibraryEntryForm;

    #[Locked]
    public int $proposalId;

    /**
     * The grid as edited, keyed "{indicatorId}_{projectId}".
     *
     * @var array<string, array{value: string, period: string, population: string}>
     */
    public array $grid = [];

    /**
     * The version (SaveProposalTargetsGrid::versionOf()) each saved cell was
     * loaded at; absent for a cell that was empty when loaded.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $versions = [];

    /** The last save was refused because someone else changed a cell. */
    #[Locked]
    public bool $isStale = false;

    public string $pickerSearch = '';

    #[Locked]
    public ?int $detachingIndicatorId = null;

    public bool $showDetachModal = false;

    public function mount(int $proposalId): void
    {
        $this->proposalId = $proposalId;
        $this->fillGrid(keepEdits: false);
    }

    #[Computed]
    public function proposal(): Proposal
    {
        return Proposal::query()
            ->select(['id', 'name', 'slug', 'status', 'previous_proposal_id'])
            ->with(['projects' => fn ($query) => $query->select(['projects.id', 'projects.name', 'projects.program_id', 'projects.center_id', 'projects.city_id'])])
            ->findOrFail($this->proposalId);
    }

    #[Computed]
    public function isReadOnlyProposal(): bool
    {
        return $this->proposal->status->isTerminal();
    }

    /** MEAL enters targets (D-23); `proposals.update` alone grants nothing. */
    #[Computed]
    public function canEditGrid(): bool
    {
        return ! $this->isReadOnlyProposal && Gate::allows('targets.update');
    }

    #[Computed]
    public function canAttach(): bool
    {
        return ! $this->isReadOnlyProposal && Gate::allows('indicators.create');
    }

    #[Computed]
    public function canDetach(): bool
    {
        return ! $this->isReadOnlyProposal && Gate::allows('indicators.delete');
    }

    /**
     * Distinct programs of the linked projects, read off the loaded projects.
     *
     * @return list<int>
     */
    #[Computed]
    public function programIds(): array
    {
        return $this->proposal->projects->pluck('program_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all();
    }

    /**
     * The proposal's own framework, top level first.
     *
     * @return Collection<int, Result>
     */
    #[Computed]
    public function results(): Collection
    {
        return Result::query()
            ->proposalFramework($this->proposalId)
            ->select(['id', 'statement', 'type', 'parent_id', 'program_id', 'proposal_id'])
            ->orderBy('id')
            ->get()
            ->sortBy(fn (Result $result): array => [$result->type->rank(), $result->id])
            ->values();
    }

    /**
     * Indicator instances under the framework, in framework order.
     *
     * @return Collection<int, Indicator>
     */
    #[Computed]
    public function instances(): Collection
    {
        $order = $this->results->pluck('id')->flip();

        return Indicator::query()
            ->select(['id', 'name', 'code', 'result_id', 'library_indicator_id', 'measure', 'unit', 'frequency'])
            ->whereIn('result_id', Result::query()->proposalFramework($this->proposalId)->select('id'))
            ->with('libraryIndicator:id,code,name_ar,name_en,unit,measure')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (Indicator $indicator): array => [$order[$indicator->result_id] ?? PHP_INT_MAX, $indicator->id])
            ->values();
    }

    /**
     * Saved targets of this proposal, keyed like the grid.
     *
     * @return Collection<string, PlanIndicator>
     */
    #[Computed]
    public function savedTargets(): Collection
    {
        return PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'project_id', 'target', 'target_population', 'period', 'updated_at'])
            ->where('source_type', SaveProposalTargetsGrid::SourceType)
            ->where('source_id', $this->proposalId)
            ->withCount('reports')
            ->with([
                'indicator:id,name,result_id,project_id,measure,unit,library_indicator_id',
                'indicator.result:id,statement,type,program_id,proposal_id',
                'project:id,name,program_id',
            ])
            ->get()
            ->keyBy(fn (PlanIndicator $target): string => $target->indicator_id.'_'.$target->project_id);
    }

    /**
     * Saved targets the grid cannot show: committed on an indicator outside
     * this proposal's framework (legacy data) or on a project the proposal no
     * longer links. They stay, read-only, flagged with their chain issues.
     *
     * @return Collection<int, PlanIndicator>
     */
    #[Computed]
    public function outsideTargets(): Collection
    {
        $instanceIds = $this->instances->modelKeys();
        $projectIds = $this->proposal->projects->modelKeys();
        $source = $this->proposal;

        return $this->savedTargets
            ->filter(fn (PlanIndicator $target): bool => ! in_array($target->indicator_id, $instanceIds, true)
                || ! in_array($target->project_id, $projectIds, true))
            ->each(fn (PlanIndicator $target) => $target->setRelation('source', $source))
            ->values();
    }

    /**
     * Grid columns: one per linked project, labelled center · city, falling
     * back to the project name when the project has no center (§10).
     *
     * @return list<array{id: int, label: string, city: ?string, beneficiaries: ?int, programId: ?int}>
     */
    #[Computed]
    public function columns(): array
    {
        $projects = $this->proposal->projects;
        $centers = $projects->mapWithKeys(fn (Project $project): array => [$project->id => $project->center()]);
        $cityIds = $centers->filter()->map(fn (HrCenter $center): int => $center->city_id)
            ->merge($projects->pluck('city_id'))
            ->filter()->unique()->values()->all();
        $cities = HrCity::query()->select(['id', 'name_en', 'name_ar'])->whereIn('id', $cityIds)->get()->keyBy('id');

        return $projects
            ->map(function (Project $project) use ($centers, $cities): array {
                $center = $centers->get($project->id);
                $cityId = $center?->city_id ?? $project->city_id;

                return [
                    'id' => $project->id,
                    'label' => $center?->name ?? $project->name,
                    'city' => $center === null ? null : $cities->get($cityId)?->name,
                    'beneficiaries' => ($beneficiaries = $project->pivot?->getAttribute('target_beneficiaries')) === null ? null : (int) $beneficiaries,
                    'programId' => $project->program_id === null ? null : (int) $project->program_id,
                ];
            })
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Last year's achievement per (library entry, project) on a renewal
     * (D-11): suggestions only, never saved until MEAL accepts one.
     *
     * @return array<string, float> "{libraryIndicatorId}_{projectId}" => achieved
     */
    #[Computed]
    public function previousAchievements(): array
    {
        $previousId = $this->proposal->previous_proposal_id;

        return PlanIndicator::query()
            ->select(['id', 'indicator_id', 'project_id', 'target'])
            ->where('source_type', SaveProposalTargetsGrid::SourceType)
            ->where('source_id', $previousId ?? 0)
            ->whereIn('project_id', $this->proposal->projects->modelKeys())
            ->with(['indicator:id,library_indicator_id,measure', 'reports:id,plan_indicator_id,status,value,planned_date'])
            ->get()
            ->filter(fn (PlanIndicator $target): bool => $target->indicator?->library_indicator_id !== null)
            ->mapWithKeys(fn (PlanIndicator $target): array => [$target->indicator->library_indicator_id.'_'.$target->project_id => $target->achievedValue()])
            ->all();
    }

    /**
     * Other donors' targets on the same center and library entry, for the
     * center total (D-46: shared → highest, split → sum). Display only.
     *
     * @return array<string, list<float>> "{libraryIndicatorId}_{projectId}" => targets
     */
    #[Computed]
    public function otherDonorTargets(): array
    {
        $libraryIds = $this->instances->pluck('library_indicator_id')->filter()->unique()->values()->all();

        return PlanIndicator::query()
            ->toBase()
            ->join('indicators', 'indicators.id', '=', 'plan_indicators.indicator_id')
            ->where('plan_indicators.source_type', SaveProposalTargetsGrid::SourceType)
            ->where('plan_indicators.source_id', '!=', $this->proposalId)
            ->whereIn('plan_indicators.project_id', $this->proposal->projects->modelKeys())
            ->whereIn('indicators.library_indicator_id', $libraryIds)
            ->get(['plan_indicators.project_id', 'plan_indicators.target', 'indicators.library_indicator_id'])
            ->groupBy(fn (object $row): string => $row->library_indicator_id.'_'.$row->project_id)
            ->map(fn ($rows): array => $rows->map(fn (object $row): float => (float) $row->target)->values()->all())
            ->all();
    }

    /**
     * How each program combines donors on one indicator (D-46).
     *
     * @return array<int, DonorAttribution>
     */
    #[Computed]
    public function attributions(): array
    {
        return Program::query()
            ->select(['id', 'donor_attribution'])
            ->whereIn('id', $this->programIds)
            ->get()
            ->mapWithKeys(fn (Program $program): array => [$program->id => $program->donor_attribution])
            ->all();
    }

    /**
     * The grid's rows and per-cell display facts, all from loaded data.
     *
     * @return list<array{result: Result, indicators: list<array{indicator: Indicator, linked: bool, isPercentage: bool, cells: array<int, array<string, mixed>>}>}>
     */
    #[Computed]
    public function gridRows(): array
    {
        $instancesByResult = $this->instances->groupBy('result_id');
        $rows = [];

        foreach ($this->results as $result) {
            $indicators = $instancesByResult->get($result->id);

            if ($indicators === null) {
                continue;
            }

            $rows[] = [
                'result' => $result,
                'indicators' => $indicators->map(fn (Indicator $indicator): array => [
                    'indicator' => $indicator,
                    'linked' => $indicator->library_indicator_id !== null,
                    'isPercentage' => $indicator->measure === IndicatorMeasure::Percentage,
                    'cells' => collect($this->columns)->mapWithKeys(fn (array $column): array => [
                        $column['id'] => $this->cellFacts($indicator, $column),
                    ])->all(),
                ])->values()->all(),
            ];
        }

        return $rows;
    }

    /**
     * Library entries offered in the checklist (D-10a): active, of the
     * proposal's programs or institutional, grouped by program then level,
     * the most used first.
     *
     * @return list<array{key: string, programId: ?int, label: string, levels: list<array{kind: string, entries: list<LibraryIndicator>}>}>
     */
    #[Computed]
    public function pickerGroups(): array
    {
        if (! $this->canAttach) {
            return [];
        }

        $programIds = $this->programIds;
        $entries = LibraryIndicator::query()
            ->active()
            ->where(fn ($query) => $query->whereIn('program_id', $programIds)->orWhereNull('program_id'))
            ->select(['id', 'code', 'program_id', 'level_ref', 'serial', 'name_ar', 'name_en', 'measure', 'unit', 'collected_by', 'frequency', 'suggested_level'])
            ->get();

        $usage = PlanIndicator::query()
            ->toBase()
            ->join('indicators', 'indicators.id', '=', 'plan_indicators.indicator_id')
            ->join('projects', 'projects.id', '=', 'plan_indicators.project_id')
            ->whereIn('indicators.library_indicator_id', $entries->modelKeys())
            ->groupBy('indicators.library_indicator_id', 'projects.program_id')
            ->selectRaw('indicators.library_indicator_id as entry_id, projects.program_id as program_id, count(*) as uses')
            ->get();

        $usageOf = fn (LibraryIndicator $entry): int => (int) $usage
            ->filter(fn (object $row): bool => (int) $row->entry_id === $entry->id
                && ($entry->program_id === null ? in_array((int) $row->program_id, $programIds, true) : (int) $row->program_id === $entry->program_id))
            ->sum('uses');

        $term = trim(Like::term($this->pickerSearch));
        $entries = $entries
            ->filter(fn (LibraryIndicator $entry): bool => $term === ''
                || ArabicText::contains($entry->name_ar, $term)
                || ArabicText::contains((string) $entry->name_en, $term)
                || ArabicText::contains($entry->code, $term))
            ->each(fn (LibraryIndicator $entry) => $entry->setAttribute('usage', $usageOf($entry)));

        $groups = [];

        foreach ([...$programIds, null] as $programId) {
            $inGroup = $entries->filter(fn (LibraryIndicator $entry): bool => $entry->program_id === $programId);

            $groups[] = [
                'key' => $programId === null ? 'institutional' : (string) $programId,
                'programId' => $programId,
                'label' => $programId === null ? __('targets.institutional_indicators') : HrProgram::nameOf($programId),
                'levels' => collect(['G', 'CC', 'OC', 'OP', 'AC'])
                    ->map(fn (string $kind): array => [
                        'kind' => $kind,
                        'entries' => $inGroup
                            ->filter(fn (LibraryIndicator $entry): bool => LibraryIndicator::levelKindOf($entry->level_ref) === $kind)
                            ->sortBy([['usage', 'desc'], ['code', 'asc']])
                            ->values()
                            ->all(),
                    ])
                    ->filter(fn (array $level): bool => $level['entries'] !== [])
                    ->values()
                    ->all(),
            ];
        }

        return $groups;
    }

    /**
     * Framework nodes an entry may be attached to: its suggested level (or
     * any when it has none), minus nodes already holding it.
     *
     * @return list<Result>
     */
    public function compatibleNodes(LibraryIndicator $entry): array
    {
        $attachedNames = $this->instances->groupBy('result_id')->map(fn ($instances) => $instances->pluck('name')->all());

        return $this->results
            ->filter(fn (Result $result): bool => ($entry->suggested_level === null || $entry->suggested_level === $result->type)
                && ! in_array($entry->name_ar, $attachedNames->get($result->id, []), true))
            ->values()
            ->all();
    }

    public function attach(int $libraryIndicatorId, int $resultId): void
    {
        Gate::authorize('indicators.create');

        $indicator = app(AttachLibraryIndicatorToResult::class)->handle($this->proposal, $libraryIndicatorId, $resultId);

        $this->forgetTargets();
        $this->fillGrid(keepEdits: true);

        Flux::toast(variant: 'success', text: __('targets.attached', ['code' => $indicator->code]));
    }

    public function confirmDetach(int $indicatorId): void
    {
        Gate::authorize('indicators.delete');

        $this->resetValidation('indicator');
        $this->detachingIndicatorId = $this->frameworkIndicator($indicatorId)->id;
        $this->showDetachModal = true;
    }

    public function detach(): void
    {
        Gate::authorize('indicators.delete');

        if ($this->detachingIndicatorId === null) {
            return;
        }

        app(DetachIndicatorFromResult::class)->handle($this->frameworkIndicator($this->detachingIndicatorId));

        $this->cancelDetach();
        $this->forgetTargets();
        $this->fillGrid(keepEdits: true);

        Flux::toast(variant: 'success', text: __('targets.detached'));
    }

    public function cancelDetach(): void
    {
        $this->showDetachModal = false;
        $this->detachingIndicatorId = null;
    }

    /**
     * Fill an empty cell with its suggestion; nothing is saved until Save.
     */
    public function acceptSuggestion(string $key): void
    {
        Gate::authorize('targets.create');

        [$indicatorId, $projectId] = array_map('intval', explode('_', $key) + [1 => 0]);
        $indicator = $this->instances->firstWhere('id', $indicatorId);
        $suggestion = $indicator === null ? null : ($this->previousAchievements[$indicator->library_indicator_id.'_'.$projectId] ?? null);

        if ($suggestion === null || ! array_key_exists($key, $this->grid)) {
            return;
        }

        $this->grid[$key]['value'] = self::formatNumber($suggestion);
    }

    /**
     * Send the whole grid as one batch (contracts §12.1). The action refuses
     * the batch when any cell fails; each refusal is shown on its cell.
     */
    public function saveGrid(): void
    {
        Gate::authorize('targets.update');

        $this->resetValidation();
        $this->isStale = false;

        [$cells, $keys] = $this->cellsToSend();

        try {
            $counts = app(SaveProposalTargetsGrid::class)->handle($this->proposal, $cells);
        } catch (ValidationException $exception) {
            $this->isStale = collect($exception->errors())->flatten()->contains(__('targets.cell_stale'));

            throw ValidationException::withMessages(
                collect(['cells' => [__('targets.grid_refused')], ...$exception->errors()])->mapWithKeys(function (array $messages, string $key) use ($keys): array {
                    if (preg_match('/^cells\.(\d+)(?:\.(value|period|target_population))?$/', $key, $match) === 1) {
                        $field = match ($match[2] ?? '') {
                            'value' => '.value',
                            'period' => '.period',
                            'target_population' => '.population',
                            '' => '',
                        };

                        return ['grid.'.($keys[(int) $match[1]] ?? $match[1]).$field => $messages];
                    }

                    return [$key => $messages];
                })->all(),
            );
        }

        $this->forgetTargets();
        $this->fillGrid(keepEdits: false);

        Flux::toast(variant: 'success', text: array_sum($counts) === 0 ? __('targets.grid_unchanged') : __('targets.grid_saved', $counts));
    }

    /**
     * Throw away edits and load the grid as it is stored now.
     */
    public function reloadGrid(): void
    {
        $this->resetValidation();
        $this->isStale = false;
        $this->forgetTargets();
        $this->fillGrid(keepEdits: false);
    }

    /**
     * "Add to Program Library": the library's own entry dialog, with the
     * program already chosen.
     */
    public function openLibraryEntryFor(string $groupKey): void
    {
        $this->openCreateModal();

        if ($groupKey === 'institutional') {
            $this->entry['institutional'] = true;

            return;
        }

        if (in_array((int) $groupKey, $this->programIds, true)) {
            $this->entry['program_id'] = $groupKey;
        }
    }

    /**
     * The checklist's library entry dialog saved an entry.
     */
    protected function forgetLibrary(): void
    {
        unset($this->pickerGroups);
    }

    /**
     * Drop every memoised reading after a write.
     */
    private function forgetTargets(): void
    {
        unset(
            $this->proposal, $this->isReadOnlyProposal, $this->canEditGrid, $this->canAttach, $this->canDetach, $this->programIds,
            $this->results, $this->instances, $this->savedTargets, $this->columns, $this->previousAchievements,
            $this->otherDonorTargets, $this->attributions, $this->gridRows, $this->pickerGroups, $this->outsideTargets,
        );
    }

    /**
     * Load $grid and $versions from the stored targets. With $keepEdits,
     * cells already in the grid keep what was typed (after an attach).
     */
    private function fillGrid(bool $keepEdits): void
    {
        $grid = [];
        $versions = [];

        foreach ($this->instances as $indicator) {
            foreach ($this->columns as $column) {
                $key = $indicator->id.'_'.$column['id'];
                $saved = $this->savedTargets->get($key);

                if ($saved !== null) {
                    $versions[$key] = SaveProposalTargetsGrid::versionOf($saved);
                }

                $grid[$key] = $keepEdits && isset($this->grid[$key]) ? $this->grid[$key] : [
                    'value' => $saved === null ? '' : self::formatNumber((float) $saved->target),
                    'period' => $saved?->period?->value ?? $indicator->frequency?->value ?? '',
                    'population' => $saved?->target_population === null ? '' : self::formatNumber((float) $saved->target_population),
                ];
            }
        }

        $this->grid = $grid;
        $this->versions = $versions;
    }

    /**
     * The cells sent to the action: every filled cell and every saved one,
     * with the version it was loaded at, and their grid keys by position.
     *
     * @return array{0: list<array{indicator_id: int, project_id: int, value: ?string, period: ?string, target_population: ?string, version: ?string}>, 1: list<string>}
     */
    private function cellsToSend(): array
    {
        $cells = [];
        $keys = [];

        foreach ($this->grid as $key => $cell) {
            [$indicatorId, $projectId] = array_map('intval', explode('_', (string) $key) + [1 => 0]);
            $value = trim((string) ($cell['value'] ?? ''));
            $version = $this->versions[$key] ?? null;

            if ($value === '' && $version === null) {
                continue;
            }

            $population = trim((string) ($cell['population'] ?? ''));

            $cells[] = [
                'indicator_id' => $indicatorId,
                'project_id' => $projectId,
                'value' => $value === '' ? null : $value,
                'period' => $value === '' ? null : (string) ($cell['period'] ?? ''),
                'target_population' => $population === '' ? null : $population,
                'version' => $version,
            ];
            $keys[] = (string) $key;
        }

        return [$cells, $keys];
    }

    /**
     * What one cell shows besides its input.
     *
     * @param  array{id: int, label: string, city: ?string, beneficiaries: ?int, programId: ?int}  $column
     * @return array{key: string, saved: bool, reported: bool, beneficiaries: ?int, derived: ?string, suggestion: ?string, centerTotal: ?string, attribution: ?DonorAttribution}
     */
    private function cellFacts(Indicator $indicator, array $column): array
    {
        $key = $indicator->id.'_'.$column['id'];
        $saved = $this->savedTargets->get($key);
        $cell = $this->grid[$key] ?? ['value' => '', 'period' => '', 'population' => ''];
        $value = trim((string) $cell['value']);
        $isPercentage = $indicator->measure === IndicatorMeasure::Percentage;
        $population = trim((string) $cell['population']) !== '' ? (float) $cell['population'] : $column['beneficiaries'];

        $derived = null;

        if ($isPercentage && $value !== '' && is_numeric($value) && $population !== null) {
            $derived = __('targets.percentage_of_population', [
                'value' => self::formatNumber((float) $value),
                'population' => self::formatNumber((float) $population),
                'result' => self::formatNumber(round((float) $value * (float) $population / 100, 2)),
            ]);
        }

        $libraryKey = $indicator->library_indicator_id.'_'.$column['id'];
        $suggestion = $value === '' && $indicator->library_indicator_id !== null ? ($this->previousAchievements[$libraryKey] ?? null) : null;

        $others = $indicator->library_indicator_id === null ? [] : ($this->otherDonorTargets[$libraryKey] ?? []);
        $attribution = $column['programId'] === null ? null : ($this->attributions[$column['programId']] ?? null);
        $centerTotal = null;

        if ($others !== [] && $attribution !== null) {
            $centerTotal = self::formatNumber($attribution->combine([...$others, ...($value !== '' && is_numeric($value) ? [(float) $value] : [])]));
        }

        return [
            'key' => $key,
            'saved' => $saved !== null,
            'reported' => ($saved?->reports_count ?? 0) > 0,
            'beneficiaries' => $column['beneficiaries'],
            'derived' => $derived,
            'suggestion' => $suggestion === null ? null : self::formatNumber($suggestion),
            'centerTotal' => $centerTotal,
            'attribution' => $centerTotal === null ? null : $attribution,
        ];
    }

    /**
     * An instance of this proposal's framework, never another one (BOLA).
     */
    private function frameworkIndicator(int $indicatorId): Indicator
    {
        return Indicator::query()
            ->whereIn('result_id', Result::query()->proposalFramework($this->proposalId)->select('id'))
            ->whereKey($indicatorId)
            ->firstOrFail();
    }

    /**
     * A number without trailing zeros: 120.00 → 120, 12.50 → 12.5.
     */
    private static function formatNumber(float $number): string
    {
        $formatted = number_format($number, 2, '.', '');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
};
?>

@php
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';
    $instancesByResult = $this->instances->groupBy('result_id');
    $columns = $this->columns;
@endphp

<div class="flex flex-col gap-6" data-tour="proposal-targets">
    @if ($this->isReadOnlyProposal)
        <p class="flex items-center gap-2 rounded-lg border {{ $hairline }} bg-board px-3 py-2 text-sm {{ $mutedText }} dark:bg-surface-1" data-targets-read-only>
            <flux:icon.lock-closed variant="micro" class="size-4 shrink-0" aria-hidden="true" />
            <span>{{ __('targets.proposal_read_only') }}</span>
        </p>
    @endif

    {{-- Checklist: the framework's nodes and the indicators attached to each. --}}
    <x-ui.panel :title="__('targets.checklist_heading')" :subtitle="__('targets.checklist_subtitle')" :count="$this->instances->count()" data-tour="proposal-targets-checklist">
        @if ($this->results->isEmpty())
            <x-ui.empty-state icon="squares-2x2" :heading="__('targets.no_framework_heading')" :body="__('targets.no_framework_body')">
                <x-slot:action>
                    <flux:button size="sm" variant="ghost" :href="route('proposals.show', ['proposal' => $this->proposal, 'tab' => 'logframe'])" wire:navigate>{{ __('targets.open_logframe') }}</flux:button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <div class="grid gap-0 @4xl/detail:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
                <ol class="divide-y divide-secondary-soft dark:divide-line">
                    @foreach ($this->results as $result)
                        <li class="px-4 py-3" wire:key="node-{{ $result->id }}" data-node="{{ $result->id }}">
                            <div class="flex items-start gap-2">
                                <x-ui.enum-badge :case="$result->type" />
                                <p class="min-w-0 flex-1 text-sm font-semibold {{ $inkText }}" dir="auto">{{ $result->statement }}</p>
                            </div>

                            @php
                                $nodeInstances = $instancesByResult->get($result->id, collect());
                            @endphp
                            @if ($nodeInstances->isEmpty())
                                <p class="mt-1.5 ps-1 text-xs {{ $mutedText }}">{{ __('targets.node_has_no_indicators') }}</p>
                            @else
                                <ul class="mt-2 flex flex-col gap-1.5">
                                    @foreach ($nodeInstances as $instance)
                                        <li class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm" wire:key="instance-{{ $instance->id }}" data-instance="{{ $instance->id }}">
                                            @if ($instance->code !== null)
                                                <bdi dir="ltr" class="font-mono text-xs {{ $mutedText }}">{{ $instance->code }}</bdi>
                                            @endif
                                            <span class="min-w-0 {{ $inkText }}" dir="auto">{{ $instance->libraryIndicator?->localizedName() ?? $instance->name }}</span>
                                            @if ($instance->library_indicator_id === null)
                                                <span class="inline-flex items-center gap-1 rounded-full border border-status-critical/40 px-2 py-0.5 text-[11px] font-semibold text-status-critical" data-badge="not-linked">
                                                    <flux:icon.link-slash variant="micro" class="size-3" aria-hidden="true" />
                                                    {{ __('targets.not_linked_to_library') }}
                                                </span>
                                                @can('indicator-library.update')
                                                    <a href="{{ route('indicator-library.index') }}" wire:navigate class="text-xs font-semibold text-primary underline-offset-2 hover:underline">{{ __('targets.fix_library_link') }}</a>
                                                @endcan
                                            @endif
                                            @if ($this->canDetach)
                                                <flux:button size="xs" variant="ghost" icon="x-mark" class="ms-auto" wire:click="confirmDetach({{ $instance->id }})" :aria-label="__('targets.detach_indicator', ['name' => $instance->name])" />
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ol>

                @if ($this->canAttach)
                    <aside class="border-t {{ $hairline }} @4xl/detail:border-s @4xl/detail:border-t-0" aria-label="{{ __('targets.library_picker') }}" data-tour="proposal-targets-picker">
                        <div class="border-b {{ $hairline }} p-3">
                            <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.300ms="pickerSearch" :placeholder="__('targets.search_library')" :aria-label="__('targets.search_library')" clearable />
                            @error('library_indicator')
                                <p class="mt-2 flex items-center gap-1.5 text-xs font-semibold text-status-critical" role="alert"><flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0" />{{ $message }}</p>
                            @enderror
                            @error('result')
                                <p class="mt-2 flex items-center gap-1.5 text-xs font-semibold text-status-critical" role="alert"><flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0" />{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="max-h-[32rem] overflow-y-auto">
                            @foreach ($this->pickerGroups as $group)
                                <section wire:key="picker-{{ $group['key'] }}" data-picker-group="{{ $group['key'] }}">
                                    <header class="sticky top-0 z-[1] flex items-center justify-between gap-2 border-b {{ $hairline }} bg-board px-3 py-2 dark:bg-surface-2">
                                        <p class="truncate text-xs font-bold uppercase tracking-wide {{ $inkText }}" dir="auto">{{ $group['label'] }}</p>
                                        @can('indicator-library.create')
                                            <flux:button size="xs" variant="ghost" icon="plus" wire:click="openLibraryEntryFor('{{ $group['key'] }}')">{{ __('targets.add_to_library') }}</flux:button>
                                        @endcan
                                    </header>

                                    @forelse ($group['levels'] as $level)
                                        <p class="px-3 pt-2 text-[11px] font-semibold {{ $mutedText }}">{{ __('indicator_library.level_kinds.'.$level['kind']) }}</p>
                                        <ul class="divide-y divide-secondary-soft dark:divide-line">
                                            @foreach ($level['entries'] as $entry)
                                                @php
                                                    $nodes = $this->compatibleNodes($entry);
                                                @endphp
                                                <li class="flex items-start gap-2 px-3 py-2" wire:key="entry-{{ $entry->id }}" data-entry="{{ $entry->id }}">
                                                    <div class="min-w-0 flex-1">
                                                        <p class="text-sm {{ $inkText }}" dir="auto">{{ $entry->localizedName() }}</p>
                                                        <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[11px] {{ $mutedText }}">
                                                            <bdi dir="ltr" class="font-mono">{{ $entry->code }}</bdi>
                                                            <span aria-hidden="true">·</span>
                                                            <span>{{ $entry->measure->label() }}@if ($entry->unit !== null) ({{ $entry->unit }})@endif</span>
                                                            <span aria-hidden="true">·</span>
                                                            <span>{{ $entry->frequency->label() }}</span>
                                                            <span aria-hidden="true">·</span>
                                                            <span>{{ $entry->collected_by->label() }}</span>
                                                            @if ($entry->usage > 0)
                                                                <span aria-hidden="true">·</span>
                                                                <span>{{ trans_choice('targets.used_times', $entry->usage, ['count' => $entry->usage]) }}</span>
                                                            @endif
                                                        </p>
                                                    </div>

                                                    @if ($nodes === [])
                                                        <span class="shrink-0 text-[11px] {{ $mutedText }}">{{ __('targets.no_compatible_node') }}</span>
                                                    @else
                                                        <flux:dropdown position="bottom" align="end">
                                                            <flux:button size="xs" icon="plus" :aria-label="__('targets.attach_entry', ['code' => $entry->code])">{{ __('targets.attach') }}</flux:button>
                                                            <flux:menu class="max-w-80">
                                                                <flux:menu.heading>{{ __('targets.attach_under') }}</flux:menu.heading>
                                                                @foreach ($nodes as $node)
                                                                    <flux:menu.item wire:click="attach({{ $entry->id }}, {{ $node->id }})" wire:key="attach-{{ $entry->id }}-{{ $node->id }}">
                                                                        <span class="truncate" dir="auto">{{ $node->type->label() }} · {{ \Illuminate\Support\Str::limit($node->statement, 60) }}</span>
                                                                    </flux:menu.item>
                                                                @endforeach
                                                            </flux:menu>
                                                        </flux:dropdown>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @empty
                                        <p class="px-3 py-3 text-xs {{ $mutedText }}">{{ $pickerSearch === '' ? __('targets.picker_group_empty') : __('targets.picker_no_match') }}</p>
                                    @endforelse
                                </section>
                            @endforeach
                        </div>
                    </aside>
                @endif
            </div>
        @endif
    </x-ui.panel>

    {{-- Targets grid: one target per indicator and center. --}}
    <x-ui.panel :title="__('targets.grid_heading')" :subtitle="__('targets.grid_subtitle')" data-tour="proposal-targets-grid">
        @if ($this->canEditGrid && $this->gridRows !== [])
            <x-slot:toolbar>
                <flux:button size="sm" variant="primary" icon="check" wire:click="saveGrid" wire:target="saveGrid">{{ __('targets.save_grid') }}</flux:button>
            </x-slot:toolbar>
        @endif

        @if (! $this->canEditGrid && ! $this->isReadOnlyProposal)
            <p class="flex items-center gap-2 border-b {{ $hairline }} px-4 py-2 text-xs {{ $mutedText }}" data-targets-meal-only>
                <flux:icon.information-circle variant="micro" class="size-4 shrink-0" aria-hidden="true" />
                {{ __('targets.meal_enters_targets') }}
            </p>
        @endif

        @error('cells')
            <div class="border-b border-status-critical/30 bg-status-critical-soft px-4 py-3 text-sm text-status-critical" role="alert" data-grid-errors>
                <p class="flex items-center gap-1.5 font-semibold"><flux:icon.exclamation-triangle variant="micro" class="size-4 shrink-0" />{{ $message }}</p>
                @if ($isStale)
                    <p class="mt-1">{{ __('targets.cell_stale') }}
                        <button type="button" class="ms-1 font-semibold underline underline-offset-2" wire:click="reloadGrid">{{ __('targets.reload_grid') }}</button>
                    </p>
                @endif
            </div>
        @enderror
        @error('proposal')
            <p class="border-b border-status-critical/30 bg-status-critical-soft px-4 py-3 text-sm font-semibold text-status-critical" role="alert">{{ $message }}</p>
        @enderror

        @if ($this->gridRows === [] || $columns === [])
            <x-ui.empty-state icon="table-cells" :heading="__('targets.grid_empty_heading')" :body="$columns === [] ? __('targets.grid_no_projects') : __('targets.grid_empty_body')" />
        @else
            <x-ui.targets-grid :rows="$this->gridRows" :columns="$columns" :editable="$this->canEditGrid" :grid="$grid" :error-bag="$errors" />
        @endif
    </x-ui.panel>

    @if ($this->outsideTargets->isNotEmpty())
        <x-ui.panel :title="__('targets.outside_heading')" :subtitle="__('targets.outside_subtitle')" :count="$this->outsideTargets->count()" data-outside-targets>
            <ul class="divide-y divide-secondary-soft dark:divide-line">
                @foreach ($this->outsideTargets as $target)
                    <li class="px-4 py-3" wire:key="outside-{{ $target->id }}" data-outside-target="{{ $target->id }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold {{ $inkText }}" dir="auto">{{ $target->indicator->name }}</p>
                                <p class="mt-0.5 text-xs {{ $mutedText }}" dir="auto">{{ $target->project?->name ?? __('common.organisation_wide') }}</p>
                            </div>
                            <p class="flex shrink-0 items-baseline gap-2">
                                <x-ui.enum-badge :case="$target->period" />
                                <span class="font-bold tabular-nums {{ $inkText }}" dir="ltr">{{ number_format((float) $target->target, 2) }}</span>
                            </p>
                        </div>
                        @if (! $this->instances->contains('id', $target->indicator_id))
                            <p class="mt-1.5 flex items-center gap-1.5 text-xs font-semibold text-status-critical"><flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0" />{{ __('targets.outside_framework') }}</p>
                        @endif
                        @foreach ($target->chainIssues() as $issue)
                            <p class="mt-1.5 flex items-center gap-1.5 text-xs font-semibold text-status-critical" wire:key="outside-issue-{{ $target->id }}-{{ $loop->index }}"><flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0" />{{ __($issue) }}</p>
                        @endforeach
                    </li>
                @endforeach
            </ul>
        </x-ui.panel>
    @endif

    <x-ui.confirm-modal
        wire:model="showDetachModal"
        :title="__('targets.detach_title')"
        :message="__('targets.detach_message')"
        confirm="detach"
        :confirm-label="__('targets.detach')"
        close="cancelDetach"
    >
        @error('indicator')
            <p class="flex items-start gap-1.5 text-sm font-semibold text-status-critical" role="alert"><flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-4 shrink-0" />{{ $message }}</p>
        @enderror
    </x-ui.confirm-modal>

    @can('indicator-library.create')
        @include('pages.indicator-library._index-entry-modal')
    @endcan
</div>
