@props(['user', 'compact' => false])

@if ($user === null)
    <div {{ $attributes->class('flex min-w-0 items-center gap-2.5') }}>
        <p class="truncate text-sm text-secondary-medium dark:text-ink-muted">{{ __('common.not_assigned') }}</p>
    </div>
@else
    <div {{ $attributes->class('flex min-w-0 items-center gap-2.5') }}>
        <div class="min-w-0">
            <p class="truncate text-sm font-medium text-secondary-dark dark:text-ink">{{ $user->name }}</p>
            @unless ($compact)
                <p class="truncate text-xs text-secondary-medium dark:text-ink-muted">
                    {{ $user->roles->first()?->name ?? __('users.no_role') }} · {{ $user->email }}
                </p>
            @endunless
        </div>
    </div>
@endif
