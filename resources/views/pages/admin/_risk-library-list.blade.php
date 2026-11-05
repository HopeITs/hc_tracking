{{--
    The risk library list of admin/risk-library, in the order the logframe
    picker offers it. Included by ⚡risk-library.blade.php.

    Each row reads as one line: the position (an LTR number), the title in the
    reader's language with the other language under it, the category, the
    default ratings, how many proposal risks were picked from it, and an
    "Inactive" badge when it is off (a word, never a colour alone). Up/down
    are vertical, so they need no flipping in RTL; each button names its
    entry, and the page's live region announces the new position.
--}}
@php
    $readsArabic = app()->getLocale() === 'ar';
    $total = $entries->count();
    $badgeClasses = 'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold leading-4';
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
    @foreach ($entries as $entry)
        @php
            $displayTitle = $entry->localizedTitle();
            $secondaryTitle = $readsArabic ? $entry->title_en : $entry->title_ar;
        @endphp
        <li wire:key="risk-library-entry-{{ $entry->id }}" data-risk-library-entry="{{ $entry->id }}" @class(['flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3', 'opacity-70' => ! $entry->is_active])>
            <span class="w-8 shrink-0 text-center font-mono text-xs font-semibold tabular-nums text-secondary-medium dark:text-ink-muted" dir="ltr" aria-label="{{ __('risk_library.position', ['position' => $loop->iteration]) }}">{{ $loop->iteration }}</span>

            <div class="min-w-0 flex-1 basis-48">
                <p class="text-[15px] font-semibold leading-snug"><bdi>{{ $displayTitle }}</bdi></p>
                <p class="mt-0.5 text-sm text-secondary-medium dark:text-ink-muted"><bdi>{{ $secondaryTitle }}</bdi></p>
                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 type-meta text-secondary-medium dark:text-ink-muted">
                    @if (filled($entry->category))
                        <span data-test="risk-library-category"><bdi>{{ $entry->category }}</bdi></span>
                    @endif
                    <span>{{ __('risk_library.default_ratings', ['likelihood' => $ratingLabel($entry->default_likelihood), 'severity' => $ratingLabel($entry->default_severity)]) }}</span>
                    <span class="tabular-nums">{{ trans_choice('risk_library.used_by', $entry->risks_count, ['count' => $entry->risks_count]) }}</span>
                    @if (! $entry->is_active)
                        <span class="{{ $badgeClasses }} border-secondary-light text-secondary-dark dark:border-line-strong dark:text-ink" data-test="risk-library-inactive-badge">
                            <flux:icon.pause-circle variant="micro" class="size-3" aria-hidden="true" />{{ __('risk_library.inactive') }}
                        </span>
                    @endif
                </div>
            </div>

            <div class="ms-auto flex shrink-0 flex-wrap items-center gap-1">
                <x-ui.button
                    tone="ghost" size="sm" icon="arrow-up" class="size-9!"
                    wire:click="moveEntry({{ $entry->id }}, 'up')"
                    :disabled="$loop->first"
                    :aria-label="__('risk_library.move_up_name', ['name' => $displayTitle])"
                    data-test="move-entry-up"
                />
                <x-ui.button
                    tone="ghost" size="sm" icon="arrow-down" class="size-9!"
                    wire:click="moveEntry({{ $entry->id }}, 'down')"
                    :disabled="$loop->iteration === $total"
                    :aria-label="__('risk_library.move_down_name', ['name' => $displayTitle])"
                    data-test="move-entry-down"
                />
                <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $entry->id }})" :aria-label="__('common.edit_name', ['name' => $displayTitle])" />
                @if ($entry->is_active)
                    <x-ui.button tone="ghost" size="sm" icon="pause-circle" class="size-9!" wire:click="confirmDeactivate({{ $entry->id }})" :aria-label="__('risk_library.deactivate_name', ['name' => $displayTitle])" />
                @else
                    <x-ui.button tone="ghost" size="sm" icon="play-circle" class="size-9!" wire:click="reactivateEntry({{ $entry->id }})" :aria-label="__('risk_library.reactivate_name', ['name' => $displayTitle])" />
                @endif
                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9! text-status-critical!" wire:click="confirmDelete({{ $entry->id }})" :aria-label="__('common.delete_name', ['name' => $displayTitle])" />
            </div>
        </li>
    @endforeach
</ol>
