@props(['nodes', 'depth' => 0, 'label', 'badge' => null, 'subtitle' => null])

@foreach ($nodes as $node)
    <div
        data-tree-depth="{{ $depth }}"
        wire:key="tree-node-{{ $node->getKey() }}"
        class="flex items-center gap-2.5 border-b border-secondary-soft px-3.5 py-2.5 last:border-b-0 dark:border-line"
        style="padding-inline-start: {{ 0.875 + ($depth * 1.25) }}rem"
    >
        @if ($depth > 0)
            <span aria-hidden="true" class="text-secondary-light">└</span>
        @endif

        <div class="min-w-0 flex-1">
            <p class="truncate text-sm text-secondary-dark dark:text-ink">{{ $label($node) }}</p>
            @if ($subtitle !== null && $subtitle($node) !== null)
                <p class="truncate type-meta text-secondary-medium dark:text-ink-muted">{{ $subtitle($node) }}</p>
            @endif
        </div>

        @if ($badge !== null)
            <x-ui.enum-badge :case="$badge($node)" />
        @endif
    </div>

    @if ($node->childrenRecursive->isNotEmpty())
        <x-ui.tree-rows :nodes="$node->childrenRecursive" :depth="$depth + 1" :label="$label" :badge="$badge" :subtitle="$subtitle" />
    @endif
@endforeach
