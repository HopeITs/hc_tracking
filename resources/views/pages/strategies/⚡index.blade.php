<?php

use App\Models\Strategy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Strategies')] class extends Component {
    /**
     * @return array{
     *     strategies: Collection<int, array{id: int, name: string, start_year: int, end_year: int, duration_years: int, status: string}>,
     *     total: int,
     *     active_count: int,
     *     current_year: int,
     *     first_year: int|null,
     *     last_year: int|null
     * }
     */
    #[Computed]
    public function portfolio(): array
    {
        $currentYear = now()->year;

        $strategies = Strategy::query()
            ->select(['id', 'name', 'start_year', 'end_year'])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(static function (Strategy $strategy) use ($currentYear): array {
                $status = match (true) {
                    $currentYear < $strategy->start_year => 'upcoming',
                    $currentYear > $strategy->end_year => 'completed',
                    default => 'active',
                };

                return [
                    'id' => $strategy->id,
                    'name' => $strategy->name,
                    'start_year' => $strategy->start_year,
                    'end_year' => $strategy->end_year,
                    'duration_years' => $strategy->end_year - $strategy->start_year + 1,
                    'status' => $status,
                ];
            });

        $orderedStrategies = $strategies
            ->where('status', 'active')
            ->sortByDesc('start_year')
            ->concat($strategies->where('status', 'upcoming')->sortBy('start_year'))
            ->concat($strategies->where('status', 'completed')->sortByDesc('end_year'))
            ->values();

        return [
            'strategies' => $orderedStrategies,
            'total' => $strategies->count(),
            'active_count' => $strategies->where('status', 'active')->count(),
            'current_year' => $currentYear,
            'first_year' => $strategies->isEmpty() ? null : (int) $strategies->min('start_year'),
            'last_year' => $strategies->isEmpty() ? null : (int) $strategies->max('end_year'),
        ];
    }
};
?>

@php($portfolio = $this->portfolio)

<section
    class="@container/strategies flex min-h-full w-full flex-1 flex-col gap-5 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white"
    aria-labelledby="strategies-heading"
>
    <header class="flex flex-col gap-3 border-b border-secondary-soft pb-5 dark:border-secondary-medium @3xl/strategies:flex-row @3xl/strategies:items-end @3xl/strategies:justify-between">
        <div class="max-w-3xl">
            <div class="flex items-center gap-2.5">
                <flux:heading id="strategies-heading" size="xl">{{ __('Strategies') }}</flux:heading>
                <span class="inline-flex min-w-7 items-center justify-center rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
                    {{ $portfolio['total'] }}
                </span>
            </div>
            <p class="mt-1 text-sm leading-6 text-secondary-medium dark:text-secondary-soft">
                {{ __('A complete view of the strategic planning cycles that guide programme delivery.') }}
            </p>
        </div>

        <div class="inline-flex w-fit items-center gap-2 rounded-md border border-secondary-soft px-3 py-2 text-xs font-semibold text-secondary-medium dark:border-secondary-medium dark:text-secondary-soft">
            <flux:icon.calendar class="size-4" />
            {{ __('Planning year :year', ['year' => $portfolio['current_year']]) }}
        </div>
    </header>

    <div class="grid grid-cols-2 gap-3 @3xl/strategies:grid-cols-4" aria-label="{{ __('Strategy portfolio summary') }}">
        <article class="rounded-lg border border-secondary-soft bg-white p-4 dark:border-secondary-medium dark:bg-secondary-dark">
            <p class="text-xs font-semibold tracking-wide text-secondary-medium uppercase dark:text-secondary-soft">{{ __('Strategic periods') }}</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-secondary-dark dark:text-white">{{ $portfolio['total'] }}</p>
            <p class="mt-1 text-xs text-secondary-medium dark:text-secondary-soft">{{ __('Complete portfolio') }}</p>
        </article>

        <article class="rounded-lg border border-secondary-soft bg-white p-4 dark:border-secondary-medium dark:bg-secondary-dark">
            <p class="text-xs font-semibold tracking-wide text-secondary-medium uppercase dark:text-secondary-soft">{{ __('Active now') }}</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-primary">{{ $portfolio['active_count'] }}</p>
            <p class="mt-1 text-xs text-secondary-medium dark:text-secondary-soft">
                {{ trans_choice(':count current period|:count current periods', $portfolio['active_count'], ['count' => $portfolio['active_count']]) }}
            </p>
        </article>

        <article class="rounded-lg border border-secondary-soft bg-white p-4 dark:border-secondary-medium dark:bg-secondary-dark">
            <p class="text-xs font-semibold tracking-wide text-secondary-medium uppercase dark:text-secondary-soft">{{ __('First period') }}</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-secondary-dark dark:text-white">{{ $portfolio['first_year'] ?? '—' }}</p>
            <p class="mt-1 text-xs text-secondary-medium dark:text-secondary-soft">{{ __('Portfolio starting year') }}</p>
        </article>

        <article class="rounded-lg border border-secondary-soft bg-white p-4 dark:border-secondary-medium dark:bg-secondary-dark">
            <p class="text-xs font-semibold tracking-wide text-secondary-medium uppercase dark:text-secondary-soft">{{ __('Planning horizon') }}</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-secondary-dark dark:text-white">{{ $portfolio['last_year'] ?? '—' }}</p>
            <p class="mt-1 text-xs text-secondary-medium dark:text-secondary-soft">{{ __('Latest ending year') }}</p>
        </article>
    </div>

    <div class="flex flex-1 flex-col gap-3">
        <div class="flex flex-col gap-1 @xl/strategies:flex-row @xl/strategies:items-end @xl/strategies:justify-between">
            <div>
                <flux:heading size="lg">{{ __('Strategic planning cycles') }}</flux:heading>
                <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('Active periods are shown first, followed by upcoming and completed cycles.') }}</p>
            </div>

            @if ($portfolio['total'] > 0 && $portfolio['active_count'] === 0)
                <div class="mt-2 inline-flex w-fit items-center gap-2 rounded-md border border-primary/40 bg-primary/5 px-3 py-2 text-xs font-semibold text-primary @xl/strategies:mt-0" role="status">
                    <flux:icon.clock class="size-4" />
                    {{ __('No strategy currently covers :year.', ['year' => $portfolio['current_year']]) }}
                </div>
            @endif
        </div>

        @if ($portfolio['strategies']->isEmpty())
            <div class="flex min-h-64 flex-1 flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-secondary-light px-6 py-12 text-center dark:border-secondary-medium">
                <div class="flex size-12 items-center justify-center rounded-lg bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white">
                    <flux:icon.flag class="size-5" />
                </div>
                <div class="max-w-sm">
                    <flux:heading size="base">{{ __('No strategic periods yet') }}</flux:heading>
                    <p class="mt-1 text-sm text-secondary-medium dark:text-secondary-soft">{{ __('Strategy records will appear here once planning cycles are defined.') }}</p>
                </div>
            </div>
        @else
            <ol class="grid gap-3 @3xl/strategies:grid-cols-2">
                @foreach ($portfolio['strategies'] as $strategy)
                    <li wire:key="strategy-period-{{ $strategy['id'] }}">
                        <article
                            @class([
                                'flex h-full flex-col gap-5 rounded-lg border bg-white p-4 transition-colors dark:bg-secondary-dark @xl/strategies:p-5',
                                'border-primary ring-1 ring-primary/20' => $strategy['status'] === 'active',
                                'border-secondary-light dark:border-secondary-light' => $strategy['status'] === 'upcoming',
                                'border-secondary-soft dark:border-secondary-medium' => $strategy['status'] === 'completed',
                            ])
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex min-w-0 items-start gap-3">
                                    <div @class([
                                        'flex size-10 shrink-0 items-center justify-center rounded-lg',
                                        'bg-primary text-white' => $strategy['status'] === 'active',
                                        'bg-secondary-dark text-white dark:bg-white dark:text-secondary-dark' => $strategy['status'] === 'upcoming',
                                        'bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white' => $strategy['status'] === 'completed',
                                    ])>
                                        <flux:icon.flag class="size-4.5" />
                                    </div>

                                    <div class="min-w-0">
                                        <h3 class="text-base font-semibold leading-6 text-secondary-dark dark:text-white">
                                            <a href="{{ route('strategies.show', $strategy['id']) }}" wire:navigate class="truncate font-semibold hover:underline">{{ $strategy['name'] }}</a>
                                        </h3>
                                        <p class="mt-0.5 text-xs text-secondary-medium dark:text-secondary-soft">
                                            {{ __(':count-year strategic period', ['count' => $strategy['duration_years']]) }}
                                        </p>
                                    </div>
                                </div>

                                <span @class([
                                    'inline-flex shrink-0 items-center rounded-md px-2.5 py-1 text-xs font-semibold',
                                    'bg-primary text-white' => $strategy['status'] === 'active',
                                    'bg-secondary-dark text-white dark:bg-white dark:text-secondary-dark' => $strategy['status'] === 'upcoming',
                                    'bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white' => $strategy['status'] === 'completed',
                                ])>
                                    {{ match ($strategy['status']) {
                                        'active' => __('Active'),
                                        'upcoming' => __('Upcoming'),
                                        default => __('Completed'),
                                    } }}
                                </span>
                            </div>

                            <div class="mt-auto grid grid-cols-[auto_1fr_auto] items-center gap-3" aria-label="{{ __('Period :start to :end', ['start' => $strategy['start_year'], 'end' => $strategy['end_year']]) }}">
                                <div>
                                    <p class="text-[11px] font-semibold tracking-wide text-secondary-medium uppercase dark:text-secondary-soft">{{ __('Start') }}</p>
                                    <p class="mt-0.5 text-lg font-semibold tabular-nums text-secondary-dark dark:text-white">{{ $strategy['start_year'] }}</p>
                                </div>

                                <div class="flex items-center" aria-hidden="true">
                                    <span @class([
                                        'size-2.5 rounded-full',
                                        'bg-primary' => $strategy['status'] === 'active',
                                        'bg-secondary-dark dark:bg-white' => $strategy['status'] === 'upcoming',
                                        'bg-secondary-light' => $strategy['status'] === 'completed',
                                    ])></span>
                                    <span @class([
                                        'h-px flex-1',
                                        'bg-primary' => $strategy['status'] === 'active',
                                        'bg-secondary-dark dark:bg-white' => $strategy['status'] === 'upcoming',
                                        'bg-secondary-light' => $strategy['status'] === 'completed',
                                    ])></span>
                                    <span @class([
                                        'size-2.5 rounded-full',
                                        'bg-primary' => $strategy['status'] === 'active',
                                        'bg-secondary-dark dark:bg-white' => $strategy['status'] === 'upcoming',
                                        'bg-secondary-light' => $strategy['status'] === 'completed',
                                    ])></span>
                                </div>

                                <div class="text-end">
                                    <p class="text-[11px] font-semibold tracking-wide text-secondary-medium uppercase dark:text-secondary-soft">{{ __('End') }}</p>
                                    <p class="mt-0.5 text-lg font-semibold tabular-nums text-secondary-dark dark:text-white">{{ $strategy['end_year'] }}</p>
                                </div>
                            </div>
                        </article>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</section>
