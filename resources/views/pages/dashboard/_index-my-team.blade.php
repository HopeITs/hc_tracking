@use('Illuminate\Support\Number')

    {{-- My team: overdue tasks of the people the signed-in person supervises (T23) --}}
    @if ($this->myTeam !== [])
        <section class="{{ $cardClasses }} min-w-0" aria-labelledby="my-team-heading" data-my-team>
            <div class="border-b border-secondary-soft px-5 pt-4 pb-3 dark:border-line">
                <h2 id="my-team-heading" class="text-base font-bold text-secondary-dark dark:text-ink">{{ __('dashboard.my_team') }}</h2>
                <p class="mt-0.5 text-xs text-secondary-medium dark:text-ink-muted">{{ __('dashboard.my_team_hint') }}</p>
            </div>

            <ul class="grid grid-cols-1 gap-x-6 px-5 py-2 @3xl/dashboard:grid-cols-2 @5xl/dashboard:grid-cols-3" role="list">
                @foreach ($this->myTeam as $person)
                    @php
                        $isLate = $person['overdue'] > 0;
                    @endphp
                    <li class="relative ps-5" wire:key="my-team-{{ $person['employeeId'] }}" data-my-team-person>
                        <span class="absolute start-[0.2rem] top-0 bottom-0 w-px bg-secondary-soft dark:bg-line" aria-hidden="true"></span>
                        <span @class([
                            'absolute start-0 top-1/2 size-2 -translate-y-1/2 rounded-full ring-2 ring-white dark:ring-surface-1',
                            'bg-status-critical' => $isLate,
                            'bg-status-good' => ! $isLate,
                        ]) aria-hidden="true"></span>
                        <a
                            href="{{ route('tasks.index', ['assignee' => $person['employeeId']]) }}"
                            wire:navigate
                            aria-label="{{ __('dashboard.my_team_view_tasks', ['name' => $person['name']]) }}"
                            class="group flex min-h-14 items-center gap-3 rounded-md py-2 focus-visible:outline-2 focus-visible:outline-primary"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-secondary-dark group-hover:underline group-hover:underline-offset-4 dark:text-ink">{{ $person['name'] }}</span>
                                <span @class([
                                    'flex items-center gap-1 text-xs',
                                    'font-semibold text-status-critical' => $isLate,
                                    'text-secondary-medium dark:text-ink-muted' => ! $isLate,
                                ])>
                                    <flux:icon :name="$isLate ? 'clock' : 'check-circle'" variant="micro" class="size-3.5 shrink-0" />
                                    {{ $isLate ? trans_choice('dashboard.my_team_overdue', $person['overdue']) : __('dashboard.my_team_on_track') }}
                                </span>
                            </span>
                            @if ($isLate)
                                <span class="type-figure text-status-critical dark:text-ink">{{ Number::format($person['overdue']) }}</span>
                            @endif
                            <flux:icon.chevron-right variant="micro" class="size-4 shrink-0 text-secondary-light transition-transform duration-200 group-hover:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5" />
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
