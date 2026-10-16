{{--
    The evidence type list of admin/evidence-types, in the order report
    templates offer it. Included by ⚡evidence-types.blade.php.

    Each row reads as one line: the position (an LTR number), the name in the
    reader's language with the other language under it, and an "Inactive"
    badge when it is off (a word, never a colour alone). Up/down are
    vertical, so they need no flipping in RTL; each button names its type,
    and the page's live region announces the new position.
--}}
@php
    $readsArabic = app()->getLocale() === 'ar';
    $total = $evidenceTypes->count();
    $badgeClasses = 'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold leading-4';
@endphp

{{--
    After a move, focus returns to the moved type's arrow in the same
    direction, or to the other arrow when that one is now disabled (the row
    reached an end), so a keyboard user can keep pressing Enter. Flux re-enables
    the clicked button only after the request, hence the deferred focus.
--}}
<ol
    class="divide-y divide-secondary-soft rounded-xl border border-secondary-soft dark:divide-line dark:border-transparent dark:bg-surface-1 dark:shadow-raised"
    aria-label="{{ __('evidence_types.list_label') }}"
    data-evidence-types-list
    x-data="{
        refocus(evidenceTypeId, direction) {
            const row = this.$el.querySelector(`[data-evidence-type='${evidenceTypeId}']`);
            if (! row) {
                return;
            }
            const wanted = row.querySelector(`[data-test='move-evidence-type-${direction}']`);
            const other = row.querySelector(`[data-test='move-evidence-type-${direction === 'up' ? 'down' : 'up'}']`);
            (wanted && ! wanted.disabled ? wanted : other)?.focus();
        },
    }"
    x-on:evidence-type-moved.window="$nextTick(() => setTimeout(() => refocus($event.detail.evidenceTypeId, $event.detail.direction), 50))"
>
    @foreach ($evidenceTypes as $evidenceType)
        @php
            $displayName = $evidenceType->localizedName();
            $secondaryName = match (true) {
                blank($evidenceType->name_en) => null,
                $readsArabic => $evidenceType->name_en,
                default => $evidenceType->name_ar,
            };
        @endphp
        <li wire:key="evidence-type-{{ $evidenceType->id }}" data-evidence-type="{{ $evidenceType->id }}" @class(['flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3', 'opacity-70' => ! $evidenceType->is_active])>
            <span class="w-8 shrink-0 text-center font-mono text-xs font-semibold tabular-nums text-secondary-medium dark:text-ink-muted" dir="ltr" aria-label="{{ __('evidence_types.position', ['position' => $loop->iteration]) }}">{{ $loop->iteration }}</span>

            <div class="min-w-40 flex-1 basis-48">
                <p class="text-[15px] font-semibold leading-snug" dir="auto">{{ $displayName }}</p>
                @if ($secondaryName !== null)
                    <p class="mt-0.5 text-sm text-secondary-medium dark:text-ink-muted" dir="auto">{{ $secondaryName }}</p>
                @endif
                @if (! $evidenceType->is_active)
                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 type-meta text-secondary-medium dark:text-ink-muted">
                        <span class="{{ $badgeClasses }} border-secondary-light text-secondary-dark dark:border-line-strong dark:text-ink" data-test="evidence-type-inactive-badge">
                            <flux:icon.pause-circle variant="micro" class="size-3" aria-hidden="true" />{{ __('evidence_types.inactive') }}
                        </span>
                    </div>
                @endif
            </div>

            <div class="ms-auto flex shrink-0 flex-wrap items-center gap-1">
                <x-ui.button
                    tone="ghost" size="sm" icon="arrow-up" class="size-9!"
                    wire:click="moveEvidenceType({{ $evidenceType->id }}, 'up')"
                    wire:target="moveEvidenceType({{ $evidenceType->id }}, 'up')"
                    :disabled="$loop->first"
                    :aria-label="__('evidence_types.move_up_name', ['name' => $displayName])"
                    data-test="move-evidence-type-up"
                />
                <x-ui.button
                    tone="ghost" size="sm" icon="arrow-down" class="size-9!"
                    wire:click="moveEvidenceType({{ $evidenceType->id }}, 'down')"
                    wire:target="moveEvidenceType({{ $evidenceType->id }}, 'down')"
                    :disabled="$loop->iteration === $total"
                    :aria-label="__('evidence_types.move_down_name', ['name' => $displayName])"
                    data-test="move-evidence-type-down"
                />
                <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $evidenceType->id }})" :aria-label="__('common.edit_name', ['name' => $displayName])" />
                @if ($evidenceType->is_active)
                    <x-ui.button tone="ghost" size="sm" icon="pause-circle" class="size-9!" wire:click="confirmDeactivate({{ $evidenceType->id }})" :aria-label="__('evidence_types.deactivate_name', ['name' => $displayName])" />
                @else
                    <x-ui.button tone="ghost" size="sm" icon="play-circle" class="size-9!" wire:click="reactivateEvidenceType({{ $evidenceType->id }})" wire:target="reactivateEvidenceType({{ $evidenceType->id }})" :aria-label="__('evidence_types.reactivate_name', ['name' => $displayName])" />
                @endif
                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9! text-status-critical!" wire:click="confirmDelete({{ $evidenceType->id }})" :aria-label="__('common.delete_name', ['name' => $displayName])" />
            </div>
        </li>
    @endforeach
</ol>
