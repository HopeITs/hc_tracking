{{--
    The edit dialog of projects/index. Included by ⚡index.blade.php.

    Y-17: a place is a location row of a proposal, so this form changes only
    what the place DELIVERS — its description, the five beneficiary targets
    and its sectors (contracts.md §3, SaveLocationDetails). Three things it
    no longer offers, each on purpose:

    - **No create.** A place exists because a proposal has it (D-84, D-87),
      so the proposal form is the only way in and nothing is created here
      (`ProposalLocationPolicy::create()` is false).
    - **No name.** A place is named by where it is — its program, city and
      centers — not by a free-text field (`ProposalLocation::label()`).
    - **No placement, owner or dates.** The city, program, centers and
      responsible are decided on the proposal, through SaveProposal before
      approval or a change request afterwards (Y-09); the period comes from
      the approved grant (ApproveLocationFromProposal).
--}}
    <x-ui.modal
        wire:model="showProjectModal"
        size="xl"
        :title="__('projects.edit_project')"
        :description="__('projects.edit_what_this_place_delivers')"
        submit="saveProject"
        close="closeProjectModal"
        required-note
    >
        @if ($showProjectModal)
        {{-- One Alpine scope for the live breakdown figure beside Beneficiaries. --}}
        <div
            class="contents"
            x-data="{
                get headline() { return Number($wire.targetBeneficiaries) || 0 },
                get breakdown() {
                    return ['targetMen', 'targetWomen', 'targetBoys', 'targetGirls']
                        .reduce((total, key) => total + (Number($wire[key]) || 0), 0);
                },
                get breakdownHint() {
                    const [headline, breakdown] = [this.headline, this.breakdown];

                    if (breakdown === 0 || headline === 0 || breakdown === headline) {
                        return '';
                    }

                    return @js(e(__('projects.broken_down_of_headline', ['breakdown' => '__BREAKDOWN__', 'headline' => '__HEADLINE__'])))
                        .replace('__BREAKDOWN__', `<b class='form-section-figure'>${breakdown.toLocaleString()}</b>`)
                        .replace('__HEADLINE__', headline.toLocaleString());
                },
            }"
        >
            <x-ui.form-section
                :title="__('projects.section_details')"
                :hint="__('projects.section_details_hint')"
                :fields="['description']"
            >
                <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-3">
                    {{-- The place and its period are shown, never edited here. --}}
                    <p class="col-span-2 text-sm text-secondary-medium dark:text-ink-muted @4xl/modal:col-span-3">
                        <flux:icon name="map-pin" variant="micro" class="inline size-4 align-text-bottom" aria-hidden="true" />
                        {{ $this->editingPlaceLabel }}
                    </p>

                    <p class="col-span-2 text-sm text-secondary-medium dark:text-ink-muted @4xl/modal:col-span-3">
                        <flux:icon name="calendar-days" variant="micro" class="inline size-4 align-text-bottom" aria-hidden="true" />
                        {{ $this->datesEditable() ? __('projects.dates_set_by_the_grant') : __('projects.dates_prohibited_draft') }}
                    </p>

                    <x-ui.form-textarea wire:model="description" :label="__('common.description')" rows="2" autofocus class="col-span-2 @4xl/modal:col-span-3" />
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('projects.section_placement')" :fields="['sectorIds']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-3">
                    {{-- An older place without sectors can still be saved as it is (D-47). --}}
                    <x-projects.sector-picker wire:model="sectorIds" :required="false" class="@2xl/modal:col-span-3" />
                </div>
            </x-ui.form-section>

            <x-ui.form-section
                :title="__('projects.section_beneficiaries')" :optional="true"
                :fields="['targetBeneficiaries', 'targetMen', 'targetWomen', 'targetBoys', 'targetGirls']"
            >
                <x-slot:hint>
                    <span x-show="breakdownHint !== ''" x-html="breakdownHint" style="display: none"></span>
                </x-slot:hint>

                <div class="grid grid-cols-2 gap-3 @2xl/modal:grid-cols-5">
                    <x-ui.form-input wire:model="targetBeneficiaries" :label="__('projects.headline_total')" type="number" min="0" step="1" class="col-span-2 @2xl/modal:col-span-1" />
                    <x-ui.form-input wire:model="targetMen" :label="__('projects.men')" type="number" min="0" step="1" />
                    <x-ui.form-input wire:model="targetWomen" :label="__('projects.women')" type="number" min="0" step="1" />
                    <x-ui.form-input wire:model="targetBoys" :label="__('projects.boys')" type="number" min="0" step="1" />
                    <x-ui.form-input wire:model="targetGirls" :label="__('projects.girls')" type="number" min="0" step="1" />
                </div>
            </x-ui.form-section>
        </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-project-button">
                {{ __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
