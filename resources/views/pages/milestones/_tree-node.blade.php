@php
    /** @var \App\Models\Milestone $milestone */
    /** @var int $depth */
@endphp

<article data-tree-depth="{{ $depth }}" wire:key="milestone-node-{{ $milestone->id }}" class="flex flex-col gap-3 px-3.5 py-3" style="padding-inline-start: {{ 0.875 + ($depth * 1.25) }}rem">
    <div class="flex flex-col gap-2 @lg/directory:flex-row @lg/directory:items-start">
        <div class="flex min-w-0 flex-1 items-start gap-2.5">
            @if ($depth > 0)
                <span aria-hidden="true" class="mt-0.5 shrink-0 text-secondary-light">└</span>
            @endif

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="min-w-0 font-semibold text-secondary-dark dark:text-white"><span class="tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $milestone->sequence }}.</span> {{ $milestone->name }}</p>
                    <x-ui.enum-badge :case="$milestone->status" />
                </div>
                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-secondary-medium dark:text-secondary-soft">
                    <span>{{ __('Planned :date', ['date' => $milestone->planned_date->format('M j, Y')]) }}</span>
                    <span>{{ __('Weight :weight%', ['weight' => $milestone->weight_percentage]) }}</span>
                    <x-ui.user-chip :user="$milestone->responsibleUser" compact />
                </div>
                @if ($milestone->description !== null)
                    <p class="mt-2 text-sm leading-5 text-secondary-medium dark:text-secondary-soft">{{ $milestone->description }}</p>
                @endif
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-1 self-end @lg/directory:self-start">
            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! touch-manipulation @sm/directory:size-9!" wire:click="openEditModal({{ $milestone->id }})" :aria-label="__('Edit :name', ['name' => $milestone->name])" />
            @if ($milestone->children_count === 0 && $milestone->tasks_count === 0)
                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-11! touch-manipulation @sm/directory:size-9!" wire:click="confirmDelete({{ $milestone->id }})" :aria-label="__('Delete :name', ['name' => $milestone->name])" />
            @else
                <span class="inline-flex size-11 items-center justify-center text-secondary-light @sm/directory:size-9" aria-label="{{ __('Delete unavailable for :name', ['name' => $milestone->name]) }}"><flux:icon.lock-closed class="size-4" /></span>
            @endif
        </div>
    </div>

    <details class="rounded-md border border-secondary-soft bg-white dark:border-secondary-medium dark:bg-secondary-dark">
        <summary class="cursor-pointer px-3 py-2 text-xs font-semibold text-secondary-medium marker:text-secondary-light dark:text-secondary-soft">
            {{ trans_choice('{0} Files|{1} Files · :count record|[2,*] Files · :count records', $milestone->media->count(), ['count' => $milestone->media->count()]) }}
        </summary>
        @if ($milestone->media->isEmpty())
            <p class="border-t border-secondary-soft px-3 py-2 text-xs italic text-secondary-medium dark:border-secondary-medium dark:text-secondary-soft">{{ __('No files recorded') }}</p>
        @else
            <ul class="divide-y divide-secondary-soft border-t border-secondary-soft dark:divide-secondary-medium dark:border-secondary-medium">
                @foreach ($milestone->media as $media)
                    <li class="flex items-center gap-3 px-3 py-2.5" wire:key="milestone-file-{{ $media->id }}">
                        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-secondary-light text-[11px] font-semibold text-secondary-medium uppercase dark:text-secondary-soft">{{ Str::upper($media->type->value) }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ $media->original_name }}</p>
                            <p class="flex flex-wrap items-center gap-x-2 text-xs text-secondary-medium dark:text-secondary-soft">
                                <span>{{ Str::title($media->collection->value) }}</span>
                                <span aria-hidden="true">·</span>
                                <time datetime="{{ $media->uploaded_at?->toDateString() }}">{{ $media->uploaded_at?->format('M j, Y') ?? '—' }}</time>
                            </p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </details>
</article>

@foreach ($milestone->childrenRecursive as $childMilestone)
    @include('pages.milestones._tree-node', ['milestone' => $childMilestone, 'depth' => $depth + 1])
@endforeach
