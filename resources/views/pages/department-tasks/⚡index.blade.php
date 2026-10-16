<?php

use App\Actions\Tasks\AcceptDepartmentTask;
use App\Actions\Tasks\DeliverDepartmentTask;
use App\Actions\Tasks\ReturnDepartmentTask;
use App\Enums\DeliveryState;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\HrDepartment;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
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
new #[Title('titles.department_tasks')] class extends Component
{
    use WithFileUploads;

    /** How many files one delivery takes (DeliverDepartmentTask::MaxFiles). */
    private const MaxDeliveryFiles = 10;

    #[Url(except: '')]
    public string $department = '';

    #[Url(except: '')]
    public string $kind = '';

    #[Url(except: '')]
    public string $state = '';

    #[Url(except: '')]
    public string $due = '';

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
     * Every department task the viewer may see, filtered in memory.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function departmentTasks(): Collection
    {
        $tasks = Task::query()
            ->select(['id', 'slug', 'source_type', 'source_id', 'department_id', 'kind', 'media_kinds', 'delivery_state', 'title', 'status', 'start_date', 'due_date', 'progress_percentage', 'created_by', 'returned_reason'])
            ->whereNotNull('department_id')
            ->whereNull('parent_task_id')
            ->with([
                'taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'source' => fn (MorphTo $morphTo) => $morphTo
                    ->constrain([
                        Proposal::class => fn ($query) => $query->select(['id', 'slug', 'name', 'status']),
                        Milestone::class => fn ($query) => $query->select(['id', 'slug', 'name', 'proposal_id']),
                    ])
                    ->morphWith([Milestone::class => ['proposal:id,slug,name,status']]),
            ])
            ->orderByRaw('due_date is null, due_date')
            ->orderBy('id')
            ->get();

        return $tasks->filter(fn (Task $task): bool => $this->passesFilters($task))->values();
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
     * The departments the visible tasks belong to, for the filter.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function departmentOptions(): array
    {
        return Task::query()
            ->whereNotNull('department_id')
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
        if (in_array($property, ['department', 'kind', 'state', 'due'], true)) {
            unset($this->departmentTasks, $this->groups, $this->openableProposals);
        }
    }

    public function clearFilters(): void
    {
        $this->reset('department', 'kind', 'state', 'due');
        unset($this->departmentTasks, $this->groups, $this->openableProposals);
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

        $isOpen = $task->status !== TaskStatus::Done;

        return match ($this->due) {
            'overdue' => $isOpen && $task->due_date !== null && $task->due_date->lt(today()),
            'week' => $isOpen && $task->due_date !== null && $task->due_date->betweenIncluded(today(), today()->addDays(7)),
            'unassigned' => $isOpen && $task->taskAssignees->isEmpty(),
            default => true,
        };
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

    private function forgetTasks(): void
    {
        unset($this->departmentTasks, $this->groups, $this->openableProposals);
    }
};
?>

@php
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';
    $filtersAreActive = $department !== '' || $kind !== '' || $state !== '' || $due !== '';
    $groups = $this->groups;
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="department-tasks-heading" data-tour="department-tasks">
    <x-ui.page-header heading-id="department-tasks-heading" :title="__('department_tasks.page_title')" :count="$this->departmentTasks->count()">
        <x-slot:filters>
            <x-ui.header-filter-select :label="__('department_tasks.department')" wire:model.live="department" :value="$department">
                <option value="">{{ __('department_tasks.all_departments') }}</option>
                @foreach ($this->departmentOptions as $departmentId => $departmentName)
                    <option value="{{ $departmentId }}">{{ $departmentName }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('department_tasks.kind')" wire:model.live="kind" :value="$kind">
                <option value="">{{ __('department_tasks.all_kinds') }}</option>
                @foreach (TaskKind::cases() as $kindCase)
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
                <option value="overdue">{{ __('department_tasks.due_overdue') }}</option>
                <option value="week">{{ __('department_tasks.due_this_week') }}</option>
                <option value="unassigned">{{ __('department_tasks.no_person_yet') }}</option>
            </x-ui.header-filter-select>
        </x-slot:filters>
    </x-ui.page-header>

    <div class="flex flex-col gap-8" wire:loading.class="opacity-60" wire:target="department, kind, state, due, clearFilters" data-tour="department-tasks-list">
        @if ($groups === [])
            <x-ui.empty-state
                icon="clipboard-document-list"
                :heading="$filtersAreActive ? __('department_tasks.no_match') : __('department_tasks.none_yet')"
                :body="$filtersAreActive ? __('department_tasks.no_match_body') : __('department_tasks.none_yet_body')"
            >
                @if ($filtersAreActive)
                    <x-slot:action>
                        <x-ui.button size="sm" wire:click="clearFilters">{{ __('department_tasks.clear_filters') }}</x-ui.button>
                    </x-slot:action>
                @endif
            </x-ui.empty-state>
        @endif

        @foreach ($groups as $group)
            <section class="min-w-0" wire:key="department-task-group-{{ $group['status']->value }}" data-status-group="{{ $group['status']->value }}">
                <header class="flex items-center gap-2 border-b pb-3 {{ $hairline }}">
                    <x-ui.enum-badge :case="$group['status']" />
                    <span class="text-xs font-semibold tabular-nums {{ $mutedText }}">{{ $group['tasks']->count() }}</span>
                </header>

                <ul class="divide-y {{ $hairline }}">
                    @foreach ($group['tasks'] as $task)
                        @include('pages.department-tasks._task-row', ['task' => $task, 'proposal' => $this->proposalOf($task)])
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>

    @include('pages.department-tasks._modals')
</section>
