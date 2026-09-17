@php
    /** @var bool $disabled */
@endphp

<flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
    <flux:button id="milestone-form-project" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :disabled="$disabled" :aria-label="__('common.project')">
        <span class="truncate">{{ $selectedMilestoneProject?->name ?? __('common.select_a_project') }}</span>
    </flux:button>
    <flux:menu class="filter-menu">
        @if ($this->projectOptions->count() > 6)
            <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                <x-ui.input type="search" size="sm" icon="magnifying-glass" x-model="optionSearch" :aria-label="__('milestones.search_projects')" :placeholder="__('milestones.search_projects')" />
            </div>
        @endif
        @foreach ($this->projectOptions as $projectOption)
            <flux:menu.item wire:click="$set('milestoneProjectId', {{ $projectOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'milestone-form-project-option-'.$projectOption->id">
                <span class="min-w-0 truncate">{{ $projectOption->name }}</span>
                @if ((int) $milestoneProjectId === $projectOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
            </flux:menu.item>
        @endforeach
    </flux:menu>
</flux:dropdown>
