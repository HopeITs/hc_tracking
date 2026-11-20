{{--
    The program logframes, one folded card per program. A card opens one level at a time:
    the program shows its impacts, each impact folded until clicked, and so on down.
    A search opens every card and branch so the matches are in view.
--}}
@php
    $searching = trim($search) !== '';
@endphp
<div class="space-y-3" data-test="program-logframes">
    @foreach ($groups as $programName => $nodes)
        @php
            $groupProgram = collect($nodes)->map(fn (array $node) => $node['result']->program)->filter()->first();
            $groupKey = $groupProgram?->id ?? 'none';
            $freeActivityCount = collect($nodes)->filter(fn (array $node): bool => $node['result']->type === \App\Enums\ResultType::Activity)->count();
            $canAddFreeActivity = auth()->user()->can('results.create');
            $freeActivityHeadingShown = false;
        @endphp
        <section
            class="overflow-hidden rounded-panel border border-panel-border bg-white shadow-card dark:bg-surface-1 dark:shadow-raised"
            x-data="{ open: @js($searching) }"
            wire:key="program-logframe-{{ $groupKey }}-{{ $searching ? 'search' : 'browse' }}"
            data-test="program-logframe-{{ $groupKey }}"
        >
            <button
                type="button"
                class="flex w-full items-center gap-3 px-4 py-4 text-start hover:bg-ground focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-secondary-medium"
                x-on:click="open = ! open"
                x-bind:aria-expanded="open.toString()"
                aria-controls="program-logframe-body-{{ $groupKey }}"
                x-bind:title="open ? @js(__('results_framework.hide_logframe')) : @js(__('results_framework.open_logframe'))"
                data-test="program-logframe-toggle-{{ $groupKey }}"
            >
                <span aria-hidden="true" class="h-6 w-1 shrink-0 rounded-full bg-primary"></span>
                <span class="min-w-0 flex-1 truncate text-base font-bold text-secondary-dark dark:text-ink" dir="auto">{{ $programName }}</span>
                <flux:icon.chevron-down variant="micro" class="size-4 shrink-0 text-secondary-medium transition-transform duration-200 dark:text-ink-muted" x-bind:class="open ? '' : '-rotate-90 rtl:rotate-90'" aria-hidden="true" />
            </button>

            <div id="program-logframe-body-{{ $groupKey }}" x-show="open" x-collapse @if (! $searching) x-cloak @endif class="space-y-5 border-t border-panel-border bg-ground px-3 py-5 @2xl/directory:px-5 @2xl/directory:py-6">
                @if ($groupProgram !== null)
                    <a href="{{ route('programs.show', $groupProgram) }}" wire:navigate class="type-meta font-semibold text-secondary-medium hover:text-primary hover:underline dark:text-ink-muted dark:hover:text-brand-text">
                        {{ __('common.view_name', ['name' => $groupProgram->name]) }}
                    </a>
                @endif

                @foreach ($nodes as $node)
                    {{-- Free activities (no statement above them) follow the chain under their own heading. --}}
                    @if ($node['result']->type === \App\Enums\ResultType::Activity && ! $freeActivityHeadingShown)
                        @php $freeActivityHeadingShown = true; @endphp
                        <h3 class="type-label text-secondary-medium dark:text-ink-muted" data-test="free-activities-heading">{{ __('results_framework.free_activities') }}</h3>
                    @endif
                    <x-ui.framework-node :node="$node" :depth="0" :order="$loop->index" :collapsed="! $searching" wire:key="framework-root-{{ $node['result']->id }}-{{ $searching ? 'search' : 'browse' }}" />
                @endforeach

                @if ($canAddFreeActivity)
                    <div class="flex flex-wrap items-center gap-2 border-t border-dashed border-secondary-soft pt-4 dark:border-line">
                        @if ($freeActivityCount === 0)
                            <span class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('results_framework.free_activities_empty') }}</span>
                        @endif
                        <x-ui.button tone="ghost" size="sm" icon="plus" class="ms-auto" wire:click="openCreateActivity({{ $groupProgram?->id ?? 'null' }})" data-test="add-free-activity-{{ $groupKey }}">{{ __('results_framework.add_free_activity') }}</x-ui.button>
                    </div>
                @endif
            </div>
        </section>
    @endforeach
</div>
