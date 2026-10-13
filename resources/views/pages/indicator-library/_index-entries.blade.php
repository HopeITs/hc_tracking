{{--
    The library's entries on this page, under their program heading.
    Included by ⚡index.blade.php.

    Each row reads as one line of type: the code (always left-to-right, in
    its own isolate), the name in the reader's language with the other
    language under it, then what the entry measures. Status is a word in a
    badge, never a colour alone.
--}}
@use('App\Enums\IndicatorMeasure')
@php
    $readsArabic = app()->getLocale() === 'ar';
    $canUpdate = auth()->user()->can('indicator-library.update');
    $canDelete = auth()->user()->can('indicator-library.delete');
    $badgeClasses = 'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold leading-4';
@endphp

<div class="flex flex-col gap-8">
    @foreach ($this->pagedGroups() as $group)
        <section wire:key="library-group-{{ $group['key'] }}" data-library-group aria-labelledby="library-group-{{ $group['key'] }}">
            <header class="flex items-baseline justify-between gap-3 border-b-2 border-secondary-dark pb-2 dark:border-ink">
                <h2 id="library-group-{{ $group['key'] }}" class="text-lg font-bold leading-tight"><bdi>{{ $group['heading'] }}</bdi></h2>
                <span class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">{{ trans_choice('indicator_library.entries_count', $group['entries']->count(), ['count' => $group['entries']->count()]) }}</span>
            </header>

            <ol class="divide-y divide-secondary-soft dark:divide-line">
                @foreach ($group['entries'] as $libraryEntry)
                    @php
                        $secondaryName = match (true) {
                            blank($libraryEntry->name_en) => null,
                            $readsArabic => $libraryEntry->name_en,
                            default => $libraryEntry->name_ar,
                        };
                        $isExpanded = $expandedEntryId === $libraryEntry->id;
                        $displayName = $libraryEntry->localizedName();
                        $measureText = $libraryEntry->unit !== null && $libraryEntry->measure !== IndicatorMeasure::Percentage
                            ? $libraryEntry->measure->label().' · '.$libraryEntry->unit
                            : $libraryEntry->measure->label();
                    @endphp
                    <li wire:key="library-entry-{{ $libraryEntry->id }}" data-library-entry @class(['py-4', 'opacity-70' => ! $libraryEntry->is_active])>
                        <div class="flex flex-col gap-2 @2xl/directory:flex-row @2xl/directory:items-start @2xl/directory:gap-5">
                            <div class="flex shrink-0 items-center gap-2 @2xl/directory:w-36 @2xl/directory:pt-0.5">
                                <span aria-hidden="true" @class(['size-1.5 shrink-0 rounded-full', 'bg-primary dark:bg-brand' => $libraryEntry->is_active, 'bg-secondary-light dark:bg-line-strong' => ! $libraryEntry->is_active])></span>
                                <bdi dir="ltr" class="font-mono text-xs font-semibold tracking-tight text-secondary-dark tabular-nums dark:text-ink">{{ $libraryEntry->code }}</bdi>
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="text-[15px] font-semibold leading-snug"><bdi>{{ $displayName }}</bdi></p>
                                @if ($secondaryName !== null)
                                    <p class="mt-0.5 text-sm text-secondary-medium dark:text-ink-muted"><bdi>{{ $secondaryName }}</bdi></p>
                                @endif

                                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 type-meta text-secondary-medium dark:text-ink-muted">
                                    <span>{{ $measureText }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span>{{ $libraryEntry->frequency->label() }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span>{{ $libraryEntry->collected_by->label() }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span class="tabular-nums">{{ trans_choice('indicator_library.used_by', $libraryEntry->indicators_count, ['count' => $libraryEntry->indicators_count]) }}</span>

                                    @if (! $libraryEntry->is_active)
                                        <span class="{{ $badgeClasses }} border-secondary-light text-secondary-dark dark:border-line-strong dark:text-ink">
                                            <flux:icon.pause-circle variant="micro" class="size-3" aria-hidden="true" />{{ __('indicator_library.inactive') }}
                                        </span>
                                    @endif
                                    @if (blank($libraryEntry->name_en))
                                        <span class="{{ $badgeClasses }} border-status-warn/50 bg-status-warn-soft text-secondary-dark dark:text-ink" data-test="missing-english-badge">
                                            <flux:icon.language variant="micro" class="size-3" aria-hidden="true" />{{ __('indicator_library.missing_english') }}
                                        </span>
                                    @endif
                                    @if ($libraryEntry->is_legacy)
                                        <span class="{{ $badgeClasses }} border-secondary-soft text-secondary-medium dark:border-line dark:text-ink-muted">{{ __('indicator_library.legacy') }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-1 self-end @2xl/directory:self-start">
                                <x-ui.button
                                    tone="ghost" size="sm" :icon="$isExpanded ? 'chevron-up' : 'chevron-down'" class="size-9!"
                                    wire:click="toggleEntry({{ $libraryEntry->id }})"
                                    :aria-expanded="$isExpanded ? 'true' : 'false'"
                                    aria-controls="library-entry-details-{{ $libraryEntry->id }}"
                                    :aria-label="__($isExpanded ? 'indicator_library.hide_details' : 'indicator_library.show_details', ['name' => $displayName])"
                                />
                                @if ($canUpdate)
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $libraryEntry->id }})" :aria-label="__('common.edit_name', ['name' => $displayName])" />
                                    @if ($libraryEntry->is_active)
                                        <x-ui.button tone="ghost" size="sm" icon="pause-circle" class="size-9!" wire:click="confirmDeactivate({{ $libraryEntry->id }})" :aria-label="__('indicator_library.deactivate_name', ['name' => $displayName])" />
                                    @else
                                        <x-ui.button tone="ghost" size="sm" icon="play-circle" class="size-9!" wire:click="reactivateEntry({{ $libraryEntry->id }})" :aria-label="__('indicator_library.reactivate_name', ['name' => $displayName])" />
                                    @endif
                                @endif
                                @if ($canDelete && $libraryEntry->indicators_count === 0)
                                    <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9! text-status-critical!" wire:click="confirmDelete({{ $libraryEntry->id }})" :aria-label="__('common.delete_name', ['name' => $displayName])" />
                                @endif
                            </div>
                        </div>

                        @if ($isExpanded)
                            @php
                                $details = array_filter([
                                    __('indicator_library.fields.definition') => $libraryEntry->definition,
                                    __('indicator_library.fields.calculation_method') => $libraryEntry->calculation_method,
                                    __('indicator_library.fields.numerator_label') => $libraryEntry->numerator_label,
                                    __('indicator_library.fields.denominator_label') => $libraryEntry->denominator_label,
                                    __('indicator_library.fields.denominator_basis') => $libraryEntry->denominator_basis?->label(),
                                    __('indicator_library.fields.is_disaggregated') => $libraryEntry->is_disaggregated ? __('indicator_library.disaggregated_by_sex_and_disability') : null,
                                    __('indicator_library.fields.is_cumulative') => $libraryEntry->is_cumulative ? __('indicator_library.cumulative_last_value') : null,
                                    __('indicator_library.fields.frequency_note') => $libraryEntry->frequency_note,
                                    __('indicator_library.fields.follow_up_months') => $libraryEntry->follow_up_months === null ? null : trans_choice('indicator_library.months_after', $libraryEntry->follow_up_months, ['count' => $libraryEntry->follow_up_months]),
                                    __('indicator_library.fields.suggested_level') => $libraryEntry->suggested_level?->label(),
                                    __('indicator_library.fields.data_source') => $libraryEntry->data_source,
                                    __('indicator_library.fields.means_of_verification') => $libraryEntry->means_of_verification,
                                ], fn (?string $value): bool => filled($value));
                            @endphp
                            <dl id="library-entry-details-{{ $libraryEntry->id }}" class="mt-4 grid gap-x-8 gap-y-3 border-s-2 border-secondary-soft ps-4 @2xl/directory:ms-41 @3xl/directory:grid-cols-2 dark:border-line">
                                @forelse ($details as $detailLabel => $detailValue)
                                    <div class="min-w-0">
                                        <dt class="type-label text-secondary-medium dark:text-ink-muted">{{ $detailLabel }}</dt>
                                        <dd class="mt-0.5 whitespace-pre-line text-sm"><bdi>{{ $detailValue }}</bdi></dd>
                                    </div>
                                @empty
                                    <p class="text-sm text-secondary-medium dark:text-ink-muted">{{ __('indicator_library.no_details_yet') }}</p>
                                @endforelse
                            </dl>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    @endforeach
</div>
