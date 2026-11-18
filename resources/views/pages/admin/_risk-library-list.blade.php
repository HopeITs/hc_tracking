{{--
    The risk library list of admin/risk-library, in the order the logframe
    picker offers it. Included by ⚡risk-library.blade.php, which defines
    $visibleEntries, $entries and $filtersAreActive.

    Each row reads as one line: its position in the whole library (an LTR
    number), the title in the reader's language with the other language under
    it, the category and default ratings, one status word (In use / Not used
    yet / Switched off, a word and never a colour alone), and how many proposal
    risks were picked from it. Edit is the main action; switching on or off and
    delete sit in the ⋯ menu. Up and down are vertical, so they need no
    flipping in RTL; while a search or button narrows the list they are
    disabled, because a move is a move in the whole library.
--}}
@php
    $readsArabic = app()->getLocale() === 'ar';
    $total = $entries->count();
    $positions = $entries->pluck('id')->flip();
    $ratingLabel = fn (?int $rating): string => match ($rating) {
        1 => __('risks.rating_low'),
        2 => __('risks.rating_medium'),
        3 => __('risks.rating_high'),
        default => __('risks.not_rated'),
    };
@endphp

{{--
    After a move, focus returns to the moved entry's arrow in the same
    direction, or to the other arrow when that one is now disabled (the row
    reached an end), so a keyboard user can keep pressing Enter. Flux re-enables
    the clicked button only after the request, hence the deferred focus.
--}}
<ol
    class="divide-y divide-secondary-soft rounded-xl border border-secondary-soft dark:divide-line dark:border-transparent dark:bg-surface-1 dark:shadow-raised"
    aria-label="{{ __('risk_library.list_label') }}"
    data-risk-library-list
    x-data="{
        refocus(entryId, direction) {
            const row = this.$el.querySelector(`[data-risk-library-entry='${entryId}']`);
            if (! row) {
                return;
            }
            const wanted = row.querySelector(`[data-test='move-entry-${direction}']`);
            const other = row.querySelector(`[data-test='move-entry-${direction === 'up' ? 'down' : 'up'}']`);
            (wanted && ! wanted.disabled ? wanted : other)?.focus();
        },
    }"
    x-on:risk-library-moved.window="$nextTick(() => setTimeout(() => refocus($event.detail.entryId, $event.detail.direction), 50))"
>
    @foreach ($visibleEntries as $entry)
        @php
            $displayTitle = $entry->localizedTitle();
            $secondaryTitle = $readsArabic ? $entry->title_en : $entry->title_ar;
            $position = $positions[$entry->id] + 1;
            $statusTone = match ($entry->status) {
                'inuse' => 'text-status-good',
                'off' => 'text-secondary-medium dark:text-ink-muted',
                default => 'text-secondary-dark dark:text-ink',
            };
            $statusDot = match ($entry->status) {
                'inuse' => 'bg-status-good',
                default => 'bg-secondary-light dark:bg-ink-faint',
            };
        @endphp
        <li wire:key="risk-library-entry-{{ $entry->id }}" data-risk-library-entry="{{ $entry->id }}" data-status="{{ $entry->status }}" @class(['flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3', 'opacity-70' => ! $entry->is_active])>
            <span class="w-8 shrink-0 text-center font-mono text-xs font-semibold tabular-nums text-secondary-medium dark:text-ink-muted" dir="ltr" aria-label="{{ __('risk_library.position', ['position' => $position]) }}">{{ $position }}</span>

            <div class="min-w-0 flex-1 basis-48">
                <p class="text-[15px] font-semibold leading-snug"><bdi>{{ $displayTitle }}</bdi></p>
                <p class="mt-0.5 text-sm text-secondary-medium dark:text-ink-muted"><bdi>{{ $secondaryTitle }}</bdi></p>
                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 type-meta text-secondary-medium dark:text-ink-muted">
                    <span class="inline-flex items-center gap-1.5 font-semibold {{ $statusTone }}" @if ($entry->status === 'off') data-test="risk-library-inactive-badge" @endif>
                        <span aria-hidden="true" class="size-2 shrink-0 rounded-full {{ $statusDot }}"></span>{{ __('risk_library.status_'.$entry->status) }}
                    </span>
                    @if (filled($entry->category))
                        <span data-test="risk-library-category"><bdi>{{ $entry->category }}</bdi></span>
                    @endif
                    <span>{{ __('risk_library.default_ratings', ['likelihood' => $ratingLabel($entry->default_likelihood), 'severity' => $ratingLabel($entry->default_severity)]) }}</span>
                    <span class="tabular-nums">{{ trans_choice('risk_library.used_by', $entry->risks_count, ['count' => $entry->risks_count]) }}</span>
                </div>
            </div>

            <div class="ms-auto flex shrink-0 flex-wrap items-center gap-1">
                {{-- A disabled button takes no pointer events, so the reason sits on this wrapper. --}}
                <span class="flex items-center gap-1" @if ($filtersAreActive) title="{{ __('risk_library.clear_to_reorder') }}" @endif data-test="move-entry-arrows">
                    <x-ui.button
                        tone="ghost" size="sm" icon="arrow-up" class="min-h-11 min-w-11 md:size-9! md:min-h-0 md:min-w-0"
                        wire:click="moveEntry({{ $entry->id }}, 'up')"
                        :disabled="$filtersAreActive || $position === 1"
                        :aria-label="__('risk_library.move_up_name', ['name' => $displayTitle])"
                        data-test="move-entry-up"
                    />
                    <x-ui.button
                        tone="ghost" size="sm" icon="arrow-down" class="min-h-11 min-w-11 md:size-9! md:min-h-0 md:min-w-0"
                        wire:click="moveEntry({{ $entry->id }}, 'down')"
                        :disabled="$filtersAreActive || $position === $total"
                        :aria-label="__('risk_library.move_down_name', ['name' => $displayTitle])"
                        data-test="move-entry-down"
                    />
                </span>
                <x-ui.button size="sm" icon="pencil-square" class="min-h-11 md:min-h-9" wire:click="openEditModal({{ $entry->id }})" :aria-label="__('common.edit_name', ['name' => $displayTitle])" data-test="edit-risk-library-entry">
                    {{ __('risk_library.edit') }}
                </x-ui.button>

                <flux:dropdown align="end">
                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" class="min-h-11 md:min-h-9" :aria-label="__('risk_library.more_actions_for_name', ['name' => $displayTitle])" data-test="risk-library-menu-{{ $entry->id }}" />
                    <flux:menu>
                        @if ($entry->is_active)
                            <flux:menu.item icon="pause-circle" wire:click="confirmDeactivate({{ $entry->id }})" data-test="deactivate-entry">{{ __('risk_library.deactivate') }}</flux:menu.item>
                        @else
                            <flux:menu.item icon="play-circle" wire:click="reactivateEntry({{ $entry->id }})" data-test="reactivate-entry">{{ __('risk_library.reactivate') }}</flux:menu.item>
                        @endif
                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $entry->id }})" data-test="delete-entry">{{ __('common.delete') }}</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            </div>
        </li>
    @endforeach
</ol>
