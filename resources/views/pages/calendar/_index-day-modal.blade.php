{{--
    The day-detail dialog of calendar/index: every visible item on one day.
    Included by ⚡index.blade.php, whose top PHP block defines
    $typeDotClasses.
--}}
    <x-ui.modal
        wire:model="showDayDetailModal"
        size="sm"
        :title="$dayDetailDate !== '' ? \Carbon\CarbonImmutable::parse($dayDetailDate)->translatedFormat('l, j F Y') : ''"
        close="closeDayDetail"
        :cancel-label="__('common.close')"
    >
        @if ($showDayDetailModal)
        <div class="flex flex-col gap-2" data-test="calendar-daydetail-list">
            @forelse ($this->dayDetailItems as $item)
                <div class="flex items-start gap-2 rounded-xl border border-secondary-soft bg-white p-2.5 shadow-hairline dark:border-line dark:bg-surface-1" wire:key="calendar-daydetail-{{ $item['type'] }}-{{ $item['kind'] }}-{{ $item['id'] }}">
                    <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-secondary-soft dark:bg-surface-2">
                        <span class="size-2 rounded-full {{ $typeDotClasses[$item['type']] }}"></span>
                    </span>
                    <div class="min-w-0 flex-1">
                        @if ($item['kind'] === 'modal-milestone')
                            <button type="button" wire:click="closeDayDetail(); openEditModal({{ $item['id'] }})" class="block w-full min-w-0 truncate text-start text-xs font-semibold hover:text-primary hover:underline dark:hover:text-status-critical" data-test="calendar-daydetail-item-{{ $item['id'] }}">
                                {{ $item['title'] }}
                            </button>
                        @elseif ($item['kind'] === 'modal-task')
                            <button type="button" wire:click="closeDayDetail(); openEditTaskModal({{ $item['id'] }})" class="block w-full min-w-0 truncate text-start text-xs font-semibold hover:text-primary hover:underline dark:hover:text-status-critical" data-test="calendar-daydetail-item-{{ $item['id'] }}">
                                {{ $item['title'] }}
                            </button>
                        @elseif ($item['url'] === null)
                            <span class="block min-w-0 truncate text-xs font-semibold" data-test="calendar-daydetail-item-{{ $item['id'] }}">
                                {{ $item['title'] }}
                            </span>
                        @else
                            <a href="{{ $item['url'] }}" wire:navigate class="block min-w-0 truncate text-xs font-semibold hover:text-primary hover:underline dark:hover:text-status-critical" data-test="calendar-daydetail-item-{{ $item['id'] }}">
                                {{ $item['title'] }}
                            </a>
                        @endif
                        @if ($item['subLabel'] !== null)
                            <p class="mt-0.5 truncate text-[11px] text-secondary-medium dark:text-ink-muted">{{ $item['subLabel'] }}</p>
                        @endif
                    </div>
                </div>
            @empty
                <p class="px-1 py-6 text-center text-xs text-secondary-light">{{ __('calendar.no_related_dates') }}</p>
            @endforelse
        </div>
        @endif
    </x-ui.modal>
