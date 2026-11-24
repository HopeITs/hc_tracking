{{-- One quiet line for an empty section of ⚡show.blade.php, in place of a full-height empty state: three of those stacked took two screens to say "nothing here" (page review, 6 Nov 2026). Expects $key, $icon, $heading and $body (nullable). --}}
<p class="mrp-empty" data-empty-line="{{ $key }}">
    <flux:icon :name="$icon" variant="micro" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
    <span>
        <span class="font-semibold text-secondary-dark dark:text-ink">{{ $heading }}</span>
        @if ($body !== null)
            <span> · {{ $body }}</span>
        @endif
    </span>
</p>
