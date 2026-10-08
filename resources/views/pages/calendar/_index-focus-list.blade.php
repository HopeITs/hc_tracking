{{--
    The related-dates panel of calendar/index, shown while the calendar is
    focused on one type. Included by ⚡index.blade.php, whose top PHP block
    defines $focusedType and $typeTextClasses.
--}}
        @if ($focusedType !== null)
            <x-ui.panel :title="__('calendar.related_dates')" :count="$this->focusedItems->count()">
                <div class="flex max-h-96 flex-col gap-2 overflow-y-auto p-2" data-test="calendar-focused-list">
                    @forelse ($this->focusedItems as $item)
                        <div class="flex items-start gap-2 rounded-xl border border-secondary-soft bg-white p-2.5 shadow-hairline dark:border-line dark:bg-surface-1" wire:key="calendar-focused-item-{{ $item['kind'] }}-{{ $item['id'] }}-{{ $item['date']->toDateString() }}">
                            <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-secondary-soft dark:bg-surface-2">
                                @switch($focusedType)
                                    @case('milestone')
                                        <flux:icon.flag variant="micro" class="size-3.5 {{ $typeTextClasses[$focusedType] }}" />
                                        @break
                                    @case('task')
                                        <flux:icon.clipboard-document-check variant="micro" class="size-3.5 {{ $typeTextClasses[$focusedType] }}" />
                                        @break
                                    @case('report')
                                        <flux:icon.document-text variant="micro" class="size-3.5 {{ $typeTextClasses[$focusedType] }}" />
                                        @break
                                    @case('proposal')
                                        <flux:icon.paper-airplane variant="micro" class="size-3.5 {{ $typeTextClasses[$focusedType] }}" />
                                        @break
                                    @case('transfer')
                                        <flux:icon.banknotes variant="micro" class="size-3.5 {{ $typeTextClasses[$focusedType] }}" />
                                        @break
                                    @case('donor')
                                        <flux:icon.phone variant="micro" class="size-3.5 {{ $typeTextClasses[$focusedType] }}" />
                                        @break
                                    @case('project')
                                        <flux:icon.rocket-launch variant="micro" class="size-3.5 {{ $typeTextClasses[$focusedType] }}" />
                                        @break
                                @endswitch
                            </span>
                            <div class="min-w-0 flex-1">
                                @if ($item['kind'] === 'modal-milestone')
                                    <button type="button" wire:click="openEditModal({{ $item['id'] }})" class="block w-full min-w-0 truncate text-start text-xs font-semibold hover:text-primary hover:underline dark:hover:text-status-critical" data-test="calendar-focused-item-{{ $item['id'] }}">
                                        {{ $item['title'] }}
                                    </button>
                                @elseif ($item['kind'] === 'modal-task')
                                    <button type="button" wire:click="openEditTaskModal({{ $item['id'] }})" class="block w-full min-w-0 truncate text-start text-xs font-semibold hover:text-primary hover:underline dark:hover:text-status-critical" data-test="calendar-focused-item-{{ $item['id'] }}">
                                        {{ $item['title'] }}
                                    </button>
                                @elseif ($item['url'] === null)
                                    <span class="block min-w-0 truncate text-xs font-semibold" data-test="calendar-focused-item-{{ $item['id'] }}">
                                        {{ $item['title'] }}
                                    </span>
                                @else
                                    <a href="{{ $item['url'] }}" wire:navigate class="block min-w-0 truncate text-xs font-semibold hover:text-primary hover:underline dark:hover:text-status-critical" data-test="calendar-focused-item-{{ $item['id'] }}">
                                        {{ $item['title'] }}
                                    </a>
                                @endif
                                <p class="mt-0.5 truncate text-[11px] text-secondary-medium dark:text-ink-muted">
                                    {{ $item['date']->translatedFormat('D, j M') }}
                                    @if ($item['subLabel'] !== null)
                                        · {{ $item['subLabel'] }}
                                    @endif
                                </p>
                            </div>
                        </div>
                    @empty
                        <p class="px-1 py-6 text-center text-xs text-secondary-light">{{ __('calendar.no_related_dates') }}</p>
                    @endforelse
                </div>
            </x-ui.panel>
        @endif
