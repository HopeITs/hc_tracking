{{-- The Pillars section of strategies/show (T32, D-35); logic in ManagesStrategicPillars. --}}
@php
    $groups = $this->pillarGroups;
    $canManagePillars = auth()->user()->can('strategies.update');
@endphp

<x-ui.panel :title="__('strategies.pillars')" :subtitle="__('strategies.pillars_intro')" :count="count($groups['pillars'])" data-tour="strategy-pillars" data-test="strategy-pillars">
    @if ($canManagePillars)
        <x-slot:toolbar>
            <x-ui.button tone="primary" icon="plus" size="sm" wire:click="openCreatePillarModal" wire:loading.attr="disabled" wire:target="openCreatePillarModal" data-test="create-pillar">
                {{ __('strategies.add_pillar') }}
            </x-ui.button>
        </x-slot:toolbar>
    @endif

    @if ($groups['pillars'] === [] && $groups['unassigned'] === [])
        <x-ui.empty-state
            icon="building-library"
            :heading="__('strategies.no_pillars_yet')"
            :body="$canManagePillars ? __('strategies.no_pillars_body_editor') : __('strategies.no_pillars_body_viewer')"
        />
    @else
        <div class="grid gap-3 p-4 @2xl/detail:grid-cols-2 @5xl/detail:grid-cols-3">
            @foreach ($groups['pillars'] as $group)
                @php
                    $pillar = $group['pillar'];
                @endphp
                <article class="flex min-w-0 flex-col gap-3 rounded-lg border border-secondary-soft p-3 dark:border-line" wire:key="pillar-{{ $pillar->id }}" data-test="pillar-card-{{ $pillar->id }}">
                    <header class="flex items-start gap-2">
                        <span class="inline-flex shrink-0 items-center rounded-md bg-secondary-dark px-2 py-0.5 text-xs font-bold text-white dark:bg-ink dark:text-surface-0" dir="ltr">{{ $pillar->code }}</span>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-sm font-bold text-secondary-dark dark:text-ink" dir="auto">{{ $pillar->localizedName() }}</h3>
                            @if ($pillar->localizedGoal() !== null)
                                <p class="type-meta text-secondary-medium dark:text-ink-muted" dir="auto">{{ $pillar->localizedGoal() }}</p>
                            @endif
                        </div>
                        @if ($canManagePillars)
                            <div class="flex shrink-0 items-center">
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" wire:click="openEditPillarModal({{ $pillar->id }})" :aria-label="__('strategies.edit_pillar')" data-test="edit-pillar-{{ $pillar->id }}" />
                                <x-ui.button tone="ghost" size="sm" icon="trash" wire:click="confirmDeletePillar({{ $pillar->id }})" :aria-label="__('strategies.delete_pillar')" data-test="delete-pillar-{{ $pillar->id }}" />
                            </div>
                        @endif
                    </header>

                    @if ($group['programs'] === [])
                        <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('strategies.pillar_no_programs') }}</p>
                    @else
                        <ul class="flex flex-col gap-1.5">
                            @foreach ($group['programs'] as $program)
                                @include('pages.strategies._show-pillar-program', ['program' => $program])
                            @endforeach
                        </ul>
                    @endif
                </article>
            @endforeach

            <article class="flex min-w-0 flex-col gap-3 rounded-lg border border-dashed border-secondary-light p-3 dark:border-line-strong" data-test="pillar-card-unassigned">
                <header class="flex items-start gap-2">
                    <span class="inline-flex shrink-0 items-center gap-1 rounded-md bg-secondary-soft px-2 py-0.5 text-xs font-semibold text-secondary-dark dark:bg-surface-2 dark:text-ink">
                        <flux:icon.minus-circle variant="micro" class="size-3.5" aria-hidden="true" />
                        {{ __('strategies.no_pillar') }}
                    </span>
                    <p class="type-meta min-w-0 flex-1 text-secondary-medium dark:text-ink-muted">{{ __('strategies.no_pillar_help') }}</p>
                </header>

                @if ($groups['unassigned'] === [])
                    <p class="type-meta text-secondary-medium dark:text-ink-muted">—</p>
                @else
                    <ul class="flex flex-col gap-1.5">
                        @foreach ($groups['unassigned'] as $program)
                            @include('pages.strategies._show-pillar-program', ['program' => $program])
                        @endforeach
                    </ul>
                @endif
            </article>
        </div>
    @endif
</x-ui.panel>

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
        wire:model="showDeletePillarModal"
        close="cancelDeletePillar"
        :title="__('strategies.delete_pillar')"
        :message="__('strategies.delete_pillar_message')"
        confirm="deletePillar"
        :confirm-label="__('strategies.delete_pillar')"
        confirm-test="confirm-delete-pillar"
    />
@endif
