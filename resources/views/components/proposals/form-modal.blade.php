{{--
    The proposal form v2, rendered by every page that uses App\Livewire\Concerns\Proposals\ManagesProposalForm
    (proposals/index and proposals/show). Its wire:model bindings resolve against that page's component.

    Sections, in reading and tab order (D-62, W-10): identity (renewal preset, name, number); donor;
    locations; concept note; planned dates; budget; starting stage (create only); notes last. Reordering
    the markup is what sets the tab order, so keep the submit button in the actions slot, after everything.

    Locations holds the location rows (W-34a slice 3, EditsProposalLocations): city, program or a new
    program request, responsible and centers per row. A row of a linked project shows its city and
    program locked. The concept note (W-24) offers the text and the document; neither is required, and the
    document is filed on the saved proposal, so a renewal — whose contents are copied, not typed — does
    not show the section at all.

    W-32: a kind switch opens Identity on create (project or payroll, one line each). A payroll proposal
    shows a Narrative section after Donor and leaves out Locations, the starting stage and the renewal
    preset; its attachment is filed on the proposal's Documents tab. On edit the kind is a locked chip.

    Below the rows, create shows the project it was opened from (New Concept Note) and edit the linked
    projects, each with its beneficiaries input: links change on the proposal page's Projects tab. The budget is rendered only for a
    money holder; for anyone else the whole section is absent, not hidden.
--}}
@use('App\Enums\Currency')
@use('App\Enums\FileType')
@use('App\Enums\ProposalKind')
@use('App\Enums\ProposalStatus')
@use('Illuminate\Support\Str')
@props([
    'open' => false,
    'editing' => false,
    'donors' => [],
    'donorPending' => false,
    'canAddDonor' => false,
    'locations' => [],
    'cities' => [],
    'programs' => [],
    'centersByCity' => [],
    'chosenProjects' => [],
    'linkedProjects' => [],
    'showsMoney' => false,
    'lockedDonorName' => null,
    'needsBeneficiariesReason' => false,
    'skipping' => false,
    'storedNumber' => null,
    'renewable' => [],
    'renewal' => null,
    'kind' => 'project',
])

@php
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $isPayroll = $kind === ProposalKind::Payroll->value;
    $isRenewal = $renewal !== null && ! $isPayroll;
    $projectRows = $editing ? $linkedProjects : ($isRenewal ? $renewal['projects'] : $chosenProjects);
    $projectsError = $errors->first('projectIds') ?: $errors->first('beneficiaries');
    $locationsError = $errors->first('locations');

    /*
     * Which validation keys each section holds, in the order the sections
     * render (D-62). Defined once: every x-ui.form-section below takes its
     * `fields` from here, and the error summary names the sections that hold
     * a failure from the same map, so the two can never disagree.
     */
    $sectionFields = [
        __('proposals.section_identity') => ['kind', 'previousProposalId', 'name', 'internalProjectNumber'],
        __('proposals.section_donor') => ['donorId'],
        ...($isPayroll
            ? [__('proposals.section_narrative') => ['narrative']]
            : [
                __('proposals.section_locations') => [
                    'projectIds', 'locations', 'beneficiaries', 'beneficiariesReason',
                    ...array_map(fn (array $row): string => 'beneficiaries.'.$row['id'], $projectRows),
                    ...collect(array_keys($locations))->flatMap(fn (int $index): array => array_map(
                        fn (string $field): string => "locations.{$index}.{$field}",
                        ['city_id', 'program_id', 'responsible_employee_id', 'center_ids', 'new_program_name_en', 'new_program_name_ar'],
                    ))->all(),
                ],
                __('proposals.concept_note.section') => ['conceptNote', 'conceptNoteFile'],
            ]),
        __('proposals.section_planned_dates') => ['plannedDateBegin', 'plannedDateEnd'],
        __('proposals.section_budget') => ['requestedBudget', 'requestedCurrency'],
        ...($isPayroll ? [] : [__('proposals.section_start') => ['startingStage', 'skipReason']]),
        __('proposals.section_notes') => ['notes'],
    ];

    $failingSections = array_keys(array_filter(
        $sectionFields,
        fn (array $fields): bool => collect($fields)->contains(fn (string $field): bool => $errors->has($field)),
    ));
@endphp

<x-ui.modal
    wire:model="showProposalModal"
    size="xl"
    :title="$editing ? __('proposals.edit_proposal') : __('proposals.add_proposal')"
    :description="$editing ? __('proposals.status_moves_only_through_change_status') : ($isPayroll ? __('proposals.a_new_payroll_proposal_starts_as_a_draft') : __('proposals.a_new_proposal_starts_at_the_concept_note'))"
    submit="saveProposal"
    close="closeProposalModal"
    required-note
>
    @if ($open)
        @if ($errors->any())
            <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-status-critical bg-status-critical-soft px-3 py-2 text-sm" role="alert" data-form-error-summary>
                <flux:icon.exclamation-circle variant="micro" class="size-4 shrink-0 text-status-critical" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-status-critical">{{ trans_choice('common.fields_need_attention', $errors->count(), ['count' => $errors->count()]) }}</p>
                    @if ($failingSections !== [])
                        <p class="text-xs text-status-critical" data-form-error-sections>{{ __('proposals.form_error_summary_sections', ['sections' => implode(' · ', $failingSections)]) }}</p>
                    @endif
                </div>
                <button
                    type="button"
                    class="text-sm font-semibold text-secondary-dark underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium dark:text-ink"
                    x-on:click="const field = $el.closest('form').querySelector('[aria-invalid=true]'); field?.scrollIntoView({ block: 'center' }); field?.focus({ preventScroll: true })"
                >{{ __('proposals.go_to_the_first_problem') }}</button>
            </div>
        @endif

        {{-- 1 · Identity: the renewal preset first, then the name and the number.
             W-03 makes the number read-only; until then the editable field keeps this slot. --}}
        <x-ui.form-section :title="__('proposals.section_identity')" :fields="$sectionFields[__('proposals.section_identity')]" data-test="identity-section">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                @if ($editing)
                    <p class="flex flex-wrap items-center gap-2 text-sm {{ $mutedText }} @2xl/modal:col-span-2" data-test="proposal-kind-locked">
                        <span>{{ __('proposals.proposal_kind') }}</span>
                        <x-ui.enum-badge :case="ProposalKind::from($kind)" />
                        <span class="text-xs">{{ __('proposals.the_kind_cannot_change') }}</span>
                    </p>
                @else
                    <div class="@2xl/modal:col-span-2" data-test="proposal-kind-switch">
                        <x-ui.form-segmented
                            wire:model.live="kind"
                            :label="__('proposals.proposal_kind')"
                            required
                            :options="[
                                ['value' => ProposalKind::Project->value, 'label' => ProposalKind::Project->label(), 'test' => 'kind-project'],
                                ['value' => ProposalKind::Payroll->value, 'label' => ProposalKind::Payroll->label(), 'test' => 'kind-payroll'],
                            ]"
                        />
                        <ul class="mt-1.5 grid gap-0.5 text-xs {{ $mutedText }}">
                            <li><span class="font-semibold">{{ ProposalKind::Project->label() }}:</span> {{ __('proposals.kind_project_help') }}</li>
                            <li><span class="font-semibold">{{ ProposalKind::Payroll->label() }}:</span> {{ __('proposals.kind_payroll_help') }}</li>
                        </ul>
                    </div>
                @endif

                @if (! $editing && ! $isPayroll && $renewable !== [])
                <div class="grid gap-y-3 @2xl/modal:col-span-2" data-test="renewal-preset">
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
                @endif

                <x-ui.form-input wire:model="name" :label="__('proposals.proposal_name')" maxlength="255" required autofocus dir="auto" />

                @if ($editing && filled($storedNumber))
                <div class="min-w-0">
                        <x-ui.project-number :number="$storedNumber" kind="internal" class="mt-1.5 text-xs {{ $mutedText }}" />
                </div>
                @endif

                {{-- The number is generated, so its only error is a save that lost the race for it. --}}
                @error('internalProjectNumber')
                    <p class="text-sm text-status-critical @2xl/modal:col-span-2" role="alert" data-test="internal-number-error">{{ $message }}</p>
                @enderror
            </div>
        </x-ui.form-section>

        {{-- 2 · Donor. --}}
        @unless ($isRenewal)
        <x-ui.form-section :title="__('proposals.section_donor')" :fields="$sectionFields[__('proposals.section_donor')]">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                @if ($lockedDonorName !== null)
                    <x-ui.form-field :label="__('funding_agreements.donor')" for="proposal-locked-donor" :help="__('proposals.the_donor_cannot_change_after_the_concept_note')" :error="$errors->first('donorId')" class="@2xl/modal:col-span-2">
                        <div id="proposal-locked-donor" class="form-box" data-locked data-locked-donor>
                            <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0 text-secondary-light" />
                            <span class="truncate" dir="auto">{{ $lockedDonorName }}</span>
                        </div>
                    </x-ui.form-field>
                @else
                    <div class="flex flex-wrap items-end gap-2 @2xl/modal:col-span-2" data-donor-field>
                        <x-ui.form-select wire:model.live="donorId" id="proposal-donor" :label="__('funding_agreements.donor')" :placeholder="__('funding_agreements.select_a_donor')" required class="min-w-0 flex-1">
                            @foreach ($donors as $donor)
                                <option value="{{ $donor->id }}">{{ $donor->name }}@if ($donor->isPending()) — {{ __('donors.pending_badge') }}@endif</option>
                            @endforeach
                        </x-ui.form-select>

                        @if ($canAddDonor)
                            <x-ui.button type="button" tone="secondary" icon="plus" wire:click="$dispatch('open-donor-request')" data-test="add-donor-button">
                                {{ __('donors.add_donor') }}
                            </x-ui.button>
                        @endif
                    </div>

                    @if ($donorPending)
                        <div role="status" data-donor-pending class="flex items-start gap-2 rounded-md border border-status-warn bg-status-warn-soft px-3 py-2 text-sm @2xl/modal:col-span-2">
                            <flux:icon.clock variant="micro" class="mt-0.5 size-4 shrink-0" />
                            <span>{{ __('proposals.donor_request_pending_banner') }}</span>
                        </div>
                    @endif
                @endif
            </div>
        </x-ui.form-section>
        @endunless

        {{-- 2b · Narrative, payroll only (W-32): what the payroll covers. The
             attachment is filed on the Documents tab once the proposal exists. --}}
        @if ($isPayroll)
            <x-ui.form-section :title="__('proposals.section_narrative')" :fields="$sectionFields[__('proposals.section_narrative')]" data-test="narrative-section">
                <div class="grid gap-y-2">
                    <x-ui.form-textarea wire:model="narrative" :label="__('proposals.narrative_label')" :help="__('proposals.narrative_help')" required rows="4" dir="auto" />
                    @unless ($editing)
                        <p class="flex items-start gap-1.5 text-xs {{ $mutedText }}" data-test="payroll-attachment-hint">
                            <flux:icon.paper-clip variant="micro" class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                            <span>{{ __('proposals.payroll_attachment_hint') }}</span>
                        </p>
                    @endunless
                </div>
            </x-ui.form-section>
        @endif

        {{-- 3 · Locations (W-34a slice 3): where the proposal runs, one row per city and
             program, with the centers that run it. Absent for payroll. --}}
        @unless ($isPayroll)
        <x-ui.form-section
            :title="__('proposals.section_locations')"
            :hint="$editing ? __('proposals.locations_hint_edit') : __('proposals.locations_hint_create')"
            :fields="$sectionFields[__('proposals.section_locations')]"
            data-test="locations-section"
        >
            <div class="grid gap-y-3">
                @unless ($isRenewal)
                    @if ($locationsError !== '')
                        <p class="text-sm text-status-critical" role="alert" data-test="locations-error">{{ $locationsError }}</p>
                    @endif

                    @if ($locations === [] && $editing)
                        <p class="text-sm {{ $mutedText }}" data-test="locations-none-yet">{{ __('proposals.locations_none_yet') }}</p>
                    @endif

                    <ol class="grid gap-3" data-proposal-locations>
                        @foreach ($locations as $index => $location)
                            @php
                                $isLinked = $location['project'] !== null;
                                $cityCenters = $location['city_id'] === '' ? [] : ($centersByCity[(int) $location['city_id']] ?? []);
                                $isNewProgram = $location['program_id'] === 'new';
                            @endphp
                            <li class="grid gap-x-4 gap-y-3 rounded-lg border border-secondary-soft p-3 @2xl/modal:grid-cols-2 dark:border-line" wire:key="proposal-location-{{ $location['key'] }}" data-proposal-location="{{ $index }}">
                                <div class="flex items-center justify-between gap-2 @2xl/modal:col-span-2">
                                    <p class="min-w-0 truncate text-sm font-semibold text-secondary-dark dark:text-ink">
                                        {{ __('proposals.location_row_title', ['number' => $index + 1]) }}
                                        @if ($isLinked)
                                            <span class="ms-1 text-xs font-normal {{ $mutedText }}" dir="auto" data-test="location-linked-project">{{ __('proposals.location_linked_project', ['name' => $location['project']]) }}</span>
                                        @endif
                                    </p>
                                    @unless ($isLinked)
                                        <x-ui.button type="button" tone="ghost" size="sm" icon="x-mark" class="size-9! shrink-0" wire:click="removeLocationRow({{ $index }})" :aria-label="__('proposals.remove_location', ['number' => $index + 1])" data-test="remove-location" />
                                    @endunless
                                </div>

                                <x-ui.form-select wire:model.live="locations.{{ $index }}.city_id" :label="__('proposals.location_city')" :placeholder="__('proposals.location_select_city')" required :disabled="$isLinked" :locked-reason="$isLinked ? __('proposals.location_linked_place_locked') : null">
                                    @foreach ($cities as $city)
                                        <option value="{{ $city->id }}">{{ $city->name }}</option>
                                    @endforeach
                                </x-ui.form-select>

                                <x-ui.form-select wire:model.live="locations.{{ $index }}.program_id" :label="__('proposals.location_program')" :placeholder="__('proposals.location_select_program')" required :disabled="$isLinked" :locked-reason="$isLinked ? __('proposals.location_linked_place_locked') : null">
                                    @foreach ($programs as $program)
                                        <option value="{{ $program->id }}">{{ $program->name }}</option>
                                    @endforeach
                                    <option value="new">{{ __('proposals.location_new_program') }}</option>
                                </x-ui.form-select>

                                @if ($isNewProgram)
                                    <x-ui.form-input wire:model="locations.{{ $index }}.new_program_name_en" :label="__('proposals.location_program_name_en')" maxlength="255" required dir="ltr" />
                                    <x-ui.form-input wire:model="locations.{{ $index }}.new_program_name_ar" :label="__('proposals.location_program_name_ar')" maxlength="255" required dir="rtl" />
                                @endif

                                <x-ui.form-select wire:model="locations.{{ $index }}.responsible_employee_id" :label="__('proposals.location_responsible')" :placeholder="__('proposals.location_select_responsible')" :help="__('proposals.location_responsible_help')" employees />

                                <div wire:key="proposal-location-centers-{{ $location['key'] }}-{{ $location['city_id'] }}">
                                    <x-ui.form-multiselect
                                        wire:model="locations.{{ $index }}.center_ids"
                                        :label="__('proposals.location_centers')"
                                        :placeholder="__('proposals.location_select_centers')"
                                        :help="$location['city_id'] === '' ? __('proposals.location_choose_city_first') : ($cityCenters === [] ? __('proposals.location_no_centers_in_city') : __('proposals.location_centers_help'))"
                                        :disabled="$cityCenters === []"
                                    >
                                        @foreach ($cityCenters as $center)
                                            <option value="{{ $center['id'] }}">{{ $center['name'] }}</option>
                                        @endforeach
                                    </x-ui.form-multiselect>
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    <div>
                        <x-ui.button type="button" tone="secondary" size="sm" icon="plus" wire:click="addLocationRow" data-test="add-location">{{ __('proposals.add_location') }}</x-ui.button>
                    </div>
                @endunless

                @if ($projectRows !== [])
                    <ul class="grid gap-2" data-proposal-projects>
                        @foreach ($projectRows as $row)
                            <li class="flex flex-wrap items-end gap-x-3 gap-y-2 rounded-lg border border-secondary-soft p-3 dark:border-line" wire:key="proposal-project-{{ $row['id'] }}" data-proposal-project="{{ $row['id'] }}">
                                <div class="min-w-0 flex-1 basis-48 self-center">
                                    <p class="truncate text-sm font-semibold text-secondary-dark dark:text-ink" dir="auto">{{ $row['name'] }}</p>
                                    <p class="truncate text-xs {{ $mutedText }}">{{ $row['place'] }}</p>
                                    <x-projects.host-center-badge :host-name="$row['hosted']" class="mt-0.5" />
                                    <x-projects.center-inactive-badge :inactive="$row['centerInactive']" class="mt-0.5" />
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
        @endunless

        {{-- 4 · Concept note (W-24): the text, the document, or both. Neither is required. --}}
        @unless ($isRenewal || $isPayroll)
        <x-ui.form-section :title="__('proposals.concept_note.section')" :fields="$sectionFields[__('proposals.concept_note.section')]" data-test="concept-note-section">
            <div class="grid gap-y-3">
                <x-ui.form-textarea
                    wire:model="conceptNote"
                    :label="__('proposals.concept_note.text_label')"
                    :help="__('proposals.concept_note.text_help')"
                    rows="5"
                    dir="auto"
                />

                <x-ui.form-file
                    wire:model="conceptNoteFile"
                    :label="__('proposals.concept_note.file_label')"
                    :help="__('proposals.concept_note.file_help')"
                    :accept="collect(FileType::values())->map(fn ($extension) => '.'.$extension)->implode(',')"
                    :types="Str::upper(implode(', ', FileType::values()))"
                    max-size="5 MB"
                />
            </div>
        </x-ui.form-section>
        @endunless

        {{-- 4 · Dates. --}}
        <x-ui.form-section :title="__('proposals.section_planned_dates')" :fields="$sectionFields[__('proposals.section_planned_dates')]">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                <x-ui.form-input wire:model="plannedDateBegin" :label="__('proposals.planned_start')" type="date" required />
                <x-ui.form-input wire:model="plannedDateEnd" :label="__('proposals.planned_end')" type="date" required />
            </div>
        </x-ui.form-section>

        {{-- 5 · Budget. Absent, not hidden, for anyone without the money permissions. --}}
        @if ($showsMoney && ! $isRenewal)
            <x-ui.form-section :title="__('proposals.section_budget')" :fields="$sectionFields[__('proposals.section_budget')]">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-input wire:model="requestedBudget" :label="__('proposals.requested_budget')" :help="__('proposals.requested_budget_help')" type="number" step="0.01" min="0" dir="ltr" />
                    <x-ui.form-segmented
                        wire:model="requestedCurrency"
                        :label="__('common.currency')"
                        :options="collect(Currency::cases())->map(fn (Currency $currencyCase): array => ['value' => $currencyCase->value, 'label' => $currencyCase->value])->all()"
                    />
                </div>
            </x-ui.form-section>
        @endif

        {{-- 6 · Starting stage, on create only. --}}
        @unless ($editing || $isRenewal || $isPayroll)
            <x-ui.form-section :title="__('proposals.section_start')" :fields="$sectionFields[__('proposals.section_start')]">
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

        {{-- 7 · Notes, last (D-62). --}}
        <x-ui.form-section :title="__('proposals.section_notes')" :fields="$sectionFields[__('proposals.section_notes')]">
            <x-ui.form-textarea wire:model="notes" :label="__('common.notes')" rows="2" dir="auto" />
        </x-ui.form-section>
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
