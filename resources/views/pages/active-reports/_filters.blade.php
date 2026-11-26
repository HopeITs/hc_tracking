{{-- The bar filters of the open view: program and one month-range control on Monthly, donor on Donor. Same 36px height as the search. --}}
@if ($kind === 'monthly')
    @php
        [$windowFrom, $windowTo] = $this->window;
    @endphp

    @if (count($this->programOptions) > 1 || $program !== '')
        <x-ui.header-filter-select :label="__('active_reports.program')" wire:model.live="program" :value="$program">
            <option value="">{{ __('active_reports.all_programs') }}</option>
            @foreach ($this->programOptions as $programId => $programName)
                <option value="{{ $programId }}">{{ $programName }}</option>
            @endforeach
        </x-ui.header-filter-select>
    @endif

    <div class="rt-range" x-data="{ from: @js($windowFrom), to: @js($windowTo) }" data-tracker-range>
        <span class="rt-range-icon" aria-hidden="true"><x-desk.icon name="cal" /></span>
        <label class="rt-range-field">
            <span class="dk-sr">{{ __('active_reports.from') }}</span>
            <input id="tracker-from" type="month" x-model="from" x-on:change="$wire.setRange(from, to)" dir="ltr" />
        </label>
        <span class="rt-range-sep" aria-hidden="true">→</span>
        <label class="rt-range-field">
            <span class="dk-sr">{{ __('active_reports.to') }}</span>
            <input id="tracker-to" type="month" x-model="to" x-on:change="$wire.setRange(from, to)" dir="ltr" />
        </label>
    </div>
    @error('from')
        <p class="rt-range-error" role="alert" data-tracker-range-error>{{ $message }}</p>
    @enderror
@elseif ($kind === 'donor' && ($this->donorOptions->count() > 1 || $donor !== ''))
    <x-ui.header-filter-select :label="__('active_reports.donor')" wire:model.live="donor" :value="$donor">
        <option value="">{{ __('active_reports.all_donors') }}</option>
        @foreach ($this->donorOptions as $donorOption)
            <option value="{{ $donorOption->id }}">{{ $donorOption->name }}</option>
        @endforeach
    </x-ui.header-filter-select>
@endif
