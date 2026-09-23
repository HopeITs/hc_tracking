@php
    $destinations = [
        ['route' => 'dashboard', 'icon' => 'home', 'label' => __('common.dashboard')],
        ['route' => 'programs.index', 'icon' => 'briefcase', 'label' => __('common.programs')],
        ['route' => 'tasks.index', 'icon' => 'clipboard-document-check', 'label' => __('common.tasks')],
        ['route' => 'donors.index', 'icon' => 'building-library', 'label' => __('common.donors')],
        ['route' => 'funding-agreements.index', 'icon' => 'banknotes', 'label' => __('common.funding_agreements')],
        ['route' => 'reports.index', 'icon' => 'document-chart-bar', 'label' => __('common.reports')],
    ];
@endphp

<nav {{ $attributes->class('w-full rounded-2xl border border-secondary-soft bg-white p-6 dark:border-secondary-medium dark:bg-secondary-dark') }} aria-labelledby="error-quick-links-heading">
    <p id="error-quick-links-heading" class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('errors.where_you_can_go') }}</p>

    <ol class="connection-thread mt-4 flex flex-col">
        @foreach ($destinations as $destination)
            <li class="relative ps-7 pb-4 last:pb-0">
                <span class="absolute start-0 top-1 size-3 rounded-full border-2 border-secondary-dark bg-white dark:border-white dark:bg-secondary-dark" aria-hidden="true"></span>
                <a href="{{ route($destination['route']) }}" wire:navigate class="group flex items-center gap-2 text-sm font-semibold text-secondary-dark underline-offset-4 hover:underline dark:text-white">
                    <flux:icon :name="$destination['icon']" variant="micro" class="size-4 shrink-0 text-secondary-light group-hover:text-primary dark:text-secondary-soft" />
                    {{ $destination['label'] }}
                </a>
            </li>
        @endforeach
    </ol>
</nav>
