{{--
    After the program logframes: one small card per proposal that has its own logframe
    (often started from a program's). Each opens that proposal's Logical Framework tab.
    Hidden for a user who can open neither proposals nor projects.
--}}
@if (auth()->user()->can('proposals.view') || auth()->user()->can('projects.view'))
    <section class="space-y-3 pt-2" aria-labelledby="proposal-logframes-heading" data-test="proposal-logframes">
        <div>
            <h2 id="proposal-logframes-heading" class="text-base font-bold text-secondary-dark dark:text-ink">
                {{ __('results_framework.proposal_logframes') }}
                <span class="ms-1 inline-flex min-w-6 items-center justify-center rounded-full bg-secondary-soft px-2 py-0.5 text-[11px] font-semibold tabular-nums text-secondary-dark dark:bg-surface-2 dark:text-ink">{{ $proposalFrameworks->count() }}</span>
            </h2>
            <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('results_framework.proposal_logframes_hint') }}</p>
        </div>

        @if ($proposalFrameworks->isEmpty())
            <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ $filtersAreActive ? __('results_framework.proposal_logframes_none') : __('results_framework.proposal_logframes_empty') }}</p>
        @else
            <ul class="grid grid-cols-1 gap-3 @md/directory:grid-cols-2 @3xl/directory:grid-cols-3 @6xl/directory:grid-cols-4">
                @foreach ($proposalFrameworks as $proposalFramework)
                    <li wire:key="proposal-logframe-{{ $proposalFramework['id'] }}">
                        <a
                            href="{{ $proposalFramework['href'] }}"
                            wire:navigate
                            class="group flex h-full items-center gap-3 rounded-xl border border-secondary-soft bg-white px-4 py-3 shadow-card transition-colors hover:border-primary dark:border-transparent dark:bg-surface-1 dark:shadow-raised dark:hover:border-brand-text"
                            aria-label="{{ __('results_framework.open_proposal_logframe', ['name' => $proposalFramework['name']]) }}"
                            data-test="proposal-logframe-card-{{ $proposalFramework['id'] }}"
                        >
                            <flux:icon.document-text variant="micro" class="size-4 shrink-0 text-secondary-medium group-hover:text-primary dark:text-ink-muted dark:group-hover:text-brand-text" aria-hidden="true" />
                            <span class="min-w-0 flex-1 line-clamp-2 text-sm font-semibold text-secondary-dark group-hover:text-primary dark:text-ink dark:group-hover:text-brand-text" dir="auto">{{ $proposalFramework['name'] }}</span>
                            <flux:icon.arrow-right variant="micro" class="size-4 shrink-0 text-secondary-medium rtl:-scale-x-100 dark:text-ink-muted" aria-hidden="true" />
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
