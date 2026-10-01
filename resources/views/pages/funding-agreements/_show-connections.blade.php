{{--
    Every record this money is tied to, each one link away. Shown in both
    views of ⚡show.blade.php: a single thread beside the journeys in the
    Overview view, and in columns under the tables ($connectionsAsColumns).
--}}
        <aside class="min-w-0" aria-labelledby="connections-heading" data-connections>
            <h2 id="connections-heading" class="text-xl font-bold tracking-tight {{ $inkText }}">{{ __('funding_agreements.connected_records') }}</h2>

            <ol @class(['mt-5', 'connection-thread flex flex-col' => ! ($connectionsAsColumns ?? false), 'grid gap-x-12 @4xl/detail:grid-cols-2 @7xl/detail:grid-cols-3' => $connectionsAsColumns ?? false])>
                {{-- Donor --}}
                <li class="relative ps-7 pb-8" data-connection="donor">
                    <span class="absolute start-0 top-1 size-3 rounded-full border-2 border-secondary-dark bg-white dark:border-ink-muted dark:bg-surface-1" aria-hidden="true"></span>
                    <p class="type-label {{ $mutedText }}">{{ __('funding_agreements.donor') }}</p>
                    <a href="{{ route('donors.show', $donor) }}" wire:navigate class="mt-1 inline-block text-base font-bold underline-offset-4 hover:underline {{ $inkText }}">{{ $donor->name }}</a>
                    @if ($donor->type !== null || filled($donor->country))
                        <p class="text-xs {{ $mutedText }}">{{ collect([$donor->type?->label(), $donor->country])->filter()->implode(' · ') }}</p>
                    @endif
                    @if (filled($donor->contact_person) || filled($donor->email) || filled($donor->phone))
                        <p class="mt-2 text-xs {{ $inkText }}">{{ $donor->contact_person }}</p>
                        <p class="flex flex-wrap gap-x-3 text-xs {{ $mutedText }}">
                            @if (filled($donor->email))
                                <a href="mailto:{{ $donor->email }}" class="underline-offset-4 hover:underline">{{ $donor->email }}</a>
                            @endif
                            @if (filled($donor->phone))
                                <a href="tel:{{ $donor->phone }}" class="tabular-nums underline-offset-4 hover:underline" dir="ltr">{{ $donor->phone }}</a>
                            @endif
                        </p>
                    @endif
                    <p @class(['mt-2 text-xs', 'font-semibold text-status-critical' => $donor->isFollowUpOverdue(), $mutedText => ! $donor->isFollowUpOverdue()])>
                        @if (filled($donor->next_step))
                            {{ $donor->next_step_due_on !== null
                                ? __('funding_agreements.next_step_due', ['step' => $donor->next_step, 'date' => $donor->next_step_due_on->translatedFormat(__('formats.date'))])
                                : $donor->next_step }}
                        @else
                            {{ __('funding_agreements.no_next_step_planned') }}
                        @endif
                    </p>
                    <p @class(['text-xs tabular-nums', 'font-semibold text-status-warn' => $this->donorContactIsStale(), $mutedText => ! $this->donorContactIsStale()]) data-last-contacted>
                        {{ $donor->last_contacted_on !== null ? __('donors.last_contacted', ['date' => $donor->last_contacted_on->translatedFormat(__('formats.date'))]) : __('donors.never_contacted') }}
                    </p>

                    @if ($donorAgreements->isNotEmpty())
                        <p class="type-label mt-4 {{ $mutedText }}">{{ __('funding_agreements.also_from_donor', ['donor' => $donor->name]) }}</p>
                        <ul class="mt-1.5 flex flex-col gap-1.5">
                            @foreach ($donorAgreements as $sibling)
                                <li class="flex items-baseline justify-between gap-3 text-xs" wire:key="donor-agreement-{{ $sibling->id }}">
                                    <a href="{{ route('funding-agreements.show', $sibling) }}" wire:navigate class="min-w-0 truncate underline-offset-4 hover:underline {{ $inkText }}">{{ $sibling->proposal->name }}</a>
                                    <span class="shrink-0 text-end tabular-nums {{ $mutedText }}">{{ __('funding_agreements.landed_of_budget', ['landed' => $amount((float) $sibling->landed), 'budget' => $amount((float) $sibling->budget), 'currency' => $sibling->currency->value]) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>

                {{-- Proposal --}}
                <li class="relative ps-7 pb-8" data-connection="proposal">
                    <span class="absolute start-0 top-1 size-3 rounded-full border-2 border-secondary-dark bg-white dark:border-ink-muted dark:bg-surface-1" aria-hidden="true"></span>
                    <p class="type-label {{ $mutedText }}">{{ __('common.proposal') }}</p>
                    <a href="{{ route('proposals.show', $proposal) }}" wire:navigate class="mt-1 inline-block text-base font-bold underline-offset-4 hover:underline {{ $inkText }}">{{ $proposal->name }}</a>
                    <p class="mt-1 flex flex-wrap items-center gap-2 text-xs {{ $mutedText }}">
                        <span>{{ $proposal->status->label() }}</span>
                        <span aria-hidden="true">·</span>
                        <span>{{ $proposal->phase->label() }}</span>
                    </p>

                    @if ($term['phase'] !== 'unknown')
                        <div class="mt-3" data-term>
                            <div class="flex items-baseline justify-between gap-3 text-[11px] tabular-nums {{ $mutedText }}">
                                <span>{{ $term['begin']->translatedFormat(__('formats.date_short')) }}</span>
                                <span class="font-semibold {{ $inkText }}">
                                    @if ($term['phase'] === 'ahead')
                                        {{ __('funding_agreements.term_starts', ['date' => $term['begin']->translatedFormat(__('formats.date'))]) }}
                                    @elseif ($term['phase'] === 'ended')
                                        {{ __('funding_agreements.term_ended') }}
                                    @else
                                        {{ __('funding_agreements.term_day', ['day' => $term['day'], 'days' => $term['days']]) }}
                                    @endif
                                </span>
                                <span>{{ $term['end']->translatedFormat(__('formats.date_short')) }}</span>
                            </div>
                            <div class="relative mt-1.5 h-1 rounded-full bg-secondary-soft dark:bg-surface-2" role="img" aria-label="{{ __('funding_agreements.term_day', ['day' => $term['day'], 'days' => $term['days']]) }}">
                                <span class="money-grow absolute inset-y-0 start-0 rounded-full bg-secondary-dark dark:bg-ink-muted" style="width: {{ $term['percent'] }}%"></span>
                            </div>
                        </div>
                    @endif

                    @if ($coFunders->isNotEmpty())
                        <p class="type-label mt-4 {{ $mutedText }}">{{ __('funding_agreements.co_funders') }}</p>
                        <ul class="mt-1.5 flex flex-col gap-1.5">
                            @foreach ($coFunders as $coFunder)
                                <li class="flex items-baseline justify-between gap-3 text-xs" wire:key="co-funder-{{ $coFunder->id }}">
                                    <a href="{{ route('funding-agreements.show', $coFunder) }}" wire:navigate class="min-w-0 truncate underline-offset-4 hover:underline {{ $inkText }}">{{ $coFunder->donor->name }}</a>
                                    <span class="shrink-0 text-end tabular-nums {{ $mutedText }}">{{ __('funding_agreements.landed_of_budget', ['landed' => $amount((float) $coFunder->landed), 'budget' => $amount((float) $coFunder->budget), 'currency' => $coFunder->currency->value]) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-3 text-xs {{ $mutedText }}">{{ __('funding_agreements.sole_funder') }}</p>
                    @endif
                </li>

                {{-- Projects --}}
                <li class="relative ps-7 pb-8" data-connection="projects">
                    <span class="absolute start-0 top-1 size-3 rounded-full border-2 border-secondary-dark bg-white dark:border-ink-muted dark:bg-surface-1" aria-hidden="true"></span>
                    <p class="type-label {{ $mutedText }}">{{ __('funding_agreements.projects_funded') }} <span class="tabular-nums">{{ $this->projects->count() }}</span></p>
                    @forelse ($this->projects as $project)
                        <div class="mt-2.5 min-w-0" wire:key="project-{{ $project->id }}" data-project="{{ $project->id }}">
                            <div class="flex items-baseline justify-between gap-3">
                                <a href="{{ route('projects.show', $project) }}" wire:navigate class="min-w-0 truncate text-sm font-semibold underline-offset-4 hover:underline {{ $inkText }}">{{ $project->name }}</a>
                                <span class="shrink-0 text-[11px] {{ $mutedText }}">{{ $project->status->label() }}</span>
                            </div>
                            <p class="truncate text-xs {{ $mutedText }}">{{ collect([$project->program?->name, $project->city?->name ?? __('common.unknown_city')])->filter()->implode(' · ') }}</p>
                            @if ($project->milestones_count > 0)
                                <div class="mt-1.5 flex items-center gap-2">
                                    <span class="flex h-1 flex-1 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" aria-hidden="true">
                                        <span class="money-grow h-full rounded-full bg-secondary-dark dark:bg-ink-muted" style="width: {{ round($project->reached_milestones_count / $project->milestones_count * 100, 2) }}%"></span>
                                    </span>
                                    <span class="shrink-0 text-[11px] tabular-nums {{ $mutedText }}">{{ __('funding_agreements.milestones_reached', ['reached' => $project->reached_milestones_count, 'total' => $project->milestones_count]) }}</span>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="mt-1.5 text-xs {{ $mutedText }}">{{ __('funding_agreements.no_projects_linked_yet') }}</p>
                    @endforelse
                </li>

                {{-- Accounts --}}
                <li class="relative ps-7 pb-8" data-connection="accounts">
                    <span class="absolute start-0 top-1 size-3 rounded-full border-2 border-secondary-dark bg-white dark:border-ink-muted dark:bg-surface-1" aria-hidden="true"></span>
                    <p class="type-label {{ $mutedText }}">{{ __('funding_agreements.accounts') }}</p>
                    @forelse ($this->accounts as $account)
                        <div class="mt-2 flex items-baseline justify-between gap-3 text-xs" wire:key="account-{{ $loop->index }}">
                            <span class="min-w-0 truncate font-semibold {{ $inkText }}">{{ $account['name'] }}@unless ($account['active']) <span class="font-normal {{ $mutedText }}">· {{ __('common.closed') }}</span>@endunless</span>
                            <span class="shrink-0 text-end tabular-nums {{ $mutedText }}">
                                @forelse ($account['landed'] as $landedCode => $landedTotal)
                                    <span class="block font-semibold text-funding">{{ $amount($landedTotal) }} {{ $landedCode }}</span>
                                @empty
                                    {{ trans_choice('funding_agreements.count_tranches', $account['tranches'], ['count' => $account['tranches']]) }}
                                @endforelse
                            </span>
                        </div>
                    @empty
                        <p class="mt-1.5 text-xs {{ $mutedText }}">{{ __('funding_agreements.no_account_assigned') }}</p>
                    @endforelse
                </li>

                {{-- Paperwork --}}
                <li class="relative ps-7" data-connection="paperwork">
                    <span class="absolute start-0 top-1 size-3 rounded-full border-2 border-secondary-dark bg-white dark:border-ink-muted dark:bg-surface-1" aria-hidden="true"></span>
                    <p class="type-label {{ $mutedText }}">{{ __('funding_agreements.paperwork') }}</p>
                    @forelse ($this->paperwork as $file)
                        <a href="{{ $this->paperworkUrl($file) }}" class="group mt-2 flex items-start gap-2 text-xs" wire:key="paper-{{ $file->id }}" aria-label="{{ __('common.download_name', ['name' => $file->original_name]) }}">
                            <flux:icon.document-text variant="micro" class="mt-0.5 size-4 shrink-0 {{ $mutedText }}" aria-hidden="true" />
                            <span class="min-w-0">
                                <span class="block truncate font-semibold underline-offset-4 group-hover:underline {{ $inkText }}">{{ $file->original_name }}</span>
                                <span class="block truncate {{ $mutedText }}">{{ $file->collection->label() }}@if ($file->mediable !== null) · {{ $file->mediable->name }}@endif</span>
                            </span>
                        </a>
                    @empty
                        <p class="mt-1.5 text-xs {{ $mutedText }}">{{ __('funding_agreements.no_paperwork_filed') }}</p>
                    @endforelse
                </li>
            </ol>
        </aside>
