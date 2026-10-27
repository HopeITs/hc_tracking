{{--
    The proposal form v2, rendered by every page that uses App\Livewire\Concerns\Proposals\ManagesProposalForm
    (proposals/index and proposals/show). Its wire:model bindings resolve against that page's component.

    Sections, in reading and tab order: basics; donor and money; projects and beneficiaries; planned dates;
    starting stage (create only). Create chooses the projects (a server-searched picker, each row naming its
    center, program and city) with a beneficiaries input beside each chosen one. Edit shows the linked
    projects with their beneficiaries only: links change on the proposal page's Projects tab. The budget is
    rendered only for a money holder; for anyone else it is absent, not hidden.
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
    'renewable' => [],
    'renewal' => null,
])

@php
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $isRenewal = $renewal !== null;
    $projectRows = $editing ? $linkedProjects : ($isRenewal ? $renewal['projects'] : $chosenProjects);
    $chosenCount = count($chosenProjects);
    $projectsError = $errors->first('projectIds') ?: $errors->first('beneficiaries');
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
        @if ($errors->any())
            <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-status-critical bg-status-critical-soft px-3 py-2 text-sm" role="alert" data-form-error-summary>
                <flux:icon.exclamation-circle variant="micro" class="size-4 shrink-0 text-status-critical" aria-hidden="true" />
                <p class="min-w-0 flex-1 font-semibold text-status-critical">{{ trans_choice('common.fields_need_attention', $errors->count(), ['count' => $errors->count()]) }}</p>
                <button
                    type="button"
                    class="text-sm font-semibold text-secondary-dark underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium dark:text-ink"
                    x-on:click="const field = $el.closest('form').querySelector('[aria-invalid=true]'); field?.scrollIntoView({ block: 'center' }); field?.focus({ preventScroll: true })"
                >{{ __('proposals.go_to_the_first_problem') }}</button>
            </div>
        @endif

        @if (! $editing && $renewable !== [])
            <x-ui.form-section :title="__('proposals.start_from_previous')" :fields="['previousProposalId']" data-test="renewal-section">
                <div class="grid gap-y-3">
                    <x-ui.form-select wire:model.live="previousProposalId" :label="__('proposals.previous_proposal_label')" :placeholder="__('proposals.choose_previous')" :help="__('proposals.choose_previous')" data-test="previous-proposal-select">
                        @foreach ($renewable as $option)
                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </x-ui.form-select>

                    @if ($isRenewal)
                        <div class="grid gap-3 rounded-lg border border-secondary-soft bg-secondary-soft/40 p-3 text-sm @2xl/modal:grid-cols-2 dark:border-line dark:bg-surface-2" role="status" data-test="renewal-preview">
                            <div>
                                <p class="font-semibold text-secondary-dark dark:text-ink">{{ __('proposals.renewal_will_copy') }}</p>
                                <ul class="mt-1 list-disc space-y-0.5 ps-5 {{ $mutedText }}">
                                    <li>{{ __('proposals.renewal_copy_basics') }}</li>
                                    <li>{{ __('proposals.renewal_copy_framework') }} ({{ $renewal['results'] }})</li>
                                    <li>{{ __('proposals.renewal_copy_plan') }} ({{ $renewal['milestones'] }} / {{ $renewal['tasks'] }})</li>
                                </ul>
                            </div>
                            <div>
                                <p class="font-semibold text-secondary-dark dark:text-ink">{{ __('proposals.renewal_will_not_copy') }}</p>
                                <p class="mt-1 {{ $mutedText }}">{{ __('proposals.renewal_not_copied_list') }}</p>
                            </div>
                            @if ($renewal['existingDraft'] !== null)
                                <p class="flex items-start gap-2 rounded-lg bg-status-warn-soft px-3 py-2 text-status-warn @2xl/modal:col-span-2" role="status" data-test="renewal-draft-exists">
                                    <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                    <span dir="auto">{{ __('proposals.renewal_draft_exists', ['number' => $renewal['existingDraft']]) }}</span>
                                </p>
                            @endif
                        </div>
                    @endif
                </div>
            </x-ui.form-section>
        @endif

        <x-ui.form-section :title="__('proposals.section_basics')" :fields="['name', 'internalProjectNumber', 'notes']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-input wire:model="name" :label="__('proposals.proposal_name')" maxlength="255" required autofocus dir="auto" />

                <div class="min-w-0">
                    <x-ui.form-input wire:model="internalProjectNumber" :label="__('common.internal_project_number')" maxlength="50" dir="ltr" required />
                    @if ($editing && filled($storedNumber))
                        <x-ui.project-number :number="$storedNumber" kind="internal" class="mt-1.5 text-xs {{ $mutedText }}" />
                    @endif
                </div>

                <x-ui.form-textarea wire:model="notes" :label="__('common.notes')" rows="2" dir="auto" class="@2xl/modal:col-span-2" />
            </div>
        </x-ui.form-section>

        @unless ($isRenewal)
        <x-ui.form-section :title="$showsMoney ? __('proposals.section_donor_and_budget') : __('proposals.section_donor')" :fields="['donorId', 'requestedBudget', 'requestedCurrency']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                @if ($lockedDonorName !== null)
                    <x-ui.form-field :label="__('funding_agreements.donor')" for="proposal-locked-donor" :help="__('proposals.the_donor_cannot_change_after_the_concept_note')" :error="$errors->first('donorId')" class="@2xl/modal:col-span-2">
                        <div id="proposal-locked-donor" class="form-box" data-locked data-locked-donor>
                            <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0 text-secondary-light" />
                            <span class="truncate" dir="auto">{{ $lockedDonorName }}</span>
                        </div>
                    </x-ui.form-field>
                @else
                    <x-ui.form-select wire:model="donorId" :label="__('funding_agreements.donor')" :placeholder="__('funding_agreements.select_a_donor')" required class="@2xl/modal:col-span-2">
                        @foreach ($donors as $donor)
                            <option value="{{ $donor->id }}">{{ $donor->name }}</option>
                        @endforeach
                    </x-ui.form-select>
                @endif

                @if ($showsMoney)
                    <x-ui.form-input wire:model="requestedBudget" :label="__('proposals.requested_budget')" :help="__('proposals.requested_budget_help')" type="number" step="0.01" min="0" dir="ltr" />
                    <x-ui.form-segmented
                        wire:model="requestedCurrency"
                        :label="__('common.currency')"
                        :options="collect(Currency::cases())->map(fn (Currency $currencyCase): array => ['value' => $currencyCase->value, 'label' => $currencyCase->value])->all()"
                    />
                @endif
            </div>
        </x-ui.form-section>
        @endunless

        <x-ui.form-section
            :title="__('proposals.section_projects')"
            :hint="$editing ? __('proposals.links_change_in_the_projects_tab') : __('proposals.beneficiaries_can_wait_until_the_proposal_review')"
            :fields="['projectIds', 'beneficiaries', 'beneficiariesReason', ...array_map(fn (array $row): string => 'beneficiaries.'.$row['id'], $projectRows)]"
        >
            <div class="grid gap-y-3">
                @unless ($editing || $isRenewal)
                    <x-ui.form-field :label="__('proposals.projects_label')" for="proposal-project-picker" required :help="__('proposals.projects_label_help')" :error="$projectsError">
                        <x-ui.multi-select-popover
                            in-modal
                            :searchable="false"
                            :label="__('proposals.choose_projects')"
                            id="proposal-project-picker"
                            class="form-box cursor-pointer"
                            :aria-invalid="$projectsError !== '' ? 'true' : null"
                            data-test="proposal-project-picker"
                        >
                            <x-slot:trigger>
                                <span class="min-w-0 flex-1 truncate">{{ $chosenCount > 0 ? __('common.count_selected', ['count' => $chosenCount]) : __('proposals.choose_projects') }}</span>
                                <flux:icon.chevron-down variant="micro" class="size-4 shrink-0 {{ $mutedText }}" />
                            </x-slot:trigger>

                            <div class="border-b border-secondary-soft p-1.5 dark:border-line">
                                <x-ui.input type="search" size="sm" icon="magnifying-glass" wire:model.live.debounce.300ms="projectSearch" x-on:keydown.enter.prevent :aria-label="__('proposals.search_projects')" :placeholder="__('proposals.search_projects')" />
                            </div>

                            <div wire:loading.class="opacity-60" wire:target="projectSearch">
                                @forelse ($pickerProjects as $row)
                                    <label class="flex cursor-pointer items-start gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-white/[0.06]" wire:key="proposal-picker-{{ $row['id'] }}" data-picker-project="{{ $row['id'] }}">
                                        <input type="checkbox" wire:model.live="projectIds" value="{{ $row['id'] }}" class="mt-0.5 size-4 shrink-0 accent-primary" />
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium text-secondary-dark dark:text-ink" dir="auto">{{ $row['name'] }}</span>
                                            <span class="block truncate text-xs {{ $mutedText }}">{{ $row['place'] }}</span>
                                        </span>
                                    </label>
                                @empty
                                    <p class="px-2 py-3 text-sm {{ $mutedText }}">{{ __('proposals.no_linkable_project_matches') }}</p>
                                @endforelse
                            </div>
                        </x-ui.multi-select-popover>
                    </x-ui.form-field>
                @endunless

                @if ($projectRows !== [])
                    <ul class="grid gap-2" data-proposal-projects>
                        @foreach ($projectRows as $row)
                            <li class="flex flex-wrap items-end gap-x-3 gap-y-2 rounded-lg border border-secondary-soft p-3 dark:border-line" wire:key="proposal-project-{{ $row['id'] }}" data-proposal-project="{{ $row['id'] }}">
                                <div class="min-w-0 flex-1 basis-48 self-center">
                                    <p class="truncate text-sm font-semibold text-secondary-dark dark:text-ink" dir="auto">{{ $row['name'] }}</p>
                                    <p class="truncate text-xs {{ $mutedText }}">{{ $row['place'] }}</p>
                                </div>
                                @unless ($isRenewal)
                                    <x-ui.form-input wire:model="beneficiaries.{{ $row['id'] }}" :label="__('proposals.target_beneficiaries')" type="number" min="1" step="1" inputmode="numeric" dir="ltr" class="w-36" />
                                @endunless
                                @unless ($editing || $isRenewal)
                                    <x-ui.button type="button" tone="ghost" size="sm" icon="x-mark" class="size-10! shrink-0" wire:click="removeChosenProject({{ $row['id'] }})" :aria-label="__('proposals.remove_project_name', ['name' => $row['name']])" />
                                @endunless
                            </li>
                        @endforeach
                    </ul>
                @elseif ($editing)
                    <p class="text-sm {{ $mutedText }}">{{ __('proposals.no_projects_linked') }}</p>
                @endif

                @if ($needsBeneficiariesReason)
                    <x-ui.form-textarea wire:model="beneficiariesReason" :label="__('proposals.beneficiaries_reason')" :help="__('proposals.beneficiaries_reason_help')" rows="2" dir="auto" />
                @endif
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('proposals.section_planned_dates')" :fields="['plannedDateBegin', 'plannedDateEnd']">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                <x-ui.form-input wire:model="plannedDateBegin" :label="__('proposals.planned_start')" type="date" required />
                <x-ui.form-input wire:model="plannedDateEnd" :label="__('proposals.planned_end')" type="date" required />
            </div>
        </x-ui.form-section>

        @unless ($editing || $isRenewal)
            <x-ui.form-section :title="__('proposals.section_start')" :fields="['startingStage', 'skipReason']">
                <div class="grid gap-y-3">
                    <x-ui.form-segmented
                        wire:model.live="startingStage"
                        :label="__('proposals.start_at')"
                        :help="__('proposals.start_at_help')"
                        required
                        :options="[
                            ['value' => ProposalStatus::ConceptNote->value, 'label' => ProposalStatus::ConceptNote->label(), 'test' => 'start-concept-note'],
                            ['value' => ProposalStatus::ProposalDraft->value, 'label' => ProposalStatus::ProposalDraft->label(), 'test' => 'start-proposal-draft'],
                        ]"
                    />

                    @if ($skipping)
                        <x-ui.form-textarea wire:model="skipReason" :label="__('proposals.skip_reason')" required rows="2" dir="auto" />
                        <div>
                            <x-ui.button type="button" size="sm" icon="document-text" wire:click="useSkipPreset" data-test="use-skip-preset">{{ __('proposals.use_the_standard_reason') }}</x-ui.button>
                        </div>
                    @endif
                </div>
            </x-ui.form-section>
        @endunless
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="saveProposal" data-test="save-proposal-button">
            <span wire:loading.remove wire:target="saveProposal">{{ $editing ? __('common.save_changes') : __('proposals.create_proposal') }}</span>
            <span wire:loading wire:target="saveProposal" class="inline-flex items-center gap-1.5">
                <flux:icon.loading variant="micro" class="size-4" aria-hidden="true" />
                {{ __('proposals.saving') }}
            </span>
        </x-ui.button>
    </x-slot:actions>
</x-ui.modal>
