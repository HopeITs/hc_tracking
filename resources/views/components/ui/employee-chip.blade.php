@props(['employee', 'compact' => false])

{{-- An HR employee shown by name in the interface language, with the other language beside it. --}}
@if ($employee === null)
    <div {{ $attributes->class('flex min-w-0 items-center gap-2.5') }}>
        <p class="truncate text-sm text-secondary-medium dark:text-secondary-soft">{{ __('common.not_assigned') }}</p>
    </div>
@else
    <div {{ $attributes->class('flex min-w-0 items-center gap-2.5') }}>
        <flux:avatar
            class="bg-secondary-soft! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!"
            size="sm"
            :initials="$employee->initials()"
        />
        <div class="min-w-0">
            <p class="truncate text-sm font-medium text-secondary-dark dark:text-white">{{ $employee->name }}</p>
            @unless ($compact)
                <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft" dir="auto" lang="{{ \App\Enums\Locale::current()->other()->value }}">{{ $employee->secondary_name }}</p>
            @endunless
        </div>
    </div>
@endif
