@use('Carbon\CarbonImmutable')

    {{-- Next 30 days and recent activity --}}
    <div class="grid grid-cols-1 gap-4 @5xl/dashboard:grid-cols-12">
        <x-ui.panel :title="__('dashboard.coming_up')" :subtitle="__('dashboard.coming_up_hint')" class="min-w-0 @5xl/dashboard:col-span-7" data-upcoming>
            @if ($this->upcoming->isEmpty())
                <x-ui.empty-state icon="calendar-days" :heading="__('dashboard.nothing_scheduled')" :body="__('dashboard.nothing_scheduled_body')" class="min-h-56" />
            @else
                <ul class="divide-y divide-secondary-soft dark:divide-line" role="list">
                    @foreach ($this->upcoming as $item)
                        @php $daysAway = (int) CarbonImmutable::today()->diffInDays($item['date']); @endphp
                        <li wire:key="upcoming-{{ $item['key'] }}">
                            <a href="{{ $item['href'] }}" wire:navigate class="group flex items-center gap-4 px-4 py-2.5 transition-colors duration-150 hover:bg-board-head focus-visible:bg-board-head focus-visible:outline-none">
                                <span class="flex w-11 shrink-0 flex-col items-center rounded-lg border border-secondary-soft py-1 leading-none dark:border-line">
                                    <span class="text-[10px] font-semibold text-secondary-medium uppercase dark:text-ink-muted">{{ $item['date']->translatedFormat(__('formats.month_short')) }}</span>
                                    <span class="mt-0.5 text-base font-bold tabular-nums text-secondary-dark dark:text-ink">{{ $item['date']->format('j') }}</span>
                                </span>
                                <span class="{{ $iconTone($item['tone']) }} flex size-7 shrink-0 items-center justify-center rounded-md">
                                    <flux:icon :name="$item['icon']" variant="micro" class="size-3.5" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-secondary-dark dark:text-ink">{{ $item['title'] }}</span>
                                    <span class="block truncate text-xs text-secondary-medium dark:text-ink-muted">{{ $item['context'] }}</span>
                                </span>
                                <span class="shrink-0 text-xs font-semibold tabular-nums text-secondary-medium dark:text-ink-muted">
                                    {{ $daysAway === 0 ? __('dashboard.today') : trans_choice('dashboard.in_days', $daysAway, ['count' => $daysAway]) }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>

        <x-ui.panel :title="__('dashboard.recent_activity')" :subtitle="__('dashboard.recent_activity_hint')" class="min-w-0 @5xl/dashboard:col-span-5" data-activity>
            @if ($this->activity->isEmpty())
                <x-ui.empty-state icon="bolt" :heading="__('dashboard.no_activity_yet')" :body="__('dashboard.no_activity_yet_body')" class="min-h-56" />
            @else
                <ol class="relative px-4 py-3" role="list">
                    @foreach ($this->activity as $event)
                        <li class="relative flex gap-3 pb-3 last:pb-0" wire:key="activity-{{ $event['key'] }}">
                            @unless ($loop->last)
                                <span class="absolute start-3.5 top-8 bottom-0 w-px bg-secondary-soft dark:bg-line-strong" aria-hidden="true"></span>
                            @endunless
                            <span class="relative flex size-7 shrink-0 items-center justify-center rounded-full bg-board-head text-secondary-medium ring-1 ring-secondary-soft dark:text-ink-muted dark:ring-line">
                                <flux:icon :name="$event['icon']" variant="micro" class="size-3.5" />
                            </span>
                            <a href="{{ $event['href'] }}" wire:navigate class="min-w-0 flex-1 rounded-md pt-0.5 focus-visible:outline-2 focus-visible:outline-primary">
                                <span class="line-clamp-2 text-sm text-secondary-dark hover:underline hover:underline-offset-4 dark:text-ink">{{ $event['sentence'] }}</span>
                                <time class="text-xs text-secondary-medium dark:text-ink-muted" datetime="{{ $event['at']->toIso8601String() }}">{{ $event['dateOnly'] ? $event['at']->translatedFormat(__('formats.date_short')) : $event['at']->diffForHumans() }}</time>
                            </a>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-ui.panel>
    </div>
