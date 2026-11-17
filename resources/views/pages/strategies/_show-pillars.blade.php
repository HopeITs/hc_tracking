{{--
    "How the strategy is built" on strategies/show (logic in
    ManagesStrategicPillars): each pillar as a typographic section with its
    programs as rows (the first three, then a "Show N more" toggle), then the
    programs under no pillar. Rows read pillarGroups(), which reads
    allPrograms(). Pillar dialogs at the end.
--}}
@php
    $groups = $this->pillarGroups;
    $canManagePillars = auth()->user()->can('strategies.update');
    $unassignedCount = count($groups['unassigned']);
    $visibleRows = 3;
@endphp

<section class="dk-build" aria-labelledby="strategy-build-heading" data-tour="strategy-pillars" data-test="strategy-pillars">
    <header class="dk-build-h">
        <div class="min-w-0">
            <h2 class="dk-build-title" id="strategy-build-heading">{{ __('strategies.how_built') }}</h2>
            <p class="dk-build-intro">{{ __('strategies.how_built_intro') }}</p>
        </div>
        @if ($canManagePillars)
            <button type="button" class="dk-btn" wire:click="openCreatePillarModal" wire:loading.attr="disabled" wire:target="openCreatePillarModal" data-test="create-pillar"><x-desk.icon name="plus" />{{ __('strategies.add_pillar') }}</button>
        @endif
    </header>

    @if ($this->allPrograms->isEmpty())
        <div class="dk-build-none" data-test="strategy-no-programs">
            <p>{{ __('strategies.no_programs_linked_here') }}</p>
            @if ($this->canLinkPrograms)
                <button type="button" class="dk-btn" wire:click="openProgramsModal({{ $this->strategyId }})" data-test="strategy-empty-link-programs">{{ __('strategies.link_programs') }}</button>
            @endif
        </div>
    @endif

    @if ($unassignedCount > 0)
        <p class="dk-build-note" data-test="unassigned-notice">
            <span>{!! trans_choice('strategies.unassigned_notice', $unassignedCount, ['count' => '<bdi>'.$unassignedCount.'</bdi>']) !!}</span>
            <a href="#unassigned-programs" class="dk-slink">{{ __('strategies.assign_them') }}<x-desk.icon name="arrow" class="dk-flip" /></a>
        </p>
    @endif

    @if ($groups['pillars'] === [])
        <p class="dk-build-empty">{{ $canManagePillars ? __('strategies.no_pillars_body_editor') : __('strategies.no_pillars_body_viewer') }}</p>
    @else
        <div class="dk-pillars">
            @foreach ($groups['pillars'] as $group)
                @php
                    $pillar = $group['pillar'];
                    $programCount = count($group['programs']);
                    $hiddenCount = max(0, $programCount - $visibleRows);
                @endphp
                <article class="dk-pillar" wire:key="pillar-{{ $pillar->id }}" x-data="{ more: false }" aria-labelledby="pillar-{{ $pillar->id }}-name" data-test="pillar-card-{{ $pillar->id }}">
                    <header class="dk-pillar-h">
                        <div class="min-w-0">
                            <h3 class="dk-pillar-name" id="pillar-{{ $pillar->id }}-name">
                                <span class="dk-pcode" dir="ltr">{{ $pillar->code }}</span>
                                <span dir="auto">{{ $pillar->localizedName() }}</span>
                            </h3>
                            @if ($pillar->localizedGoal() !== null)
                                <p class="dk-pillar-goal" dir="auto">{{ $pillar->localizedGoal() }}</p>
                            @endif
                        </div>
                        <div class="dk-pillar-meta">
                            <span class="dk-num">{{ trans_choice('strategies.count_program', $programCount, ['count' => $programCount]) }}</span>
                            @if ($canManagePillars)
                                <button type="button" class="dk-slink" wire:click="openEditPillarModal({{ $pillar->id }})" aria-label="{{ __('strategies.edit_pillar_named', ['name' => $pillar->code]) }}" data-test="edit-pillar-{{ $pillar->id }}">{{ __('strategies.edit_pillar') }}</button>
                                <button type="button" class="dk-slink dk-danger" wire:click="confirmDeletePillar({{ $pillar->id }})" aria-label="{{ __('strategies.delete_pillar_named', ['name' => $pillar->code]) }}" data-test="delete-pillar-{{ $pillar->id }}">{{ __('strategies.delete_pillar') }}</button>
                            @endif
                        </div>
                    </header>

                    @if ($group['programs'] === [])
                        <p class="dk-build-empty">{{ __('strategies.pillar_no_programs') }}</p>
                    @else
                        <ul class="dk-sprows">
                            @foreach ($group['programs'] as $program)
                                @include('pages.strategies._show-pillar-program', ['program' => $program, 'folded' => $loop->index >= $visibleRows, 'assignLabel' => 'move'])
                            @endforeach
                        </ul>

                        @if ($hiddenCount > 0)
                            <button
                                type="button"
                                class="dk-slink"
                                x-on:click="more = ! more"
                                x-bind:aria-expanded="more ? 'true' : 'false'"
                                x-text="more ? @js(__('strategies.show_fewer')) : @js(trans_choice('strategies.show_more_programs', $hiddenCount, ['count' => $hiddenCount]))"
                                aria-expanded="false"
                                data-test="pillar-show-more-{{ $pillar->id }}"
                            >{{ trans_choice('strategies.show_more_programs', $hiddenCount, ['count' => $hiddenCount]) }}</button>
                        @endif
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    @if ($unassignedCount > 0)
        <article class="dk-pillar dk-pillar-none" id="unassigned-programs" aria-labelledby="unassigned-programs-name" data-test="pillar-card-unassigned">
            <header class="dk-pillar-h">
                <div class="min-w-0">
                    <h3 class="dk-pillar-name" id="unassigned-programs-name">{{ __('strategies.not_under_pillar') }}</h3>
                    <p class="dk-pillar-goal">{{ __('strategies.no_pillar_help') }}</p>
                </div>
                <div class="dk-pillar-meta">
                    <span class="dk-num">{{ trans_choice('strategies.count_program', $unassignedCount, ['count' => $unassignedCount]) }}</span>
                </div>
            </header>

            <ul class="dk-sprows">
                @foreach ($groups['unassigned'] as $program)
                    @include('pages.strategies._show-pillar-program', ['program' => $program, 'folded' => false, 'assignLabel' => 'choose'])
                @endforeach
            </ul>
        </article>
    @endif
</section>

@if ($canManagePillars)
    <x-ui.modal
        wire:model="showPillarModal"
        :title="$editingPillarId === null ? __('strategies.add_pillar') : __('strategies.edit_pillar')"
        submit="savePillar"
        close="closePillarModal"
        required-note
    >
        @if ($showPillarModal)
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-input wire:model="pillarCode" :label="__('strategies.pillar_code')" :help="__('strategies.pillar_code_help')" maxlength="20" dir="ltr" required autofocus />
                <x-ui.form-input wire:model="pillarSortOrder" type="number" min="0" step="1" :label="__('strategies.pillar_sort_order')" />
                <x-ui.form-input wire:model="pillarNameAr" :label="__('strategies.pillar_name_ar')" maxlength="255" dir="rtl" required class="@2xl/modal:col-span-2" />
                <x-ui.form-input wire:model="pillarNameEn" :label="__('strategies.pillar_name_en')" maxlength="255" dir="ltr" class="@2xl/modal:col-span-2" />
                <x-ui.form-textarea wire:model="pillarGoalAr" :label="__('strategies.pillar_goal_ar')" maxlength="2000" dir="rtl" class="@2xl/modal:col-span-2" />
                <x-ui.form-textarea wire:model="pillarGoalEn" :label="__('strategies.pillar_goal_en')" maxlength="2000" dir="ltr" class="@2xl/modal:col-span-2" />
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-pillar">{{ __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.modal
        wire:model="showAssignModal"
        size="sm"
        :title="__('strategies.assign_pillar')"
        :description="__('strategies.assign_pillar_help')"
        submit="assignProgram"
        close="closeAssignModal"
    >
        @if ($showAssignModal)
            <x-ui.form-select wire:model="assignPillarId" :label="__('strategies.choose_pillar')" :placeholder="__('strategies.no_pillar')">
                <option value="">{{ __('strategies.no_pillar') }}</option>
                @foreach ($this->pillars as $pillar)
                    <option value="{{ $pillar->id }}">{{ $pillar->code }} · {{ $pillar->localizedName() }}</option>
                @endforeach
            </x-ui.form-select>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-program-pillar">{{ __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        permanent
        wire:model="showDeletePillarModal"
        close="cancelDeletePillar"
        :title="__('strategies.delete_pillar')"
        :message="__('strategies.delete_pillar_message')"
        confirm="deletePillar"
        :confirm-label="__('strategies.delete_pillar')"
        confirm-test="confirm-delete-pillar"
    />
@endif
