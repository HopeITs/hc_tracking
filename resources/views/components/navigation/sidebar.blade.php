@php
    $moduleCounts = auth()->check() && ! \App\Http\Middleware\EnsureTwoFactorIsEnabled::mustEnrol(auth()->user())
        ? app(\App\Notifications\UnreadCounts::class)->for(auth()->user())['modules']
        : [];
    $showsAdministration = auth()->user()?->canAny(['cities.view', 'sectors.view', 'evidence-types.manage', 'narrative-questions.manage', 'activity-log.view', 'notification-settings.manage'])
        || auth()->user()?->can('viewAny', \App\Models\User::class);
    $reviewerEmployeeId = auth()->user()?->employee_id;
    $hasReviews = $reviewerEmployeeId !== null && \App\Models\ProposalReviewer::query()->forEmployee((int) $reviewerEmployeeId)->exists();
    $awaitingReviews = $hasReviews ? \App\Models\ProposalReviewer::query()->forEmployee((int) $reviewerEmployeeId)->awaitingReview()->count() : 0;
    /* The heading's look lives in app.css ("Sidebar group headings"): Flux renders it as the group's first div, which utilities passed here cannot reach reliably. */
    $navigationGroupClasses = 'in-data-flux-sidebar-collapsed-desktop:flex!';
    $navigationItemClasses = 'touch-manipulation rounded-lg! in-data-flux-sidebar-collapsed-desktop:rounded-2xl! text-secondary-dark! hover:bg-secondary-soft/70! max-lg:h-11! data-current:border-primary! data-current:bg-primary! data-current:font-semibold! data-current:text-white! data-current:shadow-sm! dark:text-ink-muted! dark:hover:bg-white/[0.06]! dark:hover:text-ink! dark:data-current:border-transparent! dark:data-current:bg-surface-2! dark:data-current:text-ink! dark:data-current:shadow-none! dark:data-current:before:absolute dark:data-current:before:inset-y-1.5 dark:data-current:before:start-0 dark:data-current:before:w-0.5 dark:data-current:before:rounded-full dark:data-current:before:bg-brand in-data-flux-sidebar-collapsed-desktop:dark:data-current:before:hidden';
@endphp

{{-- flux-sidebar-toggle opens the window in which app.css animates the desktop collapse, so restoring a saved state on page load never animates. --}}
<flux:sidebar
    sticky
    :collapsible="true"
    aria-label="{{ __('navigation.application_navigation') }}"
    data-navigation-sidebar
    x-on:keydown.escape.window="if (window.matchMedia('(max-width: 63.999rem)').matches && ! $el.hasAttribute('data-flux-sidebar-collapsed-mobile')) { $dispatch('flux-sidebar-toggle') }"
    x-on:flux-sidebar-toggle.document="$el.setAttribute('data-sidebar-animating', ''); clearTimeout($el.sidebarAnimationTimer); $el.sidebarAnimationTimer = setTimeout(() => $el.removeAttribute('data-sidebar-animating'), 500)"
    class="relative z-50! gap-3! overflow-visible! border-e border-secondary-soft bg-white p-3! text-secondary-dark shadow-[1px_0_0_0_rgba(0,0,0,0.02)] max-lg:duration-300 max-lg:ease-drawer motion-reduce:duration-0 motion-reduce:transition-none max-lg:w-[min(18rem,calc(100vw_-_2.75rem))]! max-lg:shadow-2xl lg:w-[clamp(16rem,18vw,18rem)]! dark:border-line dark:bg-surface-1 dark:text-ink dark:shadow-none data-[flux-sidebar-collapsed-desktop]:lg:w-[calc(4rem+1px+var(--sidebar-scrollbar,0px))]! data-[flux-sidebar-collapsed-desktop]:cursor-default!"
>
    <div
        x-data
        x-on:click="$dispatch('flux-sidebar-toggle')"
        class="absolute -end-4 top-[15.5px] z-20 hidden size-8 shrink-0 cursor-pointer touch-manipulation items-center justify-center rounded-full border border-secondary-soft bg-white text-secondary-medium shadow-[0_1px_2px_rgba(16,24,40,0.08)] hover:text-secondary-dark lg:flex dark:border-line dark:bg-surface-1 dark:text-ink-muted dark:hover:text-ink"
        role="button"
        tabindex="0"
        x-on:keydown.enter="$dispatch('flux-sidebar-toggle')"
        x-on:keydown.space.prevent="$dispatch('flux-sidebar-toggle')"
        {{-- Interpolated, not Blade's :attr shorthand: that shorthand is for Blade components, so on a plain div Alpine claims :aria-label as x-bind and evaluates __() as JavaScript, throwing and leaving the toggle unnamed. --}}
        aria-label="{{ __('navigation.toggle_sidebar') }}"
        data-desktop-navigation-collapse
    >
        <flux:icon.chevron-left class="size-4 in-data-flux-sidebar-collapsed-desktop:rotate-180 rtl:rotate-180 in-data-flux-sidebar-collapsed-desktop:rtl:rotate-0" data-desktop-navigation-collapse-icon />
    </div>

    <flux:sidebar.header class="-mx-3 -mt-3 h-16 shrink-0 border-b border-secondary-soft px-3 dark:border-line">
        <x-app-logo
            :sidebar="true"
            :href="route('dashboard')"
            class="touch-manipulation text-secondary-dark max-lg:min-h-11 dark:text-ink in-data-flux-sidebar-collapsed-desktop:in-data-flux-sidebar-active:static! in-data-flux-sidebar-collapsed-desktop:in-data-flux-sidebar-active:opacity-100!"
            wire:navigate.hover
        />

        <flux:sidebar.collapse
            class="size-11! touch-manipulation text-secondary-medium lg:hidden dark:text-ink-muted"
            data-mobile-navigation-close
        />
    </flux:sidebar.header>

    {{-- Mobile drawer only: the header row has no room beside the full logo, and a bottom row can sit under the browser's own toolbar. --}}
    <div class="-mx-3 -mt-3 flex shrink-0 items-center justify-between gap-2 border-b border-secondary-soft px-3 py-1.5 lg:hidden dark:border-line" data-sidebar-preferences>
        {{-- Same Light / Dark button as the desktop top header (layouts/app/sidebar.blade.php). --}}
        <flux:button
            x-data
            x-on:click="hcToggleAppearance()"
            variant="ghost"
            size="sm"
            square
            class="size-9! touch-manipulation text-secondary-medium! hover:bg-secondary-soft/70! hover:text-secondary-dark! max-lg:size-11! dark:text-ink-muted! dark:hover:bg-white/10! dark:hover:text-ink!"
            :aria-label="__('common.appearance')"
            :tooltip="__('common.appearance')"
            data-theme-toggle
        >
            <flux:icon.sun x-show="! $flux.dark" x-cloak variant="outline" class="size-4.5!" />
            <flux:icon.moon x-show="$flux.dark" x-cloak variant="outline" class="size-4.5!" />
        </flux:button>

        <x-locale-toggle />
    </div>

    <flux:sidebar.nav aria-label="{{ __('navigation.primary_navigation') }}" class="min-h-0 flex-1 gap-3 overflow-y-auto"
        x-data
        x-init="$el.scrollTop = Number(sessionStorage.getItem('sidebar-scroll') ?? 0); $el.closest('[data-navigation-sidebar]').style.setProperty('--sidebar-scrollbar', ($el.offsetWidth - $el.clientWidth) + 'px')"
        x-on:scroll.debounce.50ms="sessionStorage.setItem('sidebar-scroll', $el.scrollTop)"
        x-on:resize.window.debounce.150ms="$el.closest('[data-navigation-sidebar]').style.setProperty('--sidebar-scrollbar', ($el.offsetWidth - $el.clientWidth) + 'px')"
    >
        <flux:sidebar.group
            :heading="__('navigation.group_overview')"
            :class="$navigationGroupClasses"
        >
            <flux:sidebar.item
                icon="home"
                :href="route('dashboard')"
                :current="request()->routeIs('dashboard')"
                :class="$navigationItemClasses"
                wire:navigate.hover
            >
                {{ __('common.dashboard') }}
            </flux:sidebar.item>

            {{-- Anyone ever asked to review a proposal, whatever their role: reviewers hold no proposals.view. --}}
            @if ($hasReviews)
                <flux:sidebar.item
                    icon="document-magnifying-glass"
                    :href="route('reviews.index')"
                    :current="request()->routeIs('reviews.*')"
                    :class="$navigationItemClasses"
                    wire:navigate.hover
                    data-my-reviews-link
                >
                    {{ __('common.my_reviews') }}
                    @if ($awaitingReviews > 0)
                        <x-slot:icon-trailing>
                            <span
                                class="ms-auto inline-flex min-w-5 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-[10px] font-bold leading-5 tabular-nums text-white in-data-current:bg-white/25 in-data-flux-sidebar-collapsed-desktop:hidden dark:bg-status-critical-soft dark:text-status-critical"
                                data-my-reviews-badge
                            >{{ $awaitingReviews > 99 ? '99+' : $awaitingReviews }}<span class="sr-only"> {{ __('proposals.my_reviews_awaiting') }}</span></span>
                        </x-slot:icon-trailing>
                    @endif
                </flux:sidebar.item>
            @endif

            @can('executive-summary.view')
                <flux:sidebar.item
                    icon="chart-pie"
                    :href="route('executive-summary.index')"
                    :current="request()->routeIs('executive-summary.*')"
                    :class="$navigationItemClasses"
                    wire:navigate.hover
                >
                    {{ __('common.executive_summary') }}
                </flux:sidebar.item>
            @endcan
        </flux:sidebar.group>

        @canany(['strategies.view', 'programs.view', 'annual-plans.view'])
            <flux:sidebar.group
                :heading="__('navigation.group_strategy_planning')"
                :class="$navigationGroupClasses"
            >
                @can('strategies.view')
                    <flux:sidebar.item
                        icon="chart-bar-square"
                        :href="route('strategies.index')"
                        :current="request()->routeIs('strategies.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('common.strategies') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="strategies" :count="$moduleCounts['strategies'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>
                @endcan

                @can('programs.view')
                    <flux:sidebar.item
                        icon="briefcase"
                        :href="route('programs.index')"
                        :current="request()->routeIs('programs.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('common.programs') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="programs" :count="$moduleCounts['programs'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>
                @endcan

                @can('annual-plans.view')
                    <flux:sidebar.item
                        icon="calendar-days"
                        :href="route('annual-plans.index')"
                        :current="request()->routeIs('annual-plans.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('common.annual_plans') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="annual-plans" :count="$moduleCounts['annual-plans'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>
                @endcan
            </flux:sidebar.group>
        @endcanany

        @canany(['projects.view', 'milestones.view', 'risks.view'])
            <flux:sidebar.group
                :heading="__('navigation.group_projects_delivery')"
                :class="$navigationGroupClasses"
            >
                @can('projects.view')
                    <flux:sidebar.item
                        icon="rectangle-stack"
                        :href="route('projects.index')"
                        :current="request()->routeIs('projects.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('common.projects') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="projects" :count="$moduleCounts['projects'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>
                @endcan

                @can('milestones.view')
                    <flux:sidebar.item
                        icon="flag"
                        :href="route('milestones.index')"
                        :current="request()->routeIs('milestones.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('common.milestones') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="milestones" :count="$moduleCounts['milestones'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>
                @endcan

                @can('projects.view')
                    <flux:sidebar.item
                        icon="bars-3-bottom-left"
                        :href="route('timeline.index')"
                        :current="request()->routeIs('timeline.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('titles.timeline') }}
                    </flux:sidebar.item>
                @endcan

                @can('risks.view')
                    <flux:sidebar.item
                        icon="exclamation-triangle"
                        :href="route('risks.index')"
                        :current="request()->routeIs('risks.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('common.risks') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="risks" :count="$moduleCounts['risks'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>
                @endcan
            </flux:sidebar.group>
        @endcanany

        @canany(['results.view', 'indicator-library.view', 'indicators.view', 'targets.view', 'reports.view'])
            <flux:sidebar.group
                :heading="__('navigation.group_monitoring_evaluation')"
                :class="$navigationGroupClasses"
            >
                @can('results.view')
                    <flux:sidebar.item
                        icon="chart-bar"
                        :href="route('results.index')"
                        :current="request()->routeIs('results.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('common.results_framework') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="results" :count="$moduleCounts['results'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>
                @endcan

                @can('indicator-library.view')
                    <flux:sidebar.item
                        icon="book-open"
                        :href="route('indicator-library.index')"
                        :current="request()->routeIs('indicator-library.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('navigation.indicator_library') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="indicator-library" :count="$moduleCounts['indicator-library'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>
                @endcan

                @can('indicators.view')
                    <flux:sidebar.item
                        icon="variable"
                        :href="route('indicators.index')"
                        :current="request()->routeIs('indicators.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('common.indicators') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="indicators" :count="$moduleCounts['indicators'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>
                @endcan

                @can('targets.view')
                    <flux:sidebar.item
                        icon="viewfinder-circle"
                        :href="route('targets.index')"
                        :current="request()->routeIs('targets.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('common.targets') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="targets" :count="$moduleCounts['targets'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>
                @endcan

                @can('reports.view')
                    <flux:sidebar.item
                        icon="document-chart-bar"
                        :href="route('reports.index')"
                        :current="request()->routeIs('reports.index', 'reports.show')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('common.reports') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="reports" :count="$moduleCounts['reports'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>

                    <flux:sidebar.item
                        icon="table-cells"
                        :href="route('reports.monthly-matrix')"
                        :current="request()->routeIs('reports.monthly-matrix')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('navigation.monthly_matrix') }}
                    </flux:sidebar.item>
                @endcan
            </flux:sidebar.group>
        @endcanany

        {{-- In the order money arrives: donor, proposal, agreement, transfer, then the account it lands in. --}}
        @canany(['donors.view', 'proposals.view', 'funding-agreements.view', 'transfers.view', 'accounts.view'])
            <flux:sidebar.group
                :heading="__('navigation.group_funding_finance')"
                :class="$navigationGroupClasses"
            >
                @can('donors.view')
                    <flux:sidebar.item
                        icon="building-library"
                        :href="route('donors.index')"
                        :current="request()->routeIs('donors.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('common.donors') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="donors" :count="$moduleCounts['donors'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>
                @endcan

                @can('proposals.view')
                    <flux:sidebar.item
                        icon="document-text"
                        :href="route('proposals.index')"
                        :current="request()->routeIs('proposals.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('common.proposals') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="proposals" :count="$moduleCounts['proposals'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>
                @endcan

                @can('funding-agreements.view')
                    <flux:sidebar.item
                        icon="banknotes"
                        :href="route('funding-agreements.index')"
                        :current="request()->routeIs('funding-agreements.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('common.funding_agreements') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="funding-agreements" :count="$moduleCounts['funding-agreements'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>
                @endcan

                @can('transfers.view')
                    <flux:sidebar.item
                        icon="arrow-right-circle"
                        :href="route('transfers.index')"
                        :current="request()->routeIs('transfers.*')"
                        :class="$navigationItemClasses.' rtl:[&_svg]:-scale-x-100'"
                        wire:navigate.hover
                    >
                        {{ __('common.transfers') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="transfers" :count="$moduleCounts['transfers'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>
                @endcan

                @can('accounts.view')
                    <flux:sidebar.item
                        icon="credit-card"
                        :href="route('admin.accounts.index')"
                        :current="request()->routeIs('admin.accounts.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('common.accounts') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="accounts" :count="$moduleCounts['accounts'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>
                @endcan
            </flux:sidebar.group>
        @endcanany

        {{-- Last on purpose: the manager walkthrough runs overview, strategy, delivery, results, funding, then "this is what your team sees". --}}
        @canany(['tasks.view', 'calendar.view'])
            <flux:sidebar.group
                :heading="__('navigation.group_workspace')"
                :class="$navigationGroupClasses"
            >
                            @can('tasks.view')
                    <flux:sidebar.item
                        icon="clipboard-document-check"
                        :href="route('tasks.index')"
                        :current="request()->routeIs('tasks.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('common.tasks') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="tasks" :count="$moduleCounts['tasks'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>
                @endcan

                @can('tasks.view')
                    <flux:sidebar.item
                        icon="building-office-2"
                        :href="route('department-tasks.index')"
                        :current="request()->routeIs('department-tasks.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                        data-test="department-tasks-nav"
                    >
                        {{ __('titles.department_tasks') }}
                    </flux:sidebar.item>
                @endcan

                @can('calendar.view')
                    <flux:sidebar.item
                        icon="calendar"
                        :href="route('calendar.index')"
                        :current="request()->routeIs('calendar.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('common.my_calendar') }}
                    </flux:sidebar.item>
                @endcan
            </flux:sidebar.group>
        @endcanany

        @if ($showsAdministration)
            <flux:sidebar.group
                :heading="__('navigation.administration')"
                :class="$navigationGroupClasses"
            >
                @can('viewAny', App\Models\User::class)
                    <flux:sidebar.item
                        icon="users"
                        :href="route('admin.users.index')"
                        :current="request()->routeIs('admin.users.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('navigation.user_directory') }}
                        <x-slot:icon-trailing>
                            <x-notifications.module-badge module="access-management" :count="$moduleCounts['access-management'] ?? 0" />
                        </x-slot:icon-trailing>
                    </flux:sidebar.item>
                @endcan

                @can('notification-settings.manage')
                    <flux:sidebar.item
                        icon="adjustments-horizontal"
                        :href="route('admin.notification-settings.edit')"
                        :current="request()->routeIs('admin.notification-settings.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('navigation.notification_settings') }}
                    </flux:sidebar.item>
                @endcan

                @can('activity-log.view')
                    <flux:sidebar.item
                        icon="clock"
                        :href="route('activity.index')"
                        :current="request()->routeIs('activity.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('navigation.activity_log') }}
                    </flux:sidebar.item>
                @endcan

                @can('cities.view')
                    <flux:sidebar.item
                        icon="map-pin"
                        :href="route('admin.cities.index')"
                        :current="request()->routeIs('admin.cities.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('common.cities') }}
                    </flux:sidebar.item>
                @endcan

                @can('sectors.view')
                    <flux:sidebar.item
                        icon="squares-2x2"
                        :href="route('admin.sectors.index')"
                        :current="request()->routeIs('admin.sectors.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('navigation.sectors') }}
                    </flux:sidebar.item>
                @endcan

                @can('evidence-types.manage')
                    <flux:sidebar.item
                        icon="paper-clip"
                        :href="route('admin.evidence-types.index')"
                        :current="request()->routeIs('admin.evidence-types.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('navigation.evidence_types') }}
                    </flux:sidebar.item>
                @endcan

                @can('narrative-questions.manage')
                    <flux:sidebar.item
                        icon="chat-bubble-left-right"
                        :href="route('admin.narrative-questions.index')"
                        :current="request()->routeIs('admin.narrative-questions.*')"
                        :class="$navigationItemClasses"
                        wire:navigate.hover
                    >
                        {{ __('navigation.narrative_questions') }}
                    </flux:sidebar.item>
                @endcan
            </flux:sidebar.group>
        @endif
    </flux:sidebar.nav>
</flux:sidebar>
