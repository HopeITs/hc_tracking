{{-- The related-tasks panel on ⚡show.blade.php: the subtask list and its add button. Shown on top-level tasks only. --}}
        <x-ui.panel :title="__('tasks.related_tasks')" :count="$this->subtasks->count()" :subtitle="__('tasks.subtasks_inherit_this_task_s_proposal_milestone_and_assign')">
            <x-slot:toolbar>
                @can('create', App\Models\Task::class)<x-ui.button tone="primary" size="sm" icon="plus" wire:click="openAddSubtaskModal" data-test="add-subtask-button">{{ __('tasks.add_subtask') }}</x-ui.button>@endcan
            </x-slot:toolbar>

            @if ($this->subtasks->isEmpty())
                <x-ui.empty-state icon="rectangle-stack" :heading="__('tasks.no_subtasks_yet')" :body="__('tasks.break_this_task_down_into_smaller_steps')">
                    <x-slot:action>
                        @can('create', App\Models\Task::class)<x-ui.button size="sm" tone="primary" icon="plus" wire:click="openAddSubtaskModal">{{ __('tasks.add_subtask') }}</x-ui.button>@endcan
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                <ul class="divide-y divide-secondary-soft dark:divide-line">
                    @foreach ($this->subtasks as $subtask)
                        @php $subtaskAssignees = $subtask->taskAssignees->pluck('employee')->filter(); @endphp
                        <li class="flex flex-wrap items-center justify-between gap-3 p-3.5" wire:key="subtask-{{ $subtask->id }}">
                            <a href="{{ route('tasks.show', $subtask) }}" wire:navigate class="min-w-0 flex-1 truncate text-sm font-semibold hover:underline">
                                {{ $subtask->title }}
                            </a>
                            <div class="flex shrink-0 items-center gap-3">
                                <span class="w-40 shrink-0 truncate text-xs text-secondary-medium dark:text-ink-muted">
                                    {{ $subtaskAssignees->isEmpty() ? __('common.not_assigned') : $subtaskAssignees->map->name->implode(', ') }}
                                </span>
                                <span class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted">
                                    {{ $subtask->due_date?->translatedFormat(__('formats.date')) ?? '—' }}
                                </span>
                                <x-ui.enum-badge :case="$subtask->status" />
                                <x-ui.enum-badge :case="$subtask->priority" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>
