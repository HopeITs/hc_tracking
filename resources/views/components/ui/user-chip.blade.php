@props(['user', 'compact' => false])

<div {{ $attributes->class('flex min-w-0 items-center gap-2.5') }}>
    <flux:avatar
        class="bg-secondary-soft! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!"
        size="sm"
        :initials="$user->initials()"
    />
    <div class="min-w-0">
        <p class="truncate text-sm font-medium text-secondary-dark dark:text-white">{{ $user->name }}</p>
        @unless ($compact)
            <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                {{ $user->role->value }} · {{ $user->email }}
            </p>
        @endunless
    </div>
</div>
