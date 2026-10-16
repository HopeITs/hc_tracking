{{--
    One indicator of a chain node (T16): library code + name, the inactive /
    not-linked / no-target badges, and the per-center targets on demand, each
    with its own achievement (PlanIndicator::percentAchieved(), approved
    reports only). Percentages are per target, never summed across centers.
--}}
@props(['row'])

@php
    $targets = $row['targets'];
    $figure = fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    $tone = fn (string $status): string => match ($status) {
        'achieved' => 'good',
        'in_progress' => 'warn',
        default => 'neutral',
    };
    $muted = 'text-secondary-medium dark:text-ink-muted';
@endphp

<li {{ $attributes->class('rounded-md border border-board-line bg-board px-3 py-2 dark:border-line') }} data-chain-indicator="{{ $row['indicator']->id }}" x-data="{ breakdown: false }">
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <flux:icon.chart-bar variant="micro" class="size-3.5 shrink-0 {{ $muted }}" aria-hidden="true" />
        @if ($row['code'] !== null)
            <bdi dir="ltr" class="font-mono text-xs font-semibold tabular-nums {{ $muted }}">{{ $row['code'] }}</bdi>
        @endif
        <span class="min-w-0 text-sm font-medium" dir="auto">{{ $row['name'] }}</span>

        @if (! $row['linked'])
            <span class="rounded-full border border-secondary-light px-2 py-0.5 text-xs font-semibold {{ $muted }} dark:border-line">{{ __('targets.not_linked_to_library') }}</span>
        @elseif ($row['inactive'])
            <span class="rounded-full border border-secondary-light px-2 py-0.5 text-xs font-semibold {{ $muted }} dark:border-line">{{ __('results_framework.chain_entry_inactive') }}</span>
        @endif

        @foreach ($row['gaps'] as $gap)
            <x-ui.logframe-gap :gap="$gap" />
        @endforeach

        @if ($targets !== [])
            <button
                type="button"
                x-on:click="breakdown = ! breakdown"
                x-bind:aria-expanded="breakdown.toString()"
                aria-controls="chain-targets-{{ $row['indicator']->id }}"
                class="ms-auto inline-flex cursor-pointer items-center gap-1 rounded-sm type-meta font-semibold text-secondary-dark hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary print:hidden dark:text-ink dark:hover:text-brand-text"
            >
                {{ trans_choice('results_framework.chain_count_targets', count($targets), ['count' => count($targets)]) }}
                <flux:icon.chevron-down variant="micro" class="size-3.5 transition-transform duration-200" x-bind:class="breakdown ? 'rotate-180' : ''" aria-hidden="true" />
            </button>
        @endif
    </div>

    @if ($targets !== [])
        <ul id="chain-targets-{{ $row['indicator']->id }}" x-show="breakdown" x-collapse x-cloak class="mt-2 divide-y divide-board-line border-t border-board-line print:block! dark:divide-line dark:border-line" aria-label="{{ __('results_framework.chain_per_center') }}">
            @foreach ($targets as $target)
                <li data-chain-target="{{ $target['id'] }}" data-percent="{{ $target['percent'] === null ? '' : (int) round($target['percent']) }}" class="grid gap-1.5 py-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,14rem)] sm:items-center sm:gap-4" wire:key="chain-target-{{ $target['id'] }}">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold" dir="auto">{{ $target['center'] }}</p>
                        @if ($target['source'] !== null)
                            <p class="type-meta {{ $muted }}" dir="auto">{{ $target['source'] }}</p>
                        @endif
                        @foreach ($target['issues'] as $issue)
                            <p class="mt-0.5 inline-flex items-center gap-1 type-meta text-status-critical" data-chain-issue>
                                <flux:icon.exclamation-circle variant="micro" class="size-3.5" aria-hidden="true" />{{ __($issue) }}
                            </p>
                        @endforeach
                    </div>
                    <div class="min-w-0">
                        <p class="flex items-baseline justify-between gap-2 text-xs">
                            <span dir="ltr" class="font-semibold tabular-nums">{{ $figure($target['achieved']) }} / {{ $figure($target['target']) }}</span>
                            <span dir="ltr" class="font-bold tabular-nums">{{ $target['percent'] === null ? '—' : (int) round($target['percent']).'%' }}</span>
                        </p>
                        <x-ui.progress-bar class="mt-1" :value="$target['percent']" :tone="$tone($target['status'])" :label="__('results_framework.chain_achievement_for', ['center' => $target['center']])" label-hidden />
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</li>
