@props([
    'id' => null,
    'label',
    'searchable' => null,
    'searchLabel' => null,
    'value' => '',
    'employees' => false,
])

@php
    use App\Models\HrEmployee;

    /* Flux's menu type-ahead listens on the whole menu; single-character keys stop at the search box so typing filters instead of jumping. */
    $model = $attributes->wire('model')->value();

    if ($model === null) {
        throw new InvalidArgumentException('The x-ui.header-filter-select component requires a wire:model binding.');
    }

    $document = new DOMDocument('1.0', 'UTF-8');
    $previousLibxmlState = libxml_use_internal_errors(true);
    $document->loadHTML(
        '<?xml encoding="utf-8" ?><select>'.(string) $slot.'</select>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlState);

    $options = [];

    foreach ($document->getElementsByTagName('option') as $option) {
        $options[] = [
            'disabled' => $option->hasAttribute('disabled'),
            'label' => trim(preg_replace('/\s+/u', ' ', $option->textContent) ?? $option->textContent),
            'value' => $option->getAttribute('value'),
        ];
    }

    /* `employees` filters list every employee from the shared employeeOptions store (form-fields.js) instead of server-rendered options. */
    $employeeLabels = $employees ? HrEmployee::pickerLabels()->pluck('label', 'value') : collect();
    $selectedValue = (string) $value;
    $selectedLabel = collect($options)->firstWhere('value', $selectedValue)['label']
        ?? $employeeLabels->get($selectedValue)
        ?? ($options[0]['label'] ?? '');
    $id ??= 'header-filter-'.substr(md5($model.'|'.$label), 0, 12);
    $searchable ??= $employees || count($options) > 7;
    $searchLabel ??= $label;
    $employeesUrl = $employees ? route('employees.picker-options', ['l' => app()->getLocale()]) : null;

    /* flux:menu.item's own default-variant classes (vendor/livewire/flux/stubs/resources/views/flux/menu/item.blade.php). */
    $menuItemClasses = 'flex items-center px-2 py-1.5 w-full focus:outline-hidden select-none rounded-md text-start text-sm font-medium [&[disabled]]:opacity-50 text-zinc-800 data-active:bg-zinc-50 dark:text-white dark:data-active:bg-zinc-600 **:data-flux-menu-item-icon:text-zinc-400 dark:**:data-flux-menu-item-icon:text-white/60 [&[data-active]_[data-flux-menu-item-icon]]:text-current';
@endphp

<div class="min-w-0" data-header-filter-select>
    <label for="{{ $id }}" class="mb-1.5 block text-[11px] font-semibold uppercase leading-4 tracking-[0.1em] text-secondary-medium dark:text-ink-muted">
        {{ $label }}
    </label>

    <flux:dropdown position="bottom" align="start" class="program-filter-picker block! w-full!" x-data="filterMenu" :data-employees-url="$employeesUrl" data-model="{{ $model }}">
        <flux:button
            :id="$id"
            variant="outline"
            icon:trailing="chevron-up-down"
            class="program-filter-trigger w-full! justify-between!"
            :aria-label="$label"
            x-on:click="openEmployees()"
        >
            <span class="truncate">{{ $selectedLabel }}</span>
        </flux:button>

        <flux:menu class="program-filter-menu">
            @if ($searchable)
                <div class="border-b border-secondary-soft p-1.5 dark:border-line">
                    <x-ui.input
                        type="search"
                        size="sm"
                        icon="magnifying-glass"
                        x-model="optionSearch"
                        x-on:keydown="$event.key.length === 1 && $event.stopPropagation()"
                        :aria-label="$searchLabel"
                        :placeholder="$searchLabel"
                    />
                </div>
            @endif

            {{-- The markup flux:menu.item renders, written out: a person filter
                 lists 270+ employees, and one component render each cost
                 ~140 ms per page. --}}
            @foreach ($options as $option)
                <button
                    type="button"
                    class="{{ $menuItemClasses }}"
                    data-flux-menu-item
                    wire:click="$set('{{ $model }}', {{ Illuminate\Support\Js::from($option['value']) }})"
                    x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                    @disabled($option['disabled'])
                    @if ($selectedValue === $option['value']) data-selected="true" @endif
                    wire:key="{{ $id.'-'.md5($option['value']) }}"
                >
                    <div class="w-7 hidden [[data-flux-menu]:has(>[data-flux-menu-item-has-icon])_&]:block"></div>
                    <span class="min-w-0 truncate">{{ $option['label'] }}</span>

                    @if ($selectedValue === $option['value'])
                        <flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />
                    @endif
                </button>
            @endforeach
            @if ($employees)
                <div wire:ignore x-ref="employeeList" class="contents" x-on:click="pickEmployee($event)" data-item-class="{{ $menuItemClasses }}" data-check-class="ms-auto size-4 shrink-0 text-secondary-medium"></div>
            @endif

        </flux:menu>
    </flux:dropdown>
</div>
