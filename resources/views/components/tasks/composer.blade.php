@props([
    'open' => false,
    'model',
    'close',
    'editing' => false,
    'sourceTypeOptions' => [],
    'sourceOptions' => [],
    'statusLocked' => false,
    'dueDateLocked' => false,
    'proofLocked' => false,
    'dueFlag' => null,
    'testPrefix' => 'task-drawer',
    'saveTest' => 'save-task-button',
])

@use('App\Enums\TaskPriority')
@use('App\Enums\TaskStatus')

{{--
    The one task create/edit dialog of tasks/index and calendar/index: a
    single screen, not a step wizard. Where the task is filed ("Related to")
    sits on top, the title and description read like the task itself, and
    every property is a chip in one row (status, assignees, due date,
    priority, proof needed); start date, completed date and progress fold under "More
    fields". Both pages' components own the same properties (title,
    description, taskSourceType, taskSourceId, taskStatus, taskPriority,
    assignedTo, startDate, dueDate, completedDate, progressPercentage,
    evidenceRequired) and
    saveTask(), so the business rules stay in SavesTaskForm.

    @var array<string, string> $sourceTypeOptions  value => label
    @var array<string, string> $sourceOptions  id => label
    @var string|null $dueFlag  'missed', 'overdue' or null
--}}
@php
    $livewire = $__env->shared('__livewire');
    $currentSourceType = (string) data_get($livewire, 'taskSourceType', '');
    $startDate = (string) data_get($livewire, 'startDate', '');
    $dueDate = (string) data_get($livewire, 'dueDate', '');
    $completedDate = (string) data_get($livewire, 'completedDate', '');
    $progress = (string) data_get($livewire, 'progressPercentage', '0');

    $foldedFields = ['startDate', 'completedDate', 'progressPercentage'];
    $foldedHasErrors = collect($foldedFields)->contains(fn (string $field): bool => $errors->has($field));
    $showMore = $foldedHasErrors || $startDate !== '' || $completedDate !== '' || ($progress !== '' && $progress !== '0');

    /* The chips carry no error line of their own; their messages gather under the row, in reading order. */
    $chipErrors = collect([
        'taskSourceType' => __('tasks.source_type'),
        'taskSourceId' => __('tasks.source'),
        'taskStatus' => __('common.status'),
        'assignedTo' => __('tasks.assignees'),
        'assignedTo.*' => __('tasks.assignees'),
        'dueDate' => __('tasks.due_date_2'),
        'taskPriority' => __('tasks.priority'),
        'startDate' => __('common.start_date'),
        'completedDate' => __('tasks.completed_date'),
        'progressPercentage' => __('tasks.progress_2'),
    ])->map(fn (string $label, string $field): ?string => ($message = $errors->first($field)) !== '' ? $label.': '.$message : null)
        ->filter()->unique()->values();

    $notes = collect([
        $statusLocked ? __('tasks.completed_tasks_cannot_change_status') : null,
        $dueDateLocked ? __('tasks.only_the_task_creator_can_change_the_due_date') : null,
        $proofLocked ? __('tasks.only_the_task_creator_can_change_proof') : null,
    ])->filter()->values();

    $titleError = $errors->first('title');
    $descriptionError = $errors->first('description');
@endphp

<x-ui.modal
    wire:model="{{ $model }}"
    size="lg"
    :title="$editing ? __('tasks.edit_task') : __('tasks.add_task')"
    submit="saveTask"
    :close="$close"
    required-note
>
    @if ($open)
        <div class="task-composer" x-data="{ more: @js($showMore) }" data-test="{{ $testPrefix }}-composer">
            @if ($foldedHasErrors)
                <span hidden x-init="more = true"></span>
            @endif

            {{-- Where the task is filed: required, so it leads, like a list picker above a task name. --}}
            <div class="task-composer-related">
                <span class="task-composer-caption">
                    {{ __('tasks.source_type') }}
                    <span class="form-required" aria-hidden="true">*</span>
                </span>

                <x-ui.form-select
                    chip
                    chip-icon="squares-2x2"
                    wire:model.live="taskSourceType"
                    :label="__('tasks.source_type')"
                    :placeholder="__('tasks.composer_choose_type')"
                    required
                    :data-test="$testPrefix.'-source-type'"
                >
                    @foreach ($sourceTypeOptions as $sourceTypeValue => $sourceTypeLabel)
                        <option value="{{ $sourceTypeValue }}">{{ $sourceTypeLabel }}</option>
                    @endforeach
                </x-ui.form-select>

                <flux:icon.chevron-right variant="micro" class="size-3.5 shrink-0 text-secondary-light rtl:rotate-180 dark:text-ink-faint" aria-hidden="true" />

                <x-ui.form-select
                    chip
                    chip-icon="link"
                    wire:model="taskSourceId"
                    :label="__('tasks.source')"
                    :placeholder="$currentSourceType === '' ? __('tasks.composer_choose_type_first') : __('tasks.composer_choose_record')"
                    :disabled="$currentSourceType === ''"
                    searchable
                    required
                    class="min-w-0"
                    :data-test="$testPrefix.'-source'"
                >
                    @foreach ($sourceOptions as $sourceOptionId => $sourceOptionLabel)
                        <option value="{{ $sourceOptionId }}">{{ $sourceOptionLabel }}</option>
                    @endforeach
                </x-ui.form-select>
            </div>

            {{-- The task itself: a large borderless name and a quiet description. Enter on the name saves. --}}
            <div class="task-composer-text">
                <label for="{{ $testPrefix }}-title" class="sr-only">{{ __('tasks.task_title') }}</label>
                <input
                    id="{{ $testPrefix }}-title"
                    type="text"
                    wire:model="title"
                    maxlength="255"
                    required
                    autofocus
                    autocomplete="off"
                    placeholder="{{ __('tasks.composer_title_placeholder') }}"
                    class="task-composer-title"
                    @if ($titleError !== '') aria-invalid="true" aria-describedby="{{ $testPrefix }}-title-error" @endif
                    data-test="{{ $testPrefix }}-title-input"
                />
                @if ($titleError !== '')
                    <p id="{{ $testPrefix }}-title-error" class="form-error">
                        <flux:icon.exclamation-circle variant="micro" class="size-3.5 shrink-0" />
                        {{ $titleError }}
                    </p>
                @endif

                <label for="{{ $testPrefix }}-description" class="sr-only">{{ __('common.description') }}</label>
                <textarea
                    id="{{ $testPrefix }}-description"
                    wire:model="description"
                    rows="2"
                    placeholder="{{ __('tasks.composer_description_placeholder') }}"
                    class="task-composer-description"
                    data-test="{{ $testPrefix }}-description-input"
                ></textarea>
                @if ($descriptionError !== '')
                    <p class="form-error">
                        <flux:icon.exclamation-circle variant="micro" class="size-3.5 shrink-0" />
                        {{ $descriptionError }}
                    </p>
                @endif
            </div>

            {{-- Properties: one row of chips, the most used first. --}}
            <div class="task-composer-chips" role="group" aria-label="{{ __('tasks.composer_properties') }}">
                <x-ui.form-select
                    chip
                    wire:model="taskStatus"
                    :label="__('common.status')"
                    :disabled="$statusLocked"
                    :locked-reason="$statusLocked ? __('tasks.completed_tasks_cannot_change_status') : null"
                    required
                    :chip-tones="[
                        TaskStatus::Todo->value => 'border border-secondary-light dark:border-ink-muted',
                        TaskStatus::InProgress->value => 'bg-status-active',
                        TaskStatus::UnderReview->value => 'bg-status-warn',
                        TaskStatus::Done->value => 'bg-status-good',
                    ]"
                    :data-test="$testPrefix.'-status'"
                >
                    @foreach (TaskStatus::cases() as $statusCase)
                        <option value="{{ $statusCase->value }}">{{ $statusCase->label() }}</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-multiselect
                    chip
                    chip-icon="user-plus"
                    wire:model="assignedTo"
                    :label="__('tasks.assignees')"
                    :placeholder="__('tasks.assignees')"
                    required
                    employees
                    data-test="task-assignee-picker"
                />

                <label
                    @class(['form-chip', 'form-chip-date'])
                    x-data="{ filled: @js($dueDate !== '') }"
                    title="{{ __('tasks.due_date_2') }}{{ $dueDateLocked ? ' · '.__('tasks.only_the_task_creator_can_change_the_due_date') : '' }}"
                    @if ($errors->has('dueDate')) data-invalid @endif
                    @if ($dueDateLocked) data-disabled @endif
                >
                    <flux:icon.calendar variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                    <span class="sr-only">{{ __('tasks.due_date_2') }}</span>
                    <span class="form-chip-placeholder" x-show="! filled" @if ($dueDate !== '') style="display: none" @endif aria-hidden="true">{{ __('tasks.due_date_2') }}</span>
                    <input
                        type="date"
                        wire:model="dueDate"
                        value="{{ $dueDate }}"
                        @disabled($dueDateLocked)
                        x-bind:data-empty="filled ? null : ''"
                        @if ($dueDate === '') data-empty @endif
                        x-on:input="filled = $el.value !== ''"
                        x-on:change="filled = $el.value !== ''"
                        x-on:click="$el.showPicker?.()"
                        data-test="{{ $testPrefix }}-due-date"
                    />
                </label>

                @if ($dueFlag !== null)
                    <span class="inline-flex items-center rounded-full bg-secondary-soft/60 px-2 py-0.5 text-[10px] font-bold tracking-wide text-status-critical uppercase dark:bg-surface-2">{{ $dueFlag === 'missed' ? __('tasks.missed') : __('tasks.overdue') }}</span>
                @endif

                <x-ui.form-select
                    chip
                    chip-icon="flag"
                    wire:model="taskPriority"
                    :label="__('tasks.priority')"
                    required
                    :chip-tones="[
                        TaskPriority::Low->value => 'text-secondary-light dark:text-ink-faint',
                        TaskPriority::Medium->value => 'text-status-warn',
                        TaskPriority::High->value => 'text-status-critical',
                    ]"
                    :data-test="$testPrefix.'-priority'"
                >
                    @foreach (TaskPriority::cases() as $priorityCase)
                        <option value="{{ $priorityCase->value }}">{{ $priorityCase->label() }}</option>
                    @endforeach
                </x-ui.form-select>

                {{-- Proof needed: when on, the task can only be marked done once a file is attached. --}}
                <label
                    class="form-chip form-chip-toggle"
                    title="{{ __('tasks.workflow.evidence_required_help') }}{{ $proofLocked ? ' · '.__('tasks.only_the_task_creator_can_change_proof') : '' }}"
                    @if ($proofLocked) data-disabled @endif
                >
                    <input type="checkbox" wire:model="evidenceRequired" class="sr-only" @disabled($proofLocked) data-test="{{ $testPrefix }}-proof-needed" />
                    <flux:icon.paper-clip variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                    <span>{{ __('tasks.show.proof_needed') }}</span>
                </label>

                <button
                    type="button"
                    class="form-chip form-chip-ghost"
                    x-on:click="more = ! more"
                    x-bind:aria-expanded="more ? 'true' : 'false'"
                    aria-controls="{{ $testPrefix }}-more"
                    data-test="{{ $testPrefix }}-more-toggle"
                >
                    <flux:icon.ellipsis-horizontal variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                    <span x-text="more ? @js(__('tasks.fewer_fields')) : @js(__('tasks.more_fields'))">{{ $showMore ? __('tasks.fewer_fields') : __('tasks.more_fields') }}</span>
                </button>
            </div>

            <div id="{{ $testPrefix }}-more" class="task-composer-chips" x-show="more" x-cloak @unless ($showMore) style="display: none" @endunless>
                @foreach ([['startDate', $startDate, __('common.start_date'), 'play'], ['completedDate', $completedDate, __('tasks.completed_date'), 'check-circle']] as [$dateField, $dateValue, $dateLabel, $dateIcon])
                    <label
                        class="form-chip form-chip-date"
                        x-data="{ filled: @js($dateValue !== '') }"
                        title="{{ $dateLabel }}"
                        @if ($errors->has($dateField)) data-invalid @endif
                    >
                        <flux:icon :name="$dateIcon" variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                        <span class="sr-only">{{ $dateLabel }}</span>
                        <span class="form-chip-placeholder" x-show="! filled" @if ($dateValue !== '') style="display: none" @endif aria-hidden="true">{{ $dateLabel }}</span>
                        <input
                            type="date"
                            wire:model="{{ $dateField }}"
                            value="{{ $dateValue }}"
                            x-bind:data-empty="filled ? null : ''"
                            @if ($dateValue === '') data-empty @endif
                            x-on:input="filled = $el.value !== ''"
                            x-on:change="filled = $el.value !== ''"
                            x-on:click="$el.showPicker?.()"
                            data-test="{{ $testPrefix }}-{{ Illuminate\Support\Str::kebab($dateField) }}"
                        />
                    </label>
                @endforeach

                <label class="form-chip" title="{{ __('tasks.progress_2') }}" @if ($errors->has('progressPercentage')) data-invalid @endif>
                    <flux:icon.chart-pie variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                    <span>{{ __('tasks.composer_progress') }}</span>
                    <input
                        type="number"
                        wire:model="progressPercentage"
                        min="0"
                        max="100"
                        required
                        class="form-chip-number"
                        aria-label="{{ __('tasks.progress_2') }}"
                        data-test="{{ $testPrefix }}-progress-input"
                    />
                    <span aria-hidden="true">%</span>
                </label>
            </div>

            @if ($chipErrors->isNotEmpty() || $notes->isNotEmpty())
                <div class="grid gap-1" aria-live="polite">
                    @foreach ($chipErrors as $chipError)
                        <p class="form-error mt-0!">
                            <flux:icon.exclamation-circle variant="micro" class="size-3.5 shrink-0" />
                            {{ $chipError }}
                        </p>
                    @endforeach
                    @foreach ($notes as $note)
                        <p class="form-help mt-0! flex items-center gap-1">
                            <flux:icon.lock-closed variant="micro" class="size-3 shrink-0" />
                            {{ $note }}
                        </p>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" :data-test="$saveTest">{{ $editing ? __('common.save_changes') : __('tasks.create_task') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>
