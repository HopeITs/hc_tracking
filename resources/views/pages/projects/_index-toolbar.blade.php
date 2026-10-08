{{--
    The view switch and the reset-filters button of projects/index. Included
    by ⚡index.blade.php, whose top PHP block defines $viewOptions and
    $filtersAreActive.
--}}
    {{-- Tabs, exactly as the milestones directory renders them. --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex flex-wrap gap-1 rounded-lg border border-secondary-soft bg-secondary-soft/40 p-1 dark:border-line dark:bg-surface-2" role="group" aria-label="{{ __('projects.views') }}">
            @foreach ($viewOptions as $viewValue => $viewMeta)
                <button
                    type="button"
                    wire:click="$set('view', '{{ $viewValue }}')"
                    aria-pressed="{{ $view === $viewValue ? 'true' : 'false' }}"
                    data-test="project-view-{{ $viewValue }}"
                    @class([
                        'inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-md px-3 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                        'bg-white text-secondary-dark shadow-sm dark:bg-surface-3 dark:shadow-raised dark:text-ink' => $view === $viewValue,
                        'text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => $view !== $viewValue,
                    ])
                >
                    <flux:icon :name="$viewMeta['icon']" variant="micro" class="size-4" aria-hidden="true" />
                    {{ $viewMeta['label'] }}
                </button>
            @endforeach
        </div>

        <div class="flex shrink-0 items-center gap-2">
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
            @endif
        </div>
    </div>
