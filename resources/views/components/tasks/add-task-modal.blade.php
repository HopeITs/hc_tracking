@props([
    'testPrefix',
    'open' => true,
])

@use('App\Enums\TaskPriority')
@use('App\Enums\TaskStatus')

{{--
    The quick add-task form shared by the milestone, program, project and
    proposal pages, laid out like the tasks page's x-tasks.composer: "Related
    to" on top, a large task name and quiet description, then every property
    as a chip. Each page's component owns the same properties (newTaskTitle,
    newTaskDescription, newTaskStatus, newTaskPriority, newTaskAssignedTo,
    newTaskDueDate, newTaskTarget) and actions (addTask, closeAddTaskModal);
    `testPrefix` keeps each page's data-test hooks distinct. "Related to" is a
    "Goes to" picker when the page's record sits in a program
    (AddsQuickTask::quickTaskPlaces), else the record itself, read-only.
--}}
@php
    $livewire = $open ? $__env->shared('__livewire') : null;
    $places = $livewire?->quickTaskPlaces;
    $sourceLabel = $livewire !== null && $places === null ? $livewire->quickTaskSourceLabel : null;
    $dueDate = (string) data_get($livewire, 'newTaskDueDate', '');

    /* The chips carry no error line of their own; their messages gather under the row, in reading order. */
    $chipErrors = collect([
        'newTaskTarget' => __('tasks.goes_to'),
        'newTaskStatus' => __('common.status'),
        'newTaskAssignedTo' => __('tasks.assignees'),
        'newTaskAssignedTo.*' => __('tasks.assignees'),
        'newTaskDueDate' => __('tasks.due_date_2'),
        'newTaskPriority' => __('tasks.priority'),
    ])->map(fn (string $label, string $field): ?string => ($message = $errors->first($field)) !== '' ? $label.': '.$message : null)
        ->filter()->unique()->values();

    $titleError = $errors->first('newTaskTitle');
    $descriptionError = $errors->first('newTaskDescription');
@endphp
<x-ui.modal
    wire:model="showAddTaskModal"
    size="lg"
    :title="__('tasks.add_task')"
    submit="addTask"
    close="closeAddTaskModal"
    required-note
>
    @if ($open)
        <div class="task-composer" data-test="{{ $testPrefix }}-composer">
            {{-- Where the task is filed: the page's record, or a pick among the places in its program. --}}
            <div class="task-composer-related">
                <span class="task-composer-caption">
                    {{ __('tasks.source_type') }}
                    <span class="form-required" aria-hidden="true">*</span>
                </span>

                @if ($places !== null)
                    <x-ui.form-select
                        chip
                        chip-icon="link"
                        wire:model="newTaskTarget"
                        :label="__('tasks.goes_to')"
                        required
                        searchable
                        :search-label="__('tasks.move_search')"
                        class="min-w-0"
                        :data-test="$testPrefix.'-goes-to'"
                    >
                        <option value="program">{{ $places['program']['name'] }} · {{ __('tasks.move_program_itself') }}</option>
                        @foreach ($places['projects'] as $project)
                            <option value="project:{{ $project['id'] }}">{{ $project['name'] }}</option>
                            @foreach ($project['milestones'] as $milestone)
                                <option value="milestone:{{ $milestone['id'] }}">{{ $project['name'] }} › {{ $milestone['name'] }}</option>
                            @endforeach
                        @endforeach
                    </x-ui.form-select>
                @elseif ($sourceLabel !== null)
                    <span class="form-chip" data-disabled>
                        <flux:icon.squares-2x2 variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                        {{ $sourceLabel['type'] }}
                    </span>
                    <flux:icon.chevron-right variant="micro" class="size-3.5 shrink-0 text-secondary-light rtl:rotate-180 dark:text-ink-faint" aria-hidden="true" />
                    <span class="form-chip min-w-0" data-disabled data-test="{{ $testPrefix }}-related-to">
                        <flux:icon.link variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                        <span class="truncate">{{ $sourceLabel['name'] }}</span>
                    </span>
                @endif
            </div>

            {{-- The task itself: a large borderless name and a quiet description. Enter on the name saves. --}}
            <div class="task-composer-text">
                <label for="{{ $testPrefix }}-title" class="sr-only">{{ __('tasks.task_title') }}</label>
                <input
                    id="{{ $testPrefix }}-title"
                    type="text"
                    wire:model="newTaskTitle"
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
                    wire:model="newTaskDescription"
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
                    wire:model="newTaskStatus"
                    :label="__('common.status')"
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
                    wire:model="newTaskAssignedTo"
                    :label="__('tasks.assignees')"
                    :placeholder="__('tasks.assignees')"
                    employees
                    :data-test="$testPrefix.'-assignee-picker'"
                />

                <label
                    class="form-chip form-chip-date"
                    x-data="{ filled: @js($dueDate !== '') }"
                    title="{{ __('tasks.due_date_2') }}"
                    @if ($errors->has('newTaskDueDate')) data-invalid @endif
                >
                    <flux:icon.calendar variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                    <span class="sr-only">{{ __('tasks.due_date_2') }}</span>
                    <span class="form-chip-placeholder" x-show="! filled" @if ($dueDate !== '') style="display: none" @endif aria-hidden="true">{{ __('tasks.due_date_2') }}</span>
                    <input
                        type="date"
                        wire:model="newTaskDueDate"
                        value="{{ $dueDate }}"
                        x-bind:data-empty="filled ? null : ''"
                        @if ($dueDate === '') data-empty @endif
                        x-on:input="filled = $el.value !== ''"
                        x-on:change="filled = $el.value !== ''"
                        x-on:click="try { $el.showPicker() } catch (error) {}"
                        data-test="{{ $testPrefix }}-due-date"
                    />
                </label>

                <x-ui.form-select
                    chip
                    chip-icon="flag"
                    wire:model="newTaskPriority"
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
            </div>

            @if ($chipErrors->isNotEmpty())
                <div class="grid gap-1" aria-live="polite">
                    @foreach ($chipErrors as $chipError)
                        <p class="form-error mt-0!">
                            <flux:icon.exclamation-circle variant="micro" class="size-3.5 shrink-0" />
                            {{ $chipError }}
                        </p>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" :data-test="'save-'.$testPrefix.'-button'">{{ __('tasks.create_task') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>
