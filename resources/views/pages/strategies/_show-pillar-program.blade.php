{{-- One program line inside a pillar card of strategies/show. --}}
<li class="flex min-w-0 items-center gap-2" wire:key="pillar-program-{{ $program->id }}">
    <flux:icon.briefcase variant="micro" class="size-3.5 shrink-0 text-secondary-medium dark:text-ink-muted" aria-hidden="true" />
    <a href="{{ route('programs.show', $program) }}" wire:navigate class="min-w-0 flex-1 truncate text-sm text-secondary-dark hover:underline dark:text-ink" dir="auto">{{ $program->name }}</a>
    @can('strategies.update')
        <x-ui.button
            tone="ghost"
            size="xs"
            icon="arrows-right-left"
            wire:click="openAssignModal({{ $program->id }})"
            :aria-label="__('strategies.program_pillar_actions', ['program' => $program->name])"
            data-test="assign-program-{{ $program->id }}"
        />
    @endcan
</li>
