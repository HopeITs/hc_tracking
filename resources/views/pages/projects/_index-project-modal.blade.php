{{--
    The create/edit project dialog of projects/index. Included by
    ⚡index.blade.php, whose top PHP block defines $durationForms and
    $monthNames for the dialog's live duration hint.
--}}
    <x-ui.modal
        wire:model="showProjectModal"
        size="xl"
        :title="$editingProjectId === null ? __('projects.add_project') : __('projects.edit_project')"
        :description="__('projects.define_delivery_ownership_lifecycle_schedule_and_beneficiary')"
        submit="saveProject"
        close="closeProjectModal"
        required-note
    >
        @if ($showProjectModal)
        {{-- One Alpine scope for the live figures: the duration under Details and the breakdown beside Beneficiaries. --}}
        <div
            class="contents"
            x-data="{
                forms: @js($durationForms),
                months: @js($monthNames),
                breakdownTemplate: @js(e(__('projects.broken_down_of_headline', ['breakdown' => '__BREAKDOWN__', 'headline' => '__HEADLINE__']))),
                get durationDays() {
                    const start = Date.parse($wire.startDate);
                    const end = Date.parse($wire.endDate);

                    if (Number.isNaN(start) || Number.isNaN(end)) {
                        return 0;
                    }

                    return Math.max(0, Math.floor((end - start) / 86400000) + 1);
                },
                get durationLabel() {
                    const days = this.durationDays;

                    if (days < 1) {
                        return '';
                    }

                    const form = days <= 3 ? this.forms[days] : (days <= 10 ? this.forms[3] : this.forms[11]);

                    return form.replace('__N__', days.toLocaleString());
                },
                get durationRange() {
                    if (this.durationDays < 1) {
                        return '';
                    }

                    const [startYear, startMonth] = $wire.startDate.split('-').map(Number);
                    const [endYear, endMonth] = $wire.endDate.split('-').map(Number);
                    const start = this.months[startMonth - 1];
                    const end = this.months[endMonth - 1];

                    if (startYear !== endYear) {
                        return `${start} ${startYear} – ${end} ${endYear}`;
                    }

                    return startMonth === endMonth ? `${start} ${startYear}` : `${start} – ${end} ${startYear}`;
                },
                get breakdownHint() {
                    const breakdown = [$wire.targetMen, $wire.targetWomen, $wire.targetBoys, $wire.targetGirls]
                        .reduce((total, value) => total + (Number(value) || 0), 0);
                    const headline = Number($wire.targetBeneficiaries) || 0;

                    if (breakdown === 0 && headline === 0) {
                        return '';
                    }

                    return this.breakdownTemplate
                        .replace('__BREAKDOWN__', `<b class='form-section-figure'>${breakdown.toLocaleString()}</b>`)
                        .replace('__HEADLINE__', headline.toLocaleString());
                },
            }"
        >
            <x-ui.form-section
                :title="__('projects.section_details')"
                :hint="__('projects.section_details_hint')"
                :fields="['name', 'startDate', 'endDate', 'description']"
            >
                <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-3">
                    <x-ui.form-input wire:model="name" :label="__('projects.project_name')" maxlength="255" required autofocus class="col-span-2" />

                    @if ($this->datesEditable())
                        <x-ui.form-input wire:model="startDate" :label="__('common.start_date')" type="date" />
                        <x-ui.form-input wire:model="endDate" :label="__('projects.end_date')" type="date" />

                        <div class="col-span-2 min-w-0 @4xl/modal:col-span-1">
                            <span class="form-label">{{ __('projects.duration') }}</span>
                            <p class="flex min-h-11 flex-wrap items-center gap-x-2 tabular-nums">
                                <span class="text-lg font-bold text-secondary-dark dark:text-ink" x-text="durationLabel || '—'">—</span>
                                <span class="text-xs font-medium text-secondary-medium dark:text-ink-muted" x-text="durationRange"></span>
                            </p>
                        </div>
                    @else
                        {{-- A draft has no dates: they are set when the proposal is approved. --}}
                        <p class="col-span-2 text-sm text-secondary-medium dark:text-ink-muted @4xl/modal:col-span-3">
                            <flux:icon name="calendar-days" variant="micro" class="inline size-4 align-text-bottom" aria-hidden="true" />
                            {{ __('projects.dates_prohibited_draft') }}
                        </p>
                        @error('startDate')
                            <p role="alert" class="col-span-2 text-sm font-medium text-status-critical @4xl/modal:col-span-3">{{ $message }}</p>
                        @enderror
                        @error('endDate')
                            <p role="alert" class="col-span-2 text-sm font-medium text-status-critical @4xl/modal:col-span-3">{{ $message }}</p>
                        @enderror
                    @endif

                    <x-ui.form-textarea wire:model="description" :label="__('common.description')" rows="2" class="col-span-2 @4xl/modal:col-span-3" />
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('projects.section_placement')" :fields="['centerId', 'programId', 'userId']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-3">
                    <x-ui.form-select wire:model.live="centerId" :label="__('projects.center')" :placeholder="__('projects.select_a_center')" :required="$editingProjectId === null || $savedCenterId !== null">
                        @foreach ($this->centerOptions as $centerOption)
                            <option value="{{ $centerOption->id }}">{{ $centerOption->name }}@unless ($centerOption->is_active) · {{ __('projects.center_inactive') }}@endunless</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-select wire:model.live="programId" :label="__('common.program')" :placeholder="__('projects.select_a_program')" required>
                        @foreach ($this->programOptions as $programOption)
                            <option value="{{ $programOption->id }}">{{ $programOption->name }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-select wire:model="userId" :label="__('projects.owner')" :placeholder="__('projects.select_an_owner')" required>
                        @foreach ($this->ownerOptions as $userOption)
                            <option value="{{ $userOption->id }}">{{ $userOption->name }} · {{ $userOption->secondary_name }}</option>
                        @endforeach
                    </x-ui.form-select>
                </div>
            </x-ui.form-section>

            <x-ui.form-section
                :title="__('projects.section_beneficiaries')"
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
                {{ $editingProjectId === null ? __('projects.create_project') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
