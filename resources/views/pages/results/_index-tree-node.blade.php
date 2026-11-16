{{-- One box of the logframe tree and, below it, its children (folded at outputs, so activities stay out of the way until asked for). --}}
@php
    use App\Enums\ResultType;

    $result = $node['result'];
    $measurement = $node['measurement'];
    $status = $measurement['status'];
    $children = $node['children'];
    $indicatorCount = count($measurement['rows']);
    $achieved = $measurement['achieved'];

    /* The shared ResultType identity (components-ui.md): mark dot and top accent bar. */
    $mark = match ($result->type) {
        ResultType::Impact => 'bg-secondary-dark ring-secondary-dark dark:bg-ink dark:ring-ink',
        ResultType::Outcome => 'bg-primary ring-primary',
        ResultType::Output => 'bg-board ring-secondary-light',
        ResultType::Activity => 'bg-transparent ring-secondary-medium dark:ring-ink-muted',
        ResultType::CrossCutting => 'bg-secondary-light ring-secondary-light dark:bg-ink-muted dark:ring-ink-muted',
    };
    $accent = match ($result->type) {
        ResultType::Impact => 'h-1 bg-primary',
        ResultType::Outcome => 'h-0.5 bg-primary',
        ResultType::Output, ResultType::Activity, ResultType::CrossCutting => 'h-0.5 bg-transparent',
    };

    /* Status word with an icon, never colour alone. */
    [$statusIcon, $statusClass] = match ($status) {
        'ontrack' => ['check-circle', 'text-status-good'],
        'behind' => ['arrow-trending-down', 'text-status-critical-text'],
        'waiting' => ['clock', 'text-status-warn'],
        'none' => ['exclamation-triangle', 'text-secondary-dark dark:text-ink'],
        default => ['minus-circle', 'text-secondary-medium dark:text-ink-muted'],
    };

    $startsOpen = $children !== [] && $result->type !== ResultType::Output;
@endphp

<li x-data="{ open: {{ $startsOpen ? 'true' : 'false' }} }" wire:key="tree-node-{{ $result->id }}" data-tree-node="{{ $result->id }}" data-result-type="{{ $result->type->value }}">
    <div class="lf-box" data-status="{{ $status }}">
        <button
            type="button"
            class="block w-full text-start"
            x-on:click="$wire.set('view', 'list').then(() => requestAnimationFrame(() => document.getElementById('framework-result-{{ $result->id }}')?.scrollIntoView({ behavior: 'smooth', block: 'center' })))"
            title="{{ __('results_framework.tree_open_in_list') }}"
            data-test="tree-box-{{ $result->id }}"
        >
            <span aria-hidden="true" class="block {{ $accent }}"></span>
            <span class="flex items-center gap-1.5 px-3 pt-2.5">
                <span aria-hidden="true" class="size-2 shrink-0 rounded-full ring-2 ring-inset {{ $mark }}"></span>
                <span class="type-label text-secondary-medium dark:text-ink-muted">{{ $result->type->label() }}</span>
            </span>
            <span class="lf-box-text block px-3 pt-1 text-sm font-semibold leading-snug text-secondary-dark dark:text-ink"><bdi>{{ $result->statement }}</bdi></span>
            <span class="mt-2 grid gap-0.5 border-t border-secondary-soft px-3 py-2 dark:border-line">
                <span class="inline-flex items-center gap-1 type-meta font-semibold {{ $statusClass }}">
                    <flux:icon :name="$statusIcon" variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                    {{ __('results_framework.tree_status_'.$status) }}
                </span>
                <span class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">
                    {{ trans_choice('results_framework.tree_indicators', $indicatorCount, ['count' => $indicatorCount]) }}@if ($achieved !== null) · <strong class="text-secondary-dark dark:text-ink">{{ number_format(min($achieved, 100), 0) }}%</strong>@endif
                </span>
            </span>
        </button>
    </div>

    @if ($children !== [])
        <button type="button" class="lf-fold" x-on:click="open = ! open" :aria-expanded="open" data-test="tree-fold-{{ $result->id }}">
            <flux:icon name="chevron-down" variant="micro" class="size-3.5 transition-transform" ::class="open ? '' : '-rotate-90 rtl:rotate-90'" aria-hidden="true" />
            <span x-show="! open">{{ trans_choice('results_framework.tree_show_children', count($children), ['count' => count($children)]) }}</span>
            <span x-show="open" x-cloak>{{ __('results_framework.tree_hide_children') }}</span>
        </button>
        <ul x-show="open" @if (! $startsOpen) x-cloak @endif>
            @foreach ($children as $child)
                @include('pages.results._index-tree-node', ['node' => $child])
            @endforeach
        </ul>
    @endif
</li>
