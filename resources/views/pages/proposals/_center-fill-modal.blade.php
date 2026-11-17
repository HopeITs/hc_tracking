{{--
    The "Add centers" dialog of proposals/show (FillsProposalCenters, Y-01 slice
    2, D-83). Every location row is listed; a row that has a center or is field
    work is locked (plain text and chips, no field), only a row with neither
    shows the centers picker and the "Field work, no center" tick. No reason is
    asked. The body renders only while the dialog is open. Errors from
    SaveProposal come back on `locations` and `locations.{i}.center_ids`.
--}}
    <x-ui.modal
        wire:model="showCenterFillModal"
        size="lg"
        :title="__('proposals.center_fill_title')"
        :description="__('proposals.center_fill_description')"
        submit="saveCenterFill"
        close="closeCenterFill"
        data-test="center-fill-modal"
    >
        @if ($showCenterFillModal)
            @php
                $fillRows = $this->centerFillRows;
                $firstFailingIndex = collect(array_keys($fillRows))->first(fn (int $index): bool => $errors->has("locations.{$index}.center_ids"));
            @endphp

            <div
                class="grid gap-y-4"
                x-data
                x-init="$nextTick(() => setTimeout(() => $el.querySelector('[data-center-fill-row][data-fillable] [data-form-field] :is(button, select, input):not([disabled])')?.focus(), 60))"
            >
                @if ($errors->any())
                    <div class="rounded-lg border border-primary bg-secondary-soft/60 p-3 text-sm text-status-critical" role="alert" data-test="center-fill-errors">
                        @if ($firstFailingIndex !== null)
                            <p class="font-semibold">{{ __('proposals.form_error_summary_row', ['number' => $firstFailingIndex + 1]) }}</p>
                            <p>{{ $errors->first("locations.{$firstFailingIndex}.center_ids") }}</p>
                        @else
                            <p class="font-semibold">{{ $errors->first('locations') !== '' ? $errors->first('locations') : $errors->first() }}</p>
                        @endif
                    </div>
                @endif

                <ol class="grid gap-3" data-center-fill-rows>
                    @foreach ($this->locationRows as $index => $locationRow)
                        @php
                            $fillRow = $fillRows[$index] ?? null;
                            $isFillable = $fillRow !== null && $fillRow['fillable'] && isset($centerFill[$locationRow['id']]);
                            $cityCenters = $isFillable ? ($this->centerFillCentersByCity[$fillRow['cityId']] ?? []) : [];
                            $isFieldWork = $isFillable && ($centerFill[$locationRow['id']]['city_wide'] ?? false);
                            $rowError = $errors->first("locations.{$index}.center_ids");
                        @endphp
                        <li
                            class="grid gap-x-4 gap-y-3 rounded-lg border p-3 @2xl/modal:grid-cols-3 {{ $rowError !== '' ? 'border-status-critical' : 'border-secondary-soft dark:border-line' }}"
                            wire:key="center-fill-row-{{ $locationRow['id'] }}"
                            data-center-fill-row="{{ $locationRow['id'] }}"
                            @if ($isFillable) data-fillable @else data-locked @endif
                        >
                            <div class="min-w-0">
                                <p class="text-xs text-secondary-medium dark:text-ink-muted">{{ __('proposals.location_city') }}</p>
                                <p class="text-sm font-semibold text-secondary-dark dark:text-ink" dir="auto">{{ $locationRow['city'] }}</p>
                            </div>

                            <div class="min-w-0">
                                <p class="text-xs text-secondary-medium dark:text-ink-muted">{{ __('proposals.location_program') }}</p>
                                <p class="text-sm text-secondary-dark dark:text-ink" dir="auto">{{ $locationRow['program'] ?? $locationRow['programRequestName'] ?? '—' }}</p>
                            </div>

                            <div class="min-w-0">
                                <p class="text-xs text-secondary-medium dark:text-ink-muted">{{ __('proposals.location_responsible') }}</p>
                                <p class="text-sm text-secondary-dark dark:text-ink" dir="auto">{{ $locationRow['responsible'] ?? '—' }}</p>
                            </div>

                            @if ($isFillable)
                                <div class="grid gap-y-3 @2xl/modal:col-span-3" wire:key="center-fill-picker-{{ $locationRow['id'] }}-{{ $isFieldWork ? 'field' : 'centers' }}">
                                    <x-ui.form-multiselect
                                        wire:model="centerFill.{{ $locationRow['id'] }}.center_ids"
                                        :label="__('proposals.location_centers')"
                                        :placeholder="$isFieldWork ? __('proposals.location_field_work') : __('proposals.location_select_centers')"
                                        :help="$isFieldWork ? __('proposals.location_city_wide_help') : ($cityCenters === [] ? __('proposals.location_no_centers_in_city') : __('proposals.center_fill_pick_help'))"
                                        :disabled="$isFieldWork || $cityCenters === []"
                                    >
                                        @foreach ($cityCenters as $center)
                                            <option value="{{ $center['id'] }}">{{ $center['name'] }}</option>
                                        @endforeach
                                    </x-ui.form-multiselect>

                                    <label class="flex min-h-10 items-center gap-2 text-sm text-secondary-dark dark:text-ink" data-test="center-fill-city-wide">
                                        <input type="checkbox" wire:model.live="centerFill.{{ $locationRow['id'] }}.city_wide" class="size-4 shrink-0 accent-primary dark:accent-brand" @checked($isFieldWork) />
                                        <span>{{ __('proposals.location_city_wide_label') }}</span>
                                    </label>

                                    @if ($rowError !== '')
                                        <p class="text-sm text-status-critical" role="alert" data-test="center-fill-row-error">{{ $rowError }}</p>
                                    @endif
                                </div>
                            @else
                                <div class="flex min-w-0 flex-wrap items-center gap-2 @2xl/modal:col-span-3" data-center-fill-locked>
                                    <span class="inline-flex items-center gap-1 rounded-full bg-secondary-soft px-2 py-0.5 text-xs font-bold text-secondary-dark dark:bg-line dark:text-ink">
                                        <flux:icon name="lock-closed" variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('proposals.center_fill_locked') }}
                                    </span>

                                    @if ($locationRow['fieldWork'])
                                        <span class="text-sm text-secondary-dark dark:text-ink">{{ __('proposals.location_field_work') }}</span>
                                    @else
                                        @foreach ($locationRow['centers'] as $centerName)
                                            <span class="inline-flex items-center rounded-full border border-secondary-soft px-2 py-0.5 text-xs text-secondary-dark dark:border-line dark:text-ink" dir="auto" data-center-chip>{{ $centerName }}</span>
                                        @endforeach
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>

                <p class="flex items-start gap-2 rounded-lg bg-status-warn-soft p-3 text-sm text-status-warn" data-test="center-fill-warning">
                    <flux:icon name="exclamation-triangle" variant="micro" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{{ __('proposals.center_fill_warning') }}</span>
                </p>
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="saveCenterFill" data-test="confirm-center-fill">
                {{ __('proposals.center_fill_save') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
