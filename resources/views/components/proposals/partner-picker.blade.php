@props([
    'donors' => [],
    'exclude' => [],
])

{{--
    The "Other Partners" picker of the proposal form: a checkbox list on
    x-ui.multi-select-popover, bound to the Livewire array named by wire:model.
    Partners are reference only (nothing is reported to them), taken from the
    donor library; the back donor and the Main Partner are left out. The chosen
    ones are listed under the trigger as chips. SaveProposal checks it all again.
--}}
@php
    $model = $attributes->wire('model')->value();

    if ($model === null) {
        throw new InvalidArgumentException('The x-proposals.partner-picker component requires a wire:model binding.');
    }

    $livewire = $__env->shared('__livewire');
    $chosenIds = collect($livewire !== null ? data_get($livewire, $model, []) : [])->map(fn (mixed $id): string => (string) $id)->all();
    $excluded = collect($exclude)->map(fn (mixed $id): string => (string) $id)->filter()->all();
    $options = collect($donors)->reject(fn ($donor): bool => in_array((string) $donor->id, $excluded, true))->values();
    $chosen = $options->filter(fn ($donor): bool => in_array((string) $donor->id, $chosenIds, true))->values();
    $id = 'partner-picker';
    $error = $errors->first($model) ?: $errors->first($model.'.*');
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
@endphp

<x-ui.form-field :label="__('proposals.other_partners')" :for="$id" :help="__('proposals.other_partners_help')" :error="$error" data-test="partner-picker">
    <x-ui.multi-select-popover in-modal :searchable="$options->count() > 7" :label="__('proposals.choose_the_partners')" :id="$id" class="form-box cursor-pointer">
        <x-slot:trigger>
            <span class="min-w-0 flex-1 truncate">{{ $chosen->isEmpty() ? __('proposals.choose_the_partners') : ($chosen->count() <= 2 ? $chosen->pluck('name')->implode(', ') : __('common.count_selected', ['count' => $chosen->count()])) }}</span>
            <flux:icon.chevron-down variant="micro" class="size-4 shrink-0 {{ $mutedText }}" />
        </x-slot:trigger>

        @forelse ($options as $donor)
            <label
                class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-white/[0.06]"
                wire:key="{{ $id }}-{{ $donor->id }}"
                data-picker-partner="{{ $donor->id }}"
                x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
            >
                <input type="checkbox" wire:model.live="{{ $model }}" value="{{ $donor->id }}" class="size-4 shrink-0 accent-primary" />
                <span class="min-w-0 flex-1 truncate font-medium text-secondary-dark dark:text-ink" dir="auto">{{ $donor->name }}</span>
            </label>
        @empty
            <p class="px-2 py-3 text-sm {{ $mutedText }}">{{ __('proposals.no_partners_available') }}</p>
        @endforelse
    </x-ui.multi-select-popover>

    @if ($chosen->isNotEmpty())
        <ul class="mt-2 flex flex-wrap gap-1.5" aria-label="{{ __('proposals.other_partners') }}">
            @foreach ($chosen as $donor)
                <li wire:key="{{ $id }}-chip-{{ $donor->id }}" class="inline-flex items-center rounded-full bg-secondary-soft px-2.5 py-0.5 text-xs font-medium text-secondary-dark dark:bg-surface-2 dark:text-ink">
                    <span dir="auto">{{ $donor->name }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.form-field>
