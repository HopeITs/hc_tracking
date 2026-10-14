{{--
    The sector list of admin/sectors, in the order the project form offers
    it. Included by ⚡sectors.blade.php.

    Each row reads as one line: the position (an LTR number), the name in the
    reader's language with the other language under it, how many projects use
    it, and an "Inactive" badge when it is off (a word, never a colour alone).
    Up/down are vertical, so they need no flipping in RTL; each button names
    its sector, and the page's live region announces the new position.
--}}
@php
    $readsArabic = app()->getLocale() === 'ar';
    $total = $sectors->count();
    $badgeClasses = 'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold leading-4';
@endphp

{{--
    After a move, focus returns to the moved sector's arrow in the same
    direction, or to the other arrow when that one is now disabled (the row
    reached an end), so a keyboard user can keep pressing Enter. Flux re-enables
    the clicked button only after the request, hence the deferred focus.
--}}
<ol
    class="divide-y divide-secondary-soft rounded-xl border border-secondary-soft dark:divide-line dark:border-transparent dark:bg-surface-1 dark:shadow-raised"
    aria-label="{{ __('sectors.list_label') }}"
    data-sectors-list
    x-data="{
        refocus(sectorId, direction) {
            const row = this.$el.querySelector(`[data-sector='${sectorId}']`);
            if (! row) {
                return;
            }
            const wanted = row.querySelector(`[data-test='move-sector-${direction}']`);
            const other = row.querySelector(`[data-test='move-sector-${direction === 'up' ? 'down' : 'up'}']`);
            (wanted && ! wanted.disabled ? wanted : other)?.focus();
        },
    }"
    x-on:sector-moved.window="$nextTick(() => setTimeout(() => refocus($event.detail.sectorId, $event.detail.direction), 50))"
>
    @foreach ($sectors as $sector)
        @php
            $displayName = $sector->localizedName();
            $secondaryName = match (true) {
                blank($sector->name_en) => null,
                $readsArabic => $sector->name_en,
                default => $sector->name_ar,
            };
        @endphp
        <li wire:key="sector-{{ $sector->id }}" data-sector="{{ $sector->id }}" @class(['flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3', 'opacity-70' => ! $sector->is_active])>
            <span class="w-8 shrink-0 text-center font-mono text-xs font-semibold tabular-nums text-secondary-medium dark:text-ink-muted" dir="ltr" aria-label="{{ __('sectors.position', ['position' => $loop->iteration]) }}">{{ $loop->iteration }}</span>

            <div class="min-w-0 flex-1 basis-48">
                <p class="text-[15px] font-semibold leading-snug"><bdi>{{ $displayName }}</bdi></p>
                @if ($secondaryName !== null)
                    <p class="mt-0.5 text-sm text-secondary-medium dark:text-ink-muted"><bdi>{{ $secondaryName }}</bdi></p>
                @endif
                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 type-meta text-secondary-medium dark:text-ink-muted">
                    <span class="tabular-nums">{{ trans_choice('sectors.used_by', $sector->projects_count, ['count' => $sector->projects_count]) }}</span>
                    @if (! $sector->is_active)
                        <span class="{{ $badgeClasses }} border-secondary-light text-secondary-dark dark:border-line-strong dark:text-ink" data-test="sector-inactive-badge">
                            <flux:icon.pause-circle variant="micro" class="size-3" aria-hidden="true" />{{ __('sectors.inactive') }}
                        </span>
                    @endif
                </div>
            </div>

            @if ($canManage)
                <div class="ms-auto flex shrink-0 flex-wrap items-center gap-1">
                    <x-ui.button
                        tone="ghost" size="sm" icon="arrow-up" class="size-9!"
                        wire:click="moveSector({{ $sector->id }}, 'up')"
                        :disabled="$loop->first"
                        :aria-label="__('sectors.move_up_name', ['name' => $displayName])"
                        data-test="move-sector-up"
                    />
                    <x-ui.button
                        tone="ghost" size="sm" icon="arrow-down" class="size-9!"
                        wire:click="moveSector({{ $sector->id }}, 'down')"
                        :disabled="$loop->iteration === $total"
                        :aria-label="__('sectors.move_down_name', ['name' => $displayName])"
                        data-test="move-sector-down"
                    />
                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $sector->id }})" :aria-label="__('common.edit_name', ['name' => $displayName])" />
                    @if ($sector->is_active)
                        <x-ui.button tone="ghost" size="sm" icon="pause-circle" class="size-9!" wire:click="confirmDeactivate({{ $sector->id }})" :aria-label="__('sectors.deactivate_name', ['name' => $displayName])" />
                    @else
                        <x-ui.button tone="ghost" size="sm" icon="play-circle" class="size-9!" wire:click="reactivateSector({{ $sector->id }})" :aria-label="__('sectors.reactivate_name', ['name' => $displayName])" />
                    @endif
                    <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9! text-status-critical!" wire:click="confirmDelete({{ $sector->id }})" :aria-label="__('common.delete_name', ['name' => $displayName])" />
                </div>
            @endif
        </li>
    @endforeach
</ol>
