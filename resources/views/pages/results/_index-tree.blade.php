{{-- The same statements as the list, drawn as a top-down logframe diagram: one tree per program or proposal framework. Shown when the page's view is tree. --}}
<div class="space-y-5" data-test="results-tree">
    <p class="flex flex-wrap items-center gap-x-4 gap-y-1 type-meta text-secondary-medium dark:text-ink-muted">
        <span>{{ __('results_framework.tree_hint') }}</span>
        <span class="inline-flex items-center gap-1.5"><flux:icon name="check-circle" variant="micro" class="size-3.5 text-status-good" aria-hidden="true" />{{ __('results_framework.tree_status_ontrack') }}</span>
        <span class="inline-flex items-center gap-1.5"><flux:icon name="arrow-trending-down" variant="micro" class="size-3.5 text-status-critical" aria-hidden="true" />{{ __('results_framework.tree_status_behind') }}</span>
        <span class="inline-flex items-center gap-1.5"><flux:icon name="clock" variant="micro" class="size-3.5 text-status-warn" aria-hidden="true" />{{ __('results_framework.tree_status_waiting') }}</span>
        <span class="inline-flex items-center gap-1.5"><flux:icon name="exclamation-triangle" variant="micro" class="size-3.5" aria-hidden="true" />{{ __('results_framework.tree_status_none') }}</span>
    </p>

    @foreach ($this->groupedRoots as $programName => $nodes)
        @php
            $groupStatements = collect($nodes)->sum(fn (array $node): int => $node['rollup']['statements']);
        @endphp
        <x-ui.panel :title="$programName" :count="$groupStatements" wire:key="result-tree-{{ $loop->index }}-{{ Str::slug($programName) }}">
            <div class="lf-tree-scroll bg-ground" data-test="results-tree-group">
                <ul class="lf-tree">
                    @foreach ($nodes as $node)
                        @include('pages.results._index-tree-node', ['node' => $node])
                    @endforeach
                </ul>
            </div>
        </x-ui.panel>
    @endforeach
</div>
