@props(['result', 'depth'])

@php
    use App\Enums\ResultType;

    $children = $result->childrenRecursive;
    $isApex = $depth === 0;
    $indicatorCount = (int) $result->indicators_count;

    $surface = match ($result->type) {
        ResultType::Impact => 'border-secondary-dark bg-board text-secondary-dark shadow-[0_18px_40px_-24px_rgb(0_0_0/0.35)] dark:border-field-line dark:text-ink',
        ResultType::Outcome => 'border-board-line bg-board text-secondary-dark shadow-[0_10px_28px_-22px_rgb(0_0_0/0.45)] dark:text-ink',
        ResultType::Output, ResultType::Activity, ResultType::CrossCutting => 'border-board-line bg-board-head text-secondary-dark dark:text-ink',
    };
    $mark = match ($result->type) {
        ResultType::Impact => 'bg-secondary-dark ring-secondary-dark dark:bg-ink dark:ring-ink',
        ResultType::Outcome => 'bg-primary ring-primary',
        ResultType::Output => 'bg-board ring-secondary-light',
        ResultType::Activity => 'bg-transparent ring-secondary-medium dark:ring-ink-muted',
        ResultType::CrossCutting => 'bg-secondary-light ring-secondary-light dark:bg-ink-muted dark:ring-ink-muted',
    };
    $muted = 'text-secondary-medium dark:text-ink-muted';
    $statementSize = match (true) {
        $isApex => 'text-lg @xl/detail:text-xl @3xl/detail:text-2xl font-bold leading-snug',
        in_array($result->type, [ResultType::Output, ResultType::Activity, ResultType::CrossCutting], true) => 'text-sm font-medium leading-relaxed',
        default => 'text-base font-semibold leading-snug',
    };
@endphp

<div data-tree-depth="{{ $depth }}" data-result-type="{{ $result->type->value }}" x-data="{ open: true }">
    <article {{ $attributes->class(['relative overflow-hidden rounded-md border', $surface, $isApex ? 'px-5 py-5 text-center @xl/detail:px-6' : 'px-4 py-4']) }}>
        @if ($result->type === ResultType::Outcome)
            <span aria-hidden="true" class="absolute inset-x-0 top-0 h-0.5 bg-primary"></span>
        @elseif ($result->type === ResultType::Impact)
            <span aria-hidden="true" class="absolute inset-x-0 top-0 h-1 bg-primary"></span>
        @endif

        <div @class(['flex flex-wrap items-center gap-x-3 gap-y-1', 'justify-center' => $isApex])>
            <span class="inline-flex items-center gap-1.5">
                <span aria-hidden="true" class="size-2 rounded-full ring-2 ring-inset {{ $mark }}"></span>
                <span class="type-label">{{ $result->type->label() }}</span>
            </span>
            <span class="type-meta {{ $muted }}">{{ __('programs.logframe_checkout', ['period' => $result->checkout_period->label()]) }}</span>
        </div>

        <p @class(['mt-2 text-pretty', $statementSize, 'mx-auto max-w-[60ch] text-balance' => $isApex])>{{ $result->statement }}</p>

        <div @class(['mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 type-meta', 'justify-center' => $isApex])>
            <span @class(['inline-flex items-center gap-1', $muted => $indicatorCount > 0, 'text-status-warn' => $indicatorCount === 0])>
                <flux:icon :name="$indicatorCount > 0 ? 'chart-bar' : 'exclamation-triangle'" variant="micro" class="size-3.5" aria-hidden="true" />
                {{ trans_choice('programs.logframe_count_indicators', $indicatorCount, ['count' => $indicatorCount]) }}
            </span>

            @if (! $isApex && $children->isNotEmpty())
                <button
                    type="button"
                    x-on:click="open = ! open"
                    x-bind:aria-expanded="open.toString()"
                    aria-controls="logframe-children-{{ $result->id }}"
                    title="{{ __('programs.logframe_toggle_contributing') }}"
                    class="inline-flex cursor-pointer items-center gap-1 rounded-sm font-semibold text-secondary-dark hover:text-primary dark:hover:text-brand-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-ink"
                >
                    {{ trans_choice('programs.logframe_count_contributing', $children->count(), ['count' => $children->count()]) }}
                    <flux:icon.chevron-down variant="micro" class="size-3.5 transition-transform duration-200" x-bind:class="open ? '' : '-rotate-90 rtl:rotate-90'" aria-hidden="true" />
                </button>
            @elseif ($isApex)
                <span class="inline-flex items-center gap-1 {{ $muted }}">
                    <flux:icon.share variant="micro" class="size-3.5 rotate-90" aria-hidden="true" />
                    {{ trans_choice('programs.logframe_count_contributing', $children->count(), ['count' => $children->count()]) }}
                </span>
            @endif
        </div>
    </article>

    @if (! $isApex && $children->isNotEmpty())
        <div id="logframe-children-{{ $result->id }}" x-show="open" x-collapse>
            <x-ui.logframe-branch :nodes="$children" :depth="$depth + 1" />
        </div>
    @endif
</div>
