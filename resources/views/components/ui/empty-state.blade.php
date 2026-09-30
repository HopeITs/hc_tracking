@props(['icon' => 'inbox', 'heading', 'body' => null])

<div {{ $attributes->class('flex min-h-64 flex-col items-center justify-center gap-3 px-5 py-10 text-center') }}>
    <div class="flex size-12 items-center justify-center rounded-xl border border-secondary-soft bg-white text-secondary-medium dark:border-line dark:bg-surface-1 dark:text-ink-muted">
        <flux:icon :name="$icon" class="size-5" />
    </div>
    <div class="max-w-sm space-y-1">
        <flux:heading size="base">{{ $heading }}</flux:heading>
        @if ($body !== null)
            <p class="text-sm text-secondary-medium dark:text-ink-muted">{{ $body }}</p>
        @endif
    </div>
    {{ $action ?? '' }}
</div>
