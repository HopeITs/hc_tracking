@props(['active' => false, 'reset' => 'clearFilters'])

<div {{ $attributes->class('flex flex-col gap-2') }} data-finance-filters>
    {{ $slot }}

    @if ($active)
        <div class="flex justify-end">
            <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-ink-muted!" wire:click="{{ $reset }}" data-test="reset-filters">{{ __('common.reset_filters') }}</flux:button>
        </div>
    @endif
</div>
