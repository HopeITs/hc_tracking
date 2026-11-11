<?php

use App\Actions\Tasks\AcceptDepartmentTask;
use App\Actions\Tasks\DeliverDepartmentTask;
use App\Actions\Tasks\ReturnDepartmentTask;
use App\Enums\DeliveryState;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\HrDepartment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Department Tasks (T31, D-16, D-33): every department task the viewer may
 * see — their department's Media, Finance and other plan work — grouped by
 * status and due date, with the media delivery, accept and return moves.
 *
 * No money anywhere: the query selects task and proposal-name columns only,
 * never budget, agreement, transfer or currency data, and a proposal name
 * links to its page only for people who may open it.
 */
new #[Layout('layouts::desk'), Title('titles.department_tasks')] class extends Component
{
    use WithFileUploads;

    /** How many files one delivery takes (DeliverDepartmentTask::MaxFiles). */
    private const MaxDeliveryFiles = 10;

    /** The headline figures each filter the list to one of these. */
    private const Focuses = ['overdue', 'deliver', 'review'];

    /** The properties that narrow the list; changing one re-reads it. */
    private const FilterProperties = ['search', 'focus', 'department', 'kind', 'state', 'due', 'person'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $focus = '';

    #[Url(except: '')]
    public string $department = '';

    #[Url(except: '')]
    public string $kind = '';

    #[Url(except: '')]
    public string $state = '';

    #[Url(except: '')]
    public string $due = '';

    #[Url(except: '')]
    public string $person = '';

    #[Url(except: false)]
    public bool $showDone = false;

    public bool $showDeliveryModal = false;

    #[Locked]
    public ?int $deliveringTaskId = null;

    #[Locked]
    public string $deliveringExpectedState = '';

    public string $deliveredOn = '';

    public string $deliveredLink = '';

    public string $deliveredText = '';

    /** @var array<int, mixed> */
    public array $deliveryFiles = [];

    public int $deliveryFileSlots = 1;

    public bool $showAcceptModal = false;

    #[Locked]
    public ?int $reviewingTaskId = null;

    #[Locked]
    public string $reviewingExpectedState = '';

    public bool $showReturnModal = false;

    public string $returnReason = '';

    /**
     * The projects whose center HR has deactivated, read once so the
     * «Paused» chip costs no query per row (D-66, W-02).
     *
     * @return list<int>
     */
    #[Computed]
    public function pausedProjectIds(): array
    {
        return Project::pausedIds();
    }

    /**
     * Every department task the viewer may see, before any filter: soonest
     * due first, undated last, then by title.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function allDepartmentTasks(): Collection
    {
        return Task::query()
            ->select(['id', 'slug', 'source_type', 'source_id', 'department_id', 'kind', 'media_kinds', 'delivery_state', 'title', 'status', 'start_date', 'due_date', 'progress_percentage', 'created_by', 'returned_reason'])
            ->whereNotNull('department_id')
            ->whereNull('parent_task_id')
            ->with([
                'taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'source' => fn (MorphTo $morphTo) => $morphTo
                    ->constrain([
                        Proposal::class => fn ($query) => $query->select(['id', 'slug', 'name', 'status']),
                        Milestone::class => fn ($query) => $query->select(['id', 'slug', 'name', 'proposal_id', 'project_id']),
                    ])
                    ->morphWith([Milestone::class => ['proposal:id,slug,name,status']]),
            ])
            ->orderByRaw('due_date is null, due_date')
            ->orderBy('title')
            ->orderBy('id')
            ->get();
    }

    /**
     * The tasks that pass the search and every filter except the headline
     * focus, so pressing one figure never changes the others.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function matchingTasks(): Collection
    {
        return $this->allDepartmentTasks->filter(fn (Task $task): bool => $this->passesFilters($task))->values();
    }

    /**
     * The tasks on the list: the matching ones, narrowed to the pressed figure.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function departmentTasks(): Collection
    {
        if ($this->focus === '') {
            return $this->matchingTasks;
        }

        return $this->matchingTasks->filter(fn (Task $task): bool => $this->isInFocus($task, $this->focus))->values();
    }

    /**
     * The ids of the deliverables the viewer can hand over or review now,
     * checked once per page so the rows and the figures agree.
     *
     * @return array{deliver: list<int>, review: list<int>}
     */
    #[Computed]
    public function actionableTaskIds(): array
    {
        $user = auth()->user();
        $deliverable = $this->allDepartmentTasks->where('kind', TaskKind::MediaDeliverable);

        return [
            'deliver' => $deliverable
                ->filter(fn (Task $task): bool => in_array($task->delivery_state, [DeliveryState::Requested, DeliveryState::Returned], true) && $user?->can('deliver', $task) === true)
                ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'review' => $deliverable
                ->filter(fn (Task $task): bool => $task->delivery_state === DeliveryState::Delivered && $user?->can('review', $task) === true)
                ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
        ];
    }

    /**
     * How many matching tasks each headline figure would show, and how many
     * matching tasks are still open (the figures' denominator).
     *
     * @return array{overdue: int, deliver: int, review: int, open: int}
     */
    #[Computed]
    public function focusCounts(): array
    {
        $tasks = $this->matchingTasks;

        return [
            'overdue' => $tasks->filter(fn (Task $task): bool => $this->isInFocus($task, 'overdue'))->count(),
            'deliver' => $tasks->filter(fn (Task $task): bool => $this->isInFocus($task, 'deliver'))->count(),
            'review' => $tasks->filter(fn (Task $task): bool => $this->isInFocus($task, 'review'))->count(),
            'open' => $tasks->where('status', '!==', TaskStatus::Done)->count(),
        ];
    }

    /**
     * The tasks in status order — to do, in progress, under review, done —
     * soonest due first inside each group.
     *
     * @return list<array{status: TaskStatus, tasks: Collection<int, Task>}>
     */
    #[Computed]
    public function groups(): array
    {
        return collect([TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::UnderReview, TaskStatus::Done])
            ->map(fn (TaskStatus $status): array => ['status' => $status, 'tasks' => $this->departmentTasks->where('status', $status)->values()])
            ->filter(fn (array $group): bool => $group['tasks']->isNotEmpty())
            ->values()
            ->all();
    }

    /**
     * The departments the visible tasks belong to, for the filter. Subtasks
     * are left out, as they are from the list.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function departmentOptions(): array
    {
        return Task::query()
            ->whereNotNull('department_id')
            ->whereNull('parent_task_id')
            ->distinct()
            ->pluck('department_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => HrDepartment::nameOf((int) $id) ?: __('department_tasks.department_unavailable')])
            ->sort()
            ->all();
    }

    /**
     * Which proposals the viewer may open, so a name links only for them.
     *
     * @return array<int, bool>
     */
    #[Computed]
    public function openableProposals(): array
    {
        $user = auth()->user();
        $canViewAll = $user?->can('proposals.view') === true;

        return $this->departmentTasks
            ->map(fn (Task $task): ?Proposal => $this->proposalOf($task))
            ->filter()
            ->unique('id')
            ->mapWithKeys(fn (Proposal $proposal): array => [$proposal->id => $canViewAll || $user?->can('view', $proposal) === true])
            ->all();
    }

    public function proposalOf(Task $task): ?Proposal
    {
        $source = $task->source;

        return match (true) {
            $source instanceof Proposal => $source,
            $source instanceof Milestone => $source->proposal,
            default => null,
        };
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FilterProperties, true)) {
            $this->forgetFilteredTasks();
        }
    }

    /**
     * Press a headline figure: show only its tasks, or every task again when
     * it is already pressed.
     */
    public function toggleFocus(string $focus): void
    {
        if (! in_array($focus, self::Focuses, true)) {
            return;
        }

        $this->focus = $this->focus === $focus ? '' : $focus;
        $this->forgetFilteredTasks();
    }

    public function clearFilters(): void
    {
        $this->reset(...self::FilterProperties);
        $this->forgetFilteredTasks();
    }

    /**
     * Whether any search, figure or filter narrows the list.
     */
    public function hasActiveFilters(): bool
    {
        return collect(self::FilterProperties)->contains(fn (string $property): bool => $this->{$property} !== '');
    }

    /**
     * Whether a filter kept behind "More filters" is set, so it opens itself.
     */
    public function hasActiveMoreFilters(): bool
    {
        return $this->kind !== '' || $this->state !== '' || $this->due !== '' || $this->person !== '';
    }

    public function openDelivery(int $taskId): void
    {
        $task = $this->visibleTask($taskId);

        $this->resetDeliveryForm();
        $this->deliveringTaskId = $task->id;
        $this->deliveringExpectedState = (string) $task->delivery_state?->value;
        $this->deliveredOn = today()->toDateString();
        $this->showDeliveryModal = true;
    }

    public function addDeliveryFileSlot(): void
    {
        $this->deliveryFileSlots = min(self::MaxDeliveryFiles, $this->deliveryFileSlots + 1);
    }

    public function deliver(DeliverDepartmentTask $action): void
    {
        if ($this->deliveringTaskId === null) {
            return;
        }

        $this->resetValidation();
        $task = $this->visibleTask($this->deliveringTaskId);

        try {
            $action->handle($task, DeliveryState::from($this->deliveringExpectedState), [
                'delivered_on' => $this->deliveredOn,
                'delivered_link' => $this->deliveredLink,
                'delivered_text' => $this->deliveredText,
            ], array_values(array_filter($this->deliveryFiles)));
        } catch (ValidationException $exception) {
            $this->showActionErrors($exception, [
                'delivered_on' => 'deliveredOn',
                'delivered_link' => 'deliveredLink',
                'delivered_text' => 'deliveredText',
                'files' => 'deliveryFiles',
            ], 'delivery');

            return;
        }

        $this->closeDeliveryModal();
        $this->forgetTasks();
        Flux::toast(variant: 'success', text: __('department_tasks.delivered_toast'));
    }

    public function closeDeliveryModal(): void
    {
        $this->showDeliveryModal = false;
        $this->resetDeliveryForm();
    }

    public function confirmAccept(int $taskId): void
    {
        $task = $this->visibleTask($taskId);

        $this->resetValidation();
        $this->reviewingTaskId = $task->id;
        $this->reviewingExpectedState = (string) $task->delivery_state?->value;
        $this->showAcceptModal = true;
    }

    public function accept(AcceptDepartmentTask $action): void
    {
        if ($this->reviewingTaskId === null) {
            return;
        }

        try {
            $action->handle($this->visibleTask($this->reviewingTaskId), DeliveryState::from($this->reviewingExpectedState));
        } catch (ValidationException $exception) {
            $this->closeReview();
            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());
            $this->forgetTasks();

            return;
        }

        $this->closeReview();
        $this->forgetTasks();
        Flux::toast(variant: 'success', text: __('department_tasks.accepted_toast'));
    }

    public function confirmReturn(int $taskId): void
    {
        $task = $this->visibleTask($taskId);

        $this->resetValidation();
        $this->reviewingTaskId = $task->id;
        $this->reviewingExpectedState = (string) $task->delivery_state?->value;
        $this->returnReason = '';
        $this->showReturnModal = true;
    }

    public function returnDelivery(ReturnDepartmentTask $action): void
    {
        if ($this->reviewingTaskId === null) {
            return;
        }

        $this->resetValidation();

        try {
            $action->handle($this->visibleTask($this->reviewingTaskId), DeliveryState::from($this->reviewingExpectedState), $this->returnReason);
        } catch (ValidationException $exception) {
            $this->showActionErrors($exception, ['reason' => 'returnReason'], 'returnReason');

            return;
        }

        $this->closeReview();
        $this->forgetTasks();
        Flux::toast(variant: 'success', text: __('department_tasks.returned_toast'));
    }

    public function closeReview(): void
    {
        $this->showAcceptModal = false;
        $this->showReturnModal = false;
        $this->reviewingTaskId = null;
        $this->reviewingExpectedState = '';
        $this->returnReason = '';
    }

    /**
     * A department task the viewer may see, re-read through the visibility
     * scope: an id from the browser that is not on this page is a 404.
     */
    private function visibleTask(int $taskId): Task
    {
        return Task::query()->whereNotNull('department_id')->whereKey($taskId)->firstOrFail();
    }

    private function passesFilters(Task $task): bool
    {
        if ($this->department !== '' && (string) $task->department_id !== $this->department) {
            return false;
        }

        if ($this->kind !== '' && $task->kind->value !== $this->kind) {
            return false;
        }

        if ($this->state !== '' && $task->delivery_state?->value !== $this->state) {
            return false;
        }

        if ($this->person === 'none' && ($task->status === TaskStatus::Done || $task->taskAssignees->isNotEmpty())) {
            return false;
        }

        if ($this->search !== '' && ! $this->matchesSearch($task)) {
            return false;
        }

        return match ($this->due) {
            'week' => $task->status !== TaskStatus::Done && $task->due_date !== null && $task->due_date->betweenIncluded(today(), today()->addDays(7)),
            'none' => $task->due_date === null,
            default => true,
        };
    }

    /**
     * Whether a task belongs to a headline figure: overdue and still open,
     * waiting for the viewer to hand it over, or waiting for their review.
     */
    private function isInFocus(Task $task, string $focus): bool
    {
        return match ($focus) {
            'overdue' => $task->status !== TaskStatus::Done && $task->due_date !== null && $task->due_date->lt(today()),
            'deliver' => in_array($task->id, $this->actionableTaskIds['deliver'], true),
            'review' => in_array($task->id, $this->actionableTaskIds['review'], true),
            default => true,
        };
    }

    /**
     * The search looks at the task's title, its proposal, its department and
     * the people on it, in either language.
     */
    private function matchesSearch(Task $task): bool
    {
        $needle = mb_strtolower(trim($this->search));

        if ($needle === '') {
            return true;
        }

        $haystack = collect([
            $task->title,
            $this->proposalOf($task)?->name,
            HrDepartment::nameOf((int) $task->department_id),
            ...$task->taskAssignees->pluck('employee')->filter()->map->name->all(),
        ])->filter()->implode(' ');

        return str_contains(mb_strtolower($haystack), $needle);
    }

    /**
     * @param  array<string, string>  $fields  action key => page property
     */
    private function showActionErrors(ValidationException $exception, array $fields, string $fallback): void
    {
        foreach ($exception->errors() as $key => $messages) {
            $field = $fields[str($key)->before('.')->toString()] ?? $fallback;

            foreach ($messages as $message) {
                $this->addError($field, $message);
            }
        }
    }

    private function resetDeliveryForm(): void
    {
        $this->resetValidation();
        $this->deliveringTaskId = null;
        $this->deliveringExpectedState = '';
        $this->deliveredOn = '';
        $this->deliveredLink = '';
        $this->deliveredText = '';
        $this->deliveryFiles = [];
        $this->deliveryFileSlots = 1;
    }

    /**
     * Forget the list after a filter changed; the tasks themselves stay read.
     */
    private function forgetFilteredTasks(): void
    {
        unset($this->matchingTasks, $this->departmentTasks, $this->focusCounts, $this->groups, $this->openableProposals);
    }

    /**
     * Forget everything after a task was written.
     */
    private function forgetTasks(): void
    {
        unset($this->allDepartmentTasks, $this->actionableTaskIds);
        $this->forgetFilteredTasks();
    }
};
?>

@php
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';
    $filtersAreActive = $this->hasActiveFilters();
    $groups = $this->groups;
@endphp

<x-desk.register
    class="@container/directory"
    heading-id="department-tasks-heading"
    :title="__('department_tasks.page_title')"
    :count="$this->departmentTasks->count()"
    data-tour="department-tasks"
>
    <x-slot:search>
        <x-desk.register-search :label="__('department_tasks.search_label')" :placeholder="__('department_tasks.search_placeholder')" wire:model.live.debounce.300ms="search" />
    </x-slot:search>

    <x-slot:filters>
        <x-ui.header-filter-select :label="__('department_tasks.department')" wire:model.live="department" :value="$department">
            <option value="">{{ __('department_tasks.all_departments') }}</option>
            @foreach ($this->departmentOptions as $departmentId => $departmentName)
                <option value="{{ $departmentId }}">{{ $departmentName }}</option>
            @endforeach
        </x-ui.header-filter-select>

        <div class="contents" x-data="{ open: @js($this->hasActiveMoreFilters()) }">
            <button type="button" class="dk-btn" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-controls="department-tasks-more-filters" data-test="more-filters-button">
                <flux:icon.adjustments-horizontal variant="micro" class="size-4" aria-hidden="true" />
                {{ __('department_tasks.more_filters') }}
            </button>

            <div class="contents" id="department-tasks-more-filters" x-show="open" x-cloak>
                <x-ui.header-filter-select :label="__('department_tasks.kind')" wire:model.live="kind" :value="$kind">
                    <option value="">{{ __('department_tasks.all_kinds') }}</option>
                    @foreach (TaskKind::department() as $kindCase)
                        <option value="{{ $kindCase->value }}">{{ $kindCase->label() }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('department_tasks.delivery')" wire:model.live="state" :value="$state">
                    <option value="">{{ __('department_tasks.any_delivery_state') }}</option>
                    @foreach (DeliveryState::cases() as $stateCase)
                        <option value="{{ $stateCase->value }}">{{ $stateCase->label() }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('department_tasks.due_filter')" wire:model.live="due" :value="$due">
                    <option value="">{{ __('department_tasks.any_due_date') }}</option>
                    <option value="week">{{ __('department_tasks.due_this_week') }}</option>
                    <option value="none">{{ __('department_tasks.no_due_date') }}</option>
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('department_tasks.person')" wire:model.live="person" :value="$person">
                    <option value="">{{ __('department_tasks.anyone') }}</option>
                    <option value="none">{{ __('department_tasks.no_person_yet') }}</option>
                </x-ui.header-filter-select>
            </div>
        </div>
    </x-slot:filters>

    @include('pages.department-tasks._index-headline')

    <div class="flex flex-col gap-8" wire:loading.class="opacity-60" wire:target="search, focus, toggleFocus, department, kind, state, due, person, clearFilters" data-tour="department-tasks-list">
        @if ($groups === [])
            <x-ui.empty-state
                icon="clipboard-document-list"
                :heading="$filtersAreActive ? __('department_tasks.no_match') : __('department_tasks.none_yet')"
                :body="$filtersAreActive ? __('department_tasks.no_match_body') : __('department_tasks.none_yet_body')"
            >
                @if ($filtersAreActive)
                    <x-slot:action>
                        <x-ui.button size="sm" wire:click="clearFilters" data-test="clear-filters">{{ __('department_tasks.clear_filters') }}</x-ui.button>
                    </x-slot:action>
                @endif
            </x-ui.empty-state>
        @endif

        @foreach ($groups as $group)
            @php
                $isDoneGroup = $group['status'] === TaskStatus::Done;
                $rowsAreShown = ! $isDoneGroup || $showDone;
            @endphp
            <section class="min-w-0" wire:key="department-task-group-{{ $group['status']->value }}" data-status-group="{{ $group['status']->value }}">
                <header @class(['flex items-center gap-2', 'border-b pb-3 '.$hairline => $rowsAreShown])>
                    <x-ui.enum-badge :case="$group['status']" />
                    <span class="text-xs font-semibold tabular-nums {{ $mutedText }}">{{ $group['tasks']->count() }}</span>
                    @if ($isDoneGroup)
                        <x-ui.button size="sm" tone="ghost" class="ms-auto min-h-11 touch-manipulation sm:min-h-0" wire:click="$toggle('showDone')" :aria-expanded="$showDone ? 'true' : 'false'" data-test="toggle-done">
                            {{ $showDone ? __('department_tasks.hide_done') : __('department_tasks.show_done') }}
                        </x-ui.button>
                    @endif
                </header>

                @if ($rowsAreShown)
                    <ul class="divide-y {{ $hairline }}">
                        @foreach ($group['tasks'] as $task)
                            @include('pages.department-tasks._task-row', ['task' => $task, 'proposal' => $this->proposalOf($task)])
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </div>

    @include('pages.department-tasks._modals')
</x-desk.register>
