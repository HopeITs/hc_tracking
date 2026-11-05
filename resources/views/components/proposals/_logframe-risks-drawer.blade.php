{{--
    The "Risks & assumptions" dialog of the proposal logframe (W-09, D-61).
    Included by ⚡logframe.blade.php. It lists the risks on one outcome or
    output and, for holders of the attach ability, offers a picker over the
    active MEAL library. There is no free-text field: a risk can only be
    picked from the library. Dialogs are centered in this app (shell v2), so
    the "drawer" is the standard x-ui.modal, which traps focus and returns it
    to the node's button when it closes.
--}}
@php
    $riskResult = $this->riskResult;
    $risksHere = $riskResult === null ? collect() : $this->proposalRisks->get($riskResult->id, collect());
    $pickedIds = array_map(intval(...), $riskPicks);
    $canManage = $this->canManageRisks;
@endphp

<x-ui.modal
    wire:model="showRisksDrawer"
    size="md"
    :title="__('risks.risks_and_assumptions')"
    :description="$riskResult?->statement"
    :submit="$canManage ? 'attachRisks' : null"
    close="closeRisks"
>
    @if ($showRisksDrawer && $riskResult !== null)
        <div class="grid gap-y-4" data-test="risks-drawer">
            @if ($risksHere->isEmpty())
                <p class="type-meta text-secondary-medium dark:text-ink-muted" data-test="risks-drawer-empty">{{ __('risks.none_on_result') }}</p>
            @else
                <ul class="divide-y divide-secondary-soft rounded-lg border border-secondary-soft dark:divide-line dark:border-line" aria-label="{{ __('risks.risks_on_result') }}">
                    @foreach ($risksHere as $risk)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-3 py-2.5" wire:key="drawer-risk-{{ $risk->id }}" data-risk="{{ $risk->id }}">
                            <span class="min-w-0 flex-1 basis-40 text-sm font-semibold text-secondary-dark dark:text-ink" dir="auto">{{ $risk->localizedName() }}</span>
                            <x-ui.risk-rating kind="likelihood" :value="$risk->likelihood" />
                            <x-ui.risk-rating kind="severity" :value="$risk->severity" />
                            @if ($canManage)
                                <x-ui.button
                                    tone="ghost" size="sm" icon="trash" class="size-8! text-status-critical!"
                                    wire:click="detachRisk({{ $risk->id }})"
                                    wire:loading.attr="disabled" wire:target="detachRisk"
                                    :aria-label="__('risks.remove_risk_name', ['name' => $risk->localizedName()])"
                                    data-test="detach-risk-{{ $risk->id }}"
                                />
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($canManage)
                @php
                    $options = $this->riskLibraryOptions;
                    $taken = $risksHere->pluck('library_risk_id')->all();
                @endphp
                <div data-test="risk-picker">
                    <x-ui.form-field :label="__('risks.pick_from_library')" for="risk-picker" :help="__('risks.pick_help')" :error="$errors->first('riskPicks')">
                        <x-ui.multi-select-popover in-modal :label="__('risks.pick_from_library')" id="risk-picker" class="form-box cursor-pointer" :searchable="$options->flatten(1)->count() > 7">
                            <x-slot:trigger>
                                <span class="min-w-0 flex-1 truncate">{{ $pickedIds === [] ? __('risks.pick_from_library') : __('common.count_selected', ['count' => count($pickedIds)]) }}</span>
                                <flux:icon.chevron-down variant="micro" class="size-4 shrink-0 text-secondary-medium dark:text-ink-muted" />
                            </x-slot:trigger>

                            @forelse ($options as $category => $entries)
                                @if ($category !== '')
                                    <p class="px-2 pt-2 type-label text-secondary-medium dark:text-ink-muted" x-show="optionSearch === ''">{{ $category }}</p>
                                @endif
                                @foreach ($entries as $entry)
                                    <label
                                        class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-white/[0.06]"
                                        wire:key="risk-picker-{{ $entry->id }}"
                                        data-picker-risk="{{ $entry->id }}"
                                        x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                                    >
                                        <input type="checkbox" wire:model.live="riskPicks" value="{{ $entry->id }}" class="size-4 shrink-0 accent-primary" />
                                        <span class="min-w-0 flex-1 truncate font-medium text-secondary-dark dark:text-ink" dir="auto">{{ $entry->localizedTitle() }}</span>
                                        @if (in_array($entry->id, $taken, true))
                                            <span class="text-[11px] font-semibold text-secondary-medium dark:text-ink-muted">{{ __('risks.already_here') }}</span>
                                        @endif
                                    </label>
                                @endforeach
                            @empty
                                <p class="px-2 py-3 text-sm text-secondary-medium dark:text-ink-muted">{{ __('risks.library_empty') }}</p>
                            @endforelse
                        </x-ui.multi-select-popover>
                    </x-ui.form-field>
                </div>
            @else
                <p class="type-meta text-secondary-medium dark:text-ink-muted" data-test="risks-read-only">{{ __('risks.drawer_read_only') }}</p>
            @endif
        </div>
    @endif

    @if ($canManage)
        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="attachRisks" data-test="attach-risks-button">
                {{ __('risks.attach_picked') }}
            </x-ui.button>
        </x-slot:actions>
    @endif
</x-ui.modal>
