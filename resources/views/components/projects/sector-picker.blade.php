@props([
    'label' => null,
    'help' => null,
    'required' => true,
    'inModal' => true,
])

{{--
    The sector picker of the project form (contracts.md §12.5, T34):
    <x-projects.sector-picker wire:model="sectorIds" />

    A checkbox list on x-ui.multi-select-popover, bound to the Livewire array
    named by wire:model (`.live`, so the trigger's label follows each click).
    It offers every active sector, plus the inactive ones already chosen,
    shown with an "Inactive" badge so editing a project never drops them
    silently. The chosen sectors are listed under the trigger as chips.
    SyncProjectSectors checks all of this again on the server.

    `in-modal` (default) lifts the panel into the top layer above a flux:modal.
--}}
@php
    use App\Models\Sector;

    $model = $attributes->wire('model')->value();

    if ($model === null) {
        throw new InvalidArgumentException('The x-projects.sector-picker component requires a wire:model binding.');
    }

    $livewire = $__env->shared('__livewire');
    $chosenIds = collect($livewire !== null ? data_get($livewire, $model, []) : [])
        ->map(fn (mixed $id): int => (int) $id)
        ->filter(fn (int $id): bool => $id > 0)
        ->values()
        ->all();
    $options = Sector::pickerOptions($chosenIds);
    $chosen = $options->whereIn('id', $chosenIds)->values();
    $id = 'sector-picker-'.substr(md5($model), 0, 8);
    $label ??= __('sectors.sectors');
    $help ??= __('sectors.picker_help');
    $error = $errors->first($model) ?: $errors->first($model.'.*');
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $badgeClasses = 'inline-flex items-center gap-1 rounded-full border border-secondary-light px-1.5 text-[10px] font-semibold leading-4 text-secondary-dark dark:border-line-strong dark:text-ink';
@endphp

<x-ui.form-field :label="$label" :for="$id" :required="$required" :help="$help" :error="$error" {{ $attributes->only('class') }} data-test="sector-picker">
    <x-ui.multi-select-popover
        :in-modal="$inModal"
        :searchable="$options->count() > 7"
        :label="__('sectors.choose_sectors')"
        :id="$id"
        class="form-box cursor-pointer"
        :aria-invalid="$error !== '' ? 'true' : null"
        :aria-describedby="$error !== '' ? $id.'-error' : $id.'-help'"
    >
        <x-slot:trigger>
            <span class="min-w-0 flex-1 truncate">{{ $chosen->isEmpty() ? __('sectors.choose_sectors') : ($chosen->count() <= 2 ? $chosen->map->localizedName()->implode(__('sectors.list_separator')) : __('common.count_selected', ['count' => $chosen->count()])) }}</span>
            <flux:icon.chevron-down variant="micro" class="size-4 shrink-0 {{ $mutedText }}" />
        </x-slot:trigger>

        @forelse ($options as $sector)
            <label
                class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-white/[0.06]"
                wire:key="{{ $id }}-{{ $sector->id }}"
                data-picker-sector="{{ $sector->id }}"
                x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
            >
                <input type="checkbox" wire:model.live="{{ $model }}" value="{{ $sector->id }}" class="size-4 shrink-0 accent-primary" />
                <span class="min-w-0 flex-1 truncate font-medium text-secondary-dark dark:text-ink" dir="auto">{{ $sector->localizedName() }}</span>
                @unless ($sector->is_active)
                    <span class="{{ $badgeClasses }}">{{ __('sectors.inactive') }}</span>
                @endunless
            </label>
        @empty
            <p class="px-2 py-3 text-sm {{ $mutedText }}">{{ __('sectors.no_sectors_available') }}</p>
        @endforelse
    </x-ui.multi-select-popover>

    @if ($chosen->isNotEmpty())
        <ul class="mt-2 flex flex-wrap gap-1.5" aria-label="{{ $label }}">
            @foreach ($chosen as $sector)
                <li wire:key="{{ $id }}-chip-{{ $sector->id }}" class="inline-flex items-center gap-1 rounded-full bg-secondary-soft px-2.5 py-0.5 text-xs font-medium text-secondary-dark dark:bg-surface-2 dark:text-ink">
                    <span dir="auto">{{ $sector->localizedName() }}</span>
                    @unless ($sector->is_active)
                        <span class="{{ $badgeClasses }}">{{ __('sectors.inactive') }}</span>
                    @endunless
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.form-field>
