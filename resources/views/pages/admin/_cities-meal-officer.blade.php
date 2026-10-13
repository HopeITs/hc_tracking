{{--
    One MEAL officer line (contracts §12.3): who validates the monthly reports
    here, an inherited/inactive note, and the assign/change button for the
    MEAL Manager.

    @var array{name: string, inactive: bool}|null $officer
    @var bool $inherited
    @var bool $canManage
    @var string $scope 'city' or 'project'
    @var int $targetId
--}}

<div class="flex items-center justify-between gap-3">
    <div class="min-w-0">
        <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('cities.meal_officer') }}</p>

        @if ($officer === null)
            <p class="text-sm text-secondary-light italic dark:text-ink-faint">{{ __('cities.no_meal_officer') }}</p>
        @else
            <p class="truncate text-sm font-semibold text-secondary-dark dark:text-ink">{{ $officer['name'] }}</p>
        @endif

        @if ($inherited)
            <p class="type-meta truncate text-secondary-medium dark:text-ink-muted">{{ __('cities.inherited_from_governorate') }}</p>
        @endif

        @if ($officer !== null && $officer['inactive'])
            <p class="flex items-center gap-1 text-xs font-semibold text-status-warn">
                <flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0" />
                {{ __('cities.responsible_inactive') }}
            </p>
        @endif
    </div>

    @if ($canManage)
        <x-ui.button
            size="sm"
            tone="secondary"
            class="shrink-0"
            wire:click="openMealModal('{{ $scope }}', {{ $targetId }})"
            data-test="assign-meal-officer-{{ $scope }}-{{ $targetId }}"
        >
            {{ $officer === null || $inherited ? __('cities.assign_meal_officer') : __('cities.change_meal_officer') }}
        </x-ui.button>
    @endif
</div>
