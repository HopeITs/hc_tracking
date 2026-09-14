<?php

use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskUpdate;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.task')] class extends Component {
    #[Locked]
    public int $taskId;

    public string $comment = '';

    public function mount(Task $task): void
    {
        $this->taskId = $task->id;
    }

    #[Computed]
    public function task(): Task
    {
        return Task::query()
            ->select([
                'id',
                'milestone_id',
                'proposal_id',
                'title',
                'description',
                'assigned_to',
                'status',
                'priority',
                'start_date',
                'due_date',
                'completed_date',
                'progress_percentage',
                'created_by',
            ])
            ->with([
                'milestone:id,name,project_id',
                'milestone.project:id,name',
                'proposal:id,name',
                'assignee:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'creator:id,name',
            ])
            ->findOrFail($this->taskId);
    }

    /** @return Collection<int, TaskUpdate> */
    #[Computed]
    public function updates(): Collection
    {
        return TaskUpdate::query()
            ->select(['id', 'task_id', 'user_id', 'comment', 'date'])
            ->where('task_id', $this->taskId)
            ->with('user:id,name')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, TaskAttachment> */
    #[Computed]
    public function attachments(): Collection
    {
        return TaskAttachment::query()
            ->select(['id', 'task_id', 'path', 'type', 'date'])
            ->where('task_id', $this->taskId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Append a comment to the task's immutable progress log.
     */
    public function addUpdate(): void
    {
        $this->comment = trim($this->comment);

        $validated = $this->validate([
            'comment' => ['required', 'string', 'max:5000'],
        ], [
            'comment.required' => __('tasks.write_an_update_before_posting_it'),
            'comment.max' => __('tasks.updates_cannot_exceed_5_000_characters'),
        ]);

        TaskUpdate::query()->create([
            'task_id' => $this->taskId,
            'user_id' => (int) auth()->id(),
            'comment' => $validated['comment'],
            'date' => now(),
        ]);

        $this->comment = '';
        unset($this->updates);

        Flux::toast(variant: 'success', text: __('tasks.update_posted'));
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="task-detail-heading">
    <x-ui.detail-header
        id="task-detail-heading"
        :breadcrumbs="[
            ['label' => __('common.tasks'), 'url' => route('tasks.index')],
            ['label' => $this->task->title],
        ]"
        :title="$this->task->title"
        :subtitle="$this->task->proposal->name"
    >
        <x-slot:meta>
            @if ($this->task->milestone === null)
                <span class="italic">{{ __('tasks.no_milestone') }}</span>
            @else
                <a href="{{ route('projects.show', $this->task->milestone->project) }}" wire:navigate class="font-medium hover:underline">
                    {{ $this->task->milestone->name }} · {{ $this->task->milestone->project->name }}
                </a>
            @endif
            <span>{{ __('tasks.assigned_to_name', ['name' => $this->task->assignee?->name ?? __('common.not_assigned')]) }}</span>
            <span>{{ __('tasks.created_by_name', ['name' => $this->task->creator->name]) }}</span>
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.enum-badge :case="$this->task->status" />
                <x-ui.enum-badge :case="$this->task->priority" />
            </div>
        </x-slot:actions>
    </x-ui.detail-header>

    <div class="grid gap-3 @2xl/detail:grid-cols-4">
        <x-ui.stat-tile :label="__('tasks.progress')" :value="$this->task->progress_percentage.'%'" />
        <x-ui.stat-tile :label="__('common.start')" :value="$this->task->start_date?->translatedFormat(__('formats.date')) ?? '—'" />
        <x-ui.stat-tile :label="__('tasks.due')" :value="$this->task->due_date?->translatedFormat(__('formats.date')) ?? '—'" />
        <x-ui.stat-tile :label="__('common.completed')" :value="$this->task->completed_date?->translatedFormat(__('formats.date')) ?? '—'" />
    </div>

    @if ($this->task->description !== null)
        <x-ui.panel :title="__('common.description')">
            <p class="whitespace-pre-line p-3.5 text-sm text-secondary-medium dark:text-secondary-soft">{{ $this->task->description }}</p>
        </x-ui.panel>
    @endif

    <div class="grid gap-4 @4xl/detail:grid-cols-[minmax(0,1.6fr)_minmax(16rem,1fr)]">
        <x-ui.panel :title="__('tasks.updates')" :count="$this->updates->count()" :subtitle="__('tasks.an_append_only_log_posted_updates_cannot_be_edited_or_remove')">
            <div class="border-b border-secondary-soft p-3.5 dark:border-secondary-medium">
                <form wire:submit="addUpdate" class="flex flex-col gap-2">
                    <flux:textarea
                        wire:model="comment"
                        :label="__('tasks.post_an_update')"
                        rows="3"
                        maxlength="5000"
                        :placeholder="__('tasks.what_changed')"
                        class="border-secondary-light! bg-white! text-secondary-dark! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!"
                    />
                    <div class="flex justify-end">
                        <x-ui.button type="submit" tone="primary" size="sm" icon="paper-airplane" wire:loading.attr="disabled" wire:target="addUpdate" data-test="post-update-button">
                            <span wire:loading.remove wire:target="addUpdate">{{ __('tasks.post_update') }}</span>
                            <span wire:loading wire:target="addUpdate">{{ __('tasks.posting') }}</span>
                        </x-ui.button>
                    </div>
                </form>
            </div>

            @if ($this->updates->isEmpty())
                <x-ui.empty-state icon="chat-bubble-left-right" :heading="__('tasks.no_updates_yet')" :body="__('tasks.post_the_first_update_to_start_this_task_s_log')" />
            @else
                <ol class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->updates as $update)
                        <li class="flex flex-col gap-2 p-3.5" wire:key="task-update-{{ $update->id }}">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <x-ui.user-chip :user="$update->user" compact />
                                <time datetime="{{ $update->date->toIso8601String() }}" class="shrink-0 text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                                    {{ $update->date->translatedFormat(__('formats.datetime')) }}
                                </time>
                            </div>
                            <p class="whitespace-pre-line text-sm text-secondary-dark dark:text-white">{{ $update->comment }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-ui.panel>

        <x-ui.panel :title="__('tasks.attachments')" :count="$this->attachments->count()" :subtitle="__('common.file_records_only_the_files_themselves_are_not_stored_in_thi')">
            @if ($this->attachments->isEmpty())
                <x-ui.empty-state icon="paper-clip" :heading="__('common.no_files_recorded')" :body="__('tasks.attachment_records_for_this_task_will_appear_here')" />
            @else
                <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->attachments as $attachment)
                        {{-- Storage has no corresponding files, so metadata must never become a download link. --}}
                        <li class="flex items-center gap-3 p-3.5" wire:key="task-attachment-{{ $attachment->id }}">
                            <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-secondary-light text-[11px] font-semibold text-secondary-medium uppercase dark:text-secondary-soft">
                                {{ Str::upper($attachment->type->value) }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ basename($attachment->path) }}</p>
                                <p class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $attachment->date->translatedFormat(__('formats.date')) }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>
    </div>
</section>
