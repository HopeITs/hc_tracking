{{--
    Assign or change the MEAL officer of a governorate or a reporting place.

    @var array{name: string, inactiveCity: bool, officer: array{name: string, inactive: bool}|null}|null $mealTarget
--}}

<x-ui.modal
    wire:model="showMealModal"
    size="md"
    :title="__('cities.meal_officer_for_name', ['name' => $mealTarget['name'] ?? ''])"
    :description="$mealScope === 'place' ? __('cities.meal_place_hint') : __('cities.meal_officer_hint')"
    submit="saveMealCoverage"
    close="closeMealModal"
    required-note
>
    @if ($showMealModal)
        <div class="flex flex-col gap-4">
            @if ($mealTarget !== null && $mealTarget['inactiveCity'])
                <p class="flex items-start gap-2 rounded-lg bg-status-warn-soft px-3 py-2 text-sm text-status-warn" role="status">
                    <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-4 shrink-0" />
                    <span>
                        <span class="font-semibold">{{ __('cities.city_inactive_in_hr') }}</span>
                        · {{ __('cities.city_inactive_in_hr_hint') }}
                    </span>
                </p>
            @endif

            @if ($mealTarget['officer']['inactive'] ?? false)
                <p class="flex items-center gap-2 rounded-lg bg-status-warn-soft px-3 py-2 text-sm text-status-warn" role="status">
                    <flux:icon.exclamation-triangle variant="mini" class="size-4 shrink-0" />
                    <span>{{ $mealTarget['officer']['name'] }} · {{ __('cities.responsible_inactive') }}</span>
                </p>
            @endif

            <x-ui.form-select
                wire:model="mealEmployeeId"
                :label="__('cities.meal_officer')"
                :placeholder="__('common.select')"
                required
                searchable
            >
                @foreach ($this->eligibleMealOfficers as $officer)
                    <option value="{{ $officer->id }}">{{ $officer->name }} · {{ $officer->secondary_name }}</option>
                @endforeach
            </x-ui.form-select>

            @error('mealTargetId')
                <p class="text-sm text-status-critical">{{ $message }}</p>
            @enderror
        </div>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="save-meal-officer-button">
            {{ __('common.save_changes') }}
        </x-ui.button>
    </x-slot:actions>
</x-ui.modal>
