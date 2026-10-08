    <x-ui.modal
        wire:model="showPlanModal"
        size="md"
        :title="$editingPlanId === null ? __('annual_plans.new_annual_plan') : __('annual_plans.edit_annual_plan')"
        :description="__('annual_plans.one_program_one_fiscal_year_one_accountable_manager')"
        submit="savePlan"
        close="closePlanModal"
        required-note
    >
        @if ($showPlanModal)
            <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                <x-ui.form-select wire:model="planProgramId" :label="__('common.program')" :placeholder="__('annual_plans.select_a_program')" required class="col-span-2">
                    @foreach ($this->programOptions as $programOption)
                        <option value="{{ $programOption->id }}">{{ $programOption->name }}</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-select wire:model="planYear" :label="__('annual_plans.fiscal_year')" :placeholder="__('annual_plans.select_a_fiscal_year')" required>
                    @foreach ($this->yearFormOptions as $yearFormOption)
                        <option value="{{ $yearFormOption }}">{{ $yearFormOption }}</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-select wire:model="planManagerId" :label="__('annual_plans.assigned_manager')" :placeholder="__('annual_plans.select_a_manager')" required>
                    @foreach ($this->employeeOptions as $employeeOption)
                        <option value="{{ $employeeOption->id }}">{{ $employeeOption->name }} · {{ $employeeOption->secondary_name }}</option>
                    @endforeach
                </x-ui.form-select>
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-annual-plan-button">
                {{ $editingPlanId === null ? __('annual_plans.create_annual_plan') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
