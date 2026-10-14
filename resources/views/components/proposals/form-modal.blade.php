{{--
    The proposal form v2, rendered by every page that uses App\Livewire\Concerns\Proposals\ManagesProposalForm
    (proposals/index and proposals/show). Its wire:model bindings resolve against that page's component.

    Create chooses the projects (a server-searched picker, each row naming its center and city), their
    beneficiaries and the starting stage. Edit shows the linked projects with their beneficiaries only:
    links change on the proposal page's Projects tab. The budget is passed in only for a money holder.
--}}
@use('App\Enums\Currency')
@use('App\Enums\ProposalStatus')
@props([
    'open' => false,
    'editing' => false,
    'donors' => [],
    'pickerProjects' => [],
    'chosenProjects' => [],
    'linkedProjects' => [],
    'showsMoney' => false,
    'lockedDonorName' => null,
    'needsBeneficiariesReason' => false,
    'skipping' => false,
    'storedNumber' => null,
])

@php
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $projectRows = $editing ? $linkedProjects : $chosenProjects;
    $chosenCount = count($chosenProjects);
@endphp

<x-ui.modal
    wire:model="showProposalModal"
    size="xl"
    :title="$editing ? __('proposals.edit_proposal') : __('proposals.add_proposal')"
    :description="$editing ? __('proposals.status_moves_only_through_change_status') : __('proposals.a_new_proposal_starts_at_the_concept_note')"
    submit="saveProposal"
    close="closeProposalModal"
    required-note
>
    @if ($open)
        <x-ui.form-section :title="__('common.section_details')" :fields="['name', 'internalProjectNumber', 'donorId', 'notes']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-input wire:model="name" :label="__('proposals.proposal_name')" maxlength="255" required autofocus class="@2xl/modal:col-span-2" />

                <div class="min-w-0">
                    <x-ui.form-input wire:model="internalProjectNumber" :label="__('common.internal_project_number')" maxlength="50" dir="ltr" required />
                    @if ($editing && filled($storedNumber))
                        <x-ui.project-number :number="$storedNumber" kind="internal" class="mt-1.5 text-xs {{ $mutedText }}" />
                    @endif
                </div>

                @if ($lockedDonorName !== null)
                    <x-ui.form-field :label="__('funding_agreements.donor')" for="proposal-locked-donor" :help="__('proposals.the_donor_cannot_change_after_the_concept_note')" :error="$errors->first('donorId')">
                        <div id="proposal-locked-donor" class="form-box" data-locked data-locked-donor>
                            <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0 text-secondary-light" />
                            <span class="truncate">{{ $lockedDonorName }}</span>
                        </div>
                    </x-ui.form-field>
                @else
                    <x-ui.form-select wire:model="donorId" :label="__('funding_agreements.donor')" :placeholder="__('funding_agreements.select_a_donor')" required>
                        @foreach ($donors as $donor)
                            <option value="{{ $donor->id }}">{{ $donor->name }}</option>
                        @endforeach
                    </x-ui.form-select>
                @endif

                <x-ui.form-textarea wire:model="notes" :label="__('common.notes')" rows="2" class="@2xl/modal:col-span-2" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section
            :title="__('proposals.section_projects')"
            :hint="$editing ? __('proposals.links_change_in_the_projects_tab') : __('proposals.beneficiaries_can_wait_until_the_proposal_review')"
            :fields="['projectIds', 'beneficiaries', 'beneficiariesReason', ...array_map(fn (array $row): string => 'beneficiaries.'.$row['id'], $projectRows)]"
        >
            <div class="grid gap-y-3">
                @unless ($editing)
                    <x-ui.form-field :label="__('proposals.projects_label')" for="proposal-project-picker" required :error="$errors->first('projectIds') ?: $errors->first('beneficiaries')">
                        <x-ui.multi-select-popover
                            in-modal
                            :searchable="false"
                            :label="__('proposals.choose_projects')"
                            id="proposal-project-picker"
                            class="form-box cursor-pointer"
                            data-test="proposal-project-picker"
                        >
                            <x-slot:trigger>
                                <span class="min-w-0 flex-1 truncate">{{ $chosenCount > 0 ? __('common.count_selected', ['count' => $chosenCount]) : __('proposals.choose_projects') }}</span>
                                <flux:icon.chevron-down variant="micro" class="size-4 shrink-0 {{ $mutedText }}" />
                            </x-slot:trigger>

                            <div class="border-b border-secondary-soft p-1.5 dark:border-line">
                                <x-ui.input type="search" size="sm" icon="magnifying-glass" wire:model.live.debounce.300ms="projectSearch" x-on:keydown.enter.prevent :aria-label="__('proposals.search_projects')" :placeholder="__('proposals.search_projects')" />
                            </div>

                            @forelse ($pickerProjects as $row)
                                <label class="flex cursor-pointer items-start gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-white/[0.06]" wire:key="proposal-picker-{{ $row['id'] }}" data-picker-project="{{ $row['id'] }}">
                                    <input type="checkbox" wire:model.live="projectIds" value="{{ $row['id'] }}" class="mt-0.5 size-4 shrink-0 accent-primary" />
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-secondary-dark dark:text-ink">{{ $row['name'] }}</span>
                                        <span class="block truncate text-xs {{ $mutedText }}">{{ $row['place'] }}</span>
                                    </span>
                                </label>
                            @empty
                                <p class="px-2 py-3 text-sm {{ $mutedText }}">{{ __('proposals.no_linkable_project_matches') }}</p>
                            @endforelse
                        </x-ui.multi-select-popover>
                    </x-ui.form-field>
                @endunless

                @if ($projectRows !== [])
                    <ul class="grid gap-2" data-proposal-projects>
                        @foreach ($projectRows as $row)
                            <li class="flex flex-wrap items-end gap-x-3 gap-y-2 rounded-lg border border-secondary-soft p-3 dark:border-line" wire:key="proposal-project-{{ $row['id'] }}" data-proposal-project="{{ $row['id'] }}">
                                <div class="min-w-0 flex-1 self-center">
                                    <p class="truncate text-sm font-semibold text-secondary-dark dark:text-ink">{{ $row['name'] }}</p>
                                    <p class="truncate text-xs {{ $mutedText }}">{{ $row['place'] }}</p>
                                </div>
                                <x-ui.form-input wire:model="beneficiaries.{{ $row['id'] }}" :label="__('proposals.target_beneficiaries')" type="number" min="1" step="1" inputmode="numeric" class="w-36" />
                                @unless ($editing)
                                    <x-ui.button type="button" tone="ghost" size="sm" icon="x-mark" class="size-10! shrink-0" wire:click="removeChosenProject({{ $row['id'] }})" :aria-label="__('proposals.remove_project_name', ['name' => $row['name']])" />
                                @endunless
                            </li>
                        @endforeach
                    </ul>
                @elseif ($editing)
                    <p class="text-sm {{ $mutedText }}">{{ __('proposals.no_projects_linked') }}</p>
                @endif

                @if ($needsBeneficiariesReason)
                    <x-ui.form-textarea wire:model="beneficiariesReason" :label="__('proposals.beneficiaries_reason')" :help="__('proposals.beneficiaries_reason_help')" rows="2" />
                @endif
            </div>
        </x-ui.form-section>

        @if ($showsMoney)
            <x-ui.form-section :title="__('proposals.section_budget')" :fields="['requestedBudget', 'requestedCurrency']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-input wire:model="requestedBudget" :label="__('proposals.requested_budget')" type="number" step="0.01" min="0" dir="ltr" />
                    <x-ui.form-segmented
                        wire:model="requestedCurrency"
                        :label="__('common.currency')"
                        :options="collect(Currency::cases())->map(fn (Currency $currencyCase): array => ['value' => $currencyCase->value, 'label' => $currencyCase->value])->all()"
                    />
                </div>
            </x-ui.form-section>
        @endif

        <x-ui.form-section :title="__('common.section_schedule')" :fields="['plannedDateBegin', 'plannedDateEnd']">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                <x-ui.form-input wire:model="plannedDateBegin" :label="__('proposals.planned_start')" type="date" required />
                <x-ui.form-input wire:model="plannedDateEnd" :label="__('proposals.planned_end')" type="date" required />
            </div>
        </x-ui.form-section>

        @unless ($editing)
            <x-ui.form-section :title="__('proposals.section_start')" :fields="['startingStage', 'skipReason']">
                <div class="grid gap-y-3">
                    <x-ui.form-segmented
                        wire:model.live="startingStage"
                        :label="__('proposals.start_at')"
                        required
                        :options="[
                            ['value' => ProposalStatus::ConceptNote->value, 'label' => ProposalStatus::ConceptNote->label(), 'test' => 'start-concept-note'],
                            ['value' => ProposalStatus::ProposalDraft->value, 'label' => ProposalStatus::ProposalDraft->label(), 'test' => 'start-proposal-draft'],
                        ]"
                    />

                    @if ($skipping)
                        <x-ui.form-textarea wire:model="skipReason" :label="__('proposals.skip_reason')" required rows="2" />
                        <div>
                            <x-ui.button type="button" size="sm" icon="document-text" wire:click="useSkipPreset" data-test="use-skip-preset">{{ __('proposals.use_the_standard_reason') }}</x-ui.button>
                        </div>
                    @endif
                </div>
            </x-ui.form-section>
        @endunless
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="save-proposal-button">
            {{ $editing ? __('common.save_changes') : __('proposals.create_proposal') }}
        </x-ui.button>
    </x-slot:actions>
</x-ui.modal>
