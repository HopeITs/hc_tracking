        {{-- Dossiers: one proposal per row, everything it is connected to. --}}
        <section aria-labelledby="dossiers-heading" class="border-t pt-6 {{ $hairline }}">
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                <h2 id="dossiers-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.every_proposal_and_its_connections') }}</h2>
                <p class="text-xs {{ $mutedText }}">{{ trans_choice('proposals.count_matching_proposal', $this->proposals->total(), ['count' => $this->proposals->total()]) }}</p>
            </div>

            @if ($this->proposals->isEmpty())
                <x-ui.empty-state icon="document-text" :heading="__('proposals.no_matching_proposals')" :body="__('common.change_the_search_or_filters_and_try_again')" />
            @else
                <div class="-mx-3 mt-2">
                    @foreach ($this->proposals as $proposal)
                        @php
                            $flowKey = 'p'.$proposal->id;
                            $isRejected = $proposal->status === \App\Enums\ProposalStatus::Rejected;
                            // A hold or a rejection sits at the stop it left; a rejection marks the next stop.
                            $reachedStep = (int) array_search($proposal->lifecycleStop(), $lifecycle, true);
                            $statusTargets = $proposal->transitionTargets();
                            $nextStatus = $proposal->status->next();
                            $nextStatus = $nextStatus !== null && in_array($nextStatus, $statusTargets, true) ? $nextStatus : null;
                            $latestNote = $proposal->statusNotes->first();
                            $taskSlots = min($proposal->tasks_count, 24);
                            $doneSlots = $proposal->tasks_count > 0 ? (int) round($proposal->done_tasks_count / $proposal->tasks_count * $taskSlots) : 0;
                        @endphp
                        <article
                            id="proposal-{{ $proposal->id }}"
                            wire:key="proposal-{{ $proposal->id }}"
                            x-on:mouseenter="$store.proposalFlow.focus = '{{ $flowKey }}'"
                            x-on:mouseleave="$store.proposalFlow.focus = null"
                            :class="$store.proposalFlow.isLit('{{ $flowKey }}') ? 'bg-board-head dark:bg-white/[0.04]' : ($store.proposalFlow.isRowDimmed('{{ $flowKey }}') ? 'opacity-45' : '')"
                            class="logframe-reveal relative scroll-mt-24 rounded-xl px-3 py-7 transition-[background-color,opacity] duration-200 not-first:before:absolute not-first:before:inset-x-3 not-first:before:top-0 not-first:before:h-px not-first:before:bg-secondary-soft target:bg-board-head dark:not-first:before:bg-line"
                            style="--logframe-order: {{ $loop->index }}"
                            data-proposal-row
                        >
                            {{-- Identity and actions --}}
                            <div class="flex flex-col gap-3 @3xl/directory:flex-row @3xl/directory:items-start">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
                                        @if (in_array($proposal->id, $this->unreadIds, true))
                                            <span class="size-2 shrink-0 rounded-full bg-primary dark:bg-ink" data-unread-dot="proposal-{{ $proposal->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                                        @endif
                                        <span class="text-lg leading-tight font-bold {{ $inkText }}">{{ $proposal->name }}</span>
                                        <x-ui.project-number :number="$proposal->internal_project_number" kind="internal" class="text-xs {{ $mutedText }}" />
                                        <x-ui.enum-badge :case="$proposal->status" />
                                        {{-- phase is a read-only accessor over status->phase(), not a column. --}}
                                        <span class="text-xs font-semibold {{ $mutedText }}">{{ $proposal->phase->label() }}</span>
                                    </div>
                                    <p class="mt-1 text-xs tabular-nums {{ $mutedText }}">
                                        {{ __('proposals.planned_range', ['begin' => $proposal->planned_date_begin?->translatedFormat(__('formats.date')), 'end' => $proposal->planned_date_end?->translatedFormat(__('formats.date'))]) }}
                                        @if ($proposal->actual_date_begin !== null)
                                            <span aria-hidden="true">·</span>
                                            {{ __('proposals.actual_begin', ['begin' => $proposal->actual_date_begin->translatedFormat(__('formats.date'))]) }}
                                        @endif
                                    </p>
                                </div>

                                <div class="flex shrink-0 flex-wrap items-center gap-1">
                                    <x-ui.button tone="ghost" size="sm" icon="eye" class="size-10! touch-manipulation" :href="route('proposals.show', $proposal)" wire:navigate :aria-label="__('common.view_name', ['name' => $proposal->name])" />
                                    @can('update', $proposal)
                                    @if ($nextStatus !== null)
                                        <x-ui.button size="sm" icon:trailing="arrow-right" class="min-h-10 touch-manipulation rtl:[&_svg]:rotate-180" wire:click="openStatusModal({{ $proposal->id }})" data-test="advance-proposal-{{ $proposal->id }}">
                                            {{ __('proposals.move_to_status', ['status' => $nextStatus->label()]) }}
                                        </x-ui.button>
                                    @elseif ($statusTargets !== [])
                                        <x-ui.button size="sm" tone="ghost" icon="arrows-right-left" class="min-h-10 touch-manipulation" wire:click="openStatusModal({{ $proposal->id }})" data-test="advance-proposal-{{ $proposal->id }}">
                                            {{ __('proposals.change_status') }}
                                        </x-ui.button>
                                    @endif
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-10! touch-manipulation" wire:click="openEditModal({{ $proposal->id }})" :aria-label="__('common.edit_name', ['name' => $proposal->name])" />
                                    @endcan
                                    @can('delete', $proposal)
                                    @if ($proposal->donor_proposals_count === 0)
                                        <x-ui.button tone="ghost" size="sm" icon="trash" class="size-10! touch-manipulation" wire:click="confirmDelete({{ $proposal->id }})" :aria-label="__('common.delete_name', ['name' => $proposal->name])" />
                                    @else
                                        <flux:tooltip :content="__('proposals.funded_proposals_cannot_be_deleted_remove_the_funding_agreem')">
                                            <span class="inline-flex size-10 cursor-not-allowed items-center justify-center rounded-lg text-secondary-light focus-visible:outline-2 focus-visible:outline-secondary-medium" aria-disabled="true" tabindex="0" aria-label="{{ __('proposals.funded_proposals_cannot_be_deleted_remove_the_funding_agreem') }}">
                                                <flux:icon.lock-closed class="size-4" />
                                            </span>
                                        </flux:tooltip>
                                    @endif
                                    @endcan
                                </div>
                            </div>

                            {{-- Lifecycle stepper --}}
                            <ol class="mt-5 grid grid-cols-9" aria-label="{{ __('proposals.lifecycle') }}">
                                @foreach ($lifecycle as $index => $step)
                                    @php
                                        $isCurrent = ! $isRejected && $index === $reachedStep;
                                        $isPast = $index < $reachedStep || ($isRejected && $index === $reachedStep);
                                        $isRejectionPoint = $isRejected && $index === $reachedStep + 1;
                                        $isAhead = ! $isPast && ! $isCurrent && ! $isRejectionPoint;
                                    @endphp
                                    <li class="relative flex flex-col items-center gap-1.5" @if ($isCurrent || $isRejectionPoint) aria-current="step" @endif>
                                        @if (! $loop->first)
                                            <span @class([
                                                'absolute end-1/2 top-[0.3125rem] h-0.5 w-full -translate-y-1/2',
                                                'bg-secondary-dark dark:bg-ink-muted' => $isPast || $isCurrent,
                                                'bg-primary dark:bg-status-critical' => $isRejectionPoint,
                                                'bg-secondary-soft dark:bg-line-strong' => $isAhead,
                                            ]) aria-hidden="true"></span>
                                        @endif
                                        <span @class([
                                            'relative z-10 size-2.5 rounded-full',
                                            'bg-primary dark:bg-brand ring-4 ring-secondary-light' => $isCurrent || $isRejectionPoint,
                                            'bg-secondary-dark dark:bg-ink-muted' => $isPast,
                                            'border-2 border-secondary-soft bg-white dark:border-line-strong dark:bg-surface-1' => $isAhead,
                                        ]) aria-hidden="true"></span>
                                        <span @class([
                                            'px-0.5 text-center text-[11px] leading-tight',
                                            'font-bold text-primary dark:text-brand-text' => $isCurrent || $isRejectionPoint,
                                            'hidden font-medium @4xl/directory:block '.$inkText => $isPast,
                                            'hidden font-medium text-secondary-light @4xl/directory:block' => $isAhead,
                                        ])>{{ $isRejectionPoint ? \App\Enums\ProposalStatus::Rejected->label() : $step->label() }}</span>
                                    </li>
                                @endforeach
                            </ol>

                            {{-- Connections --}}
                            <div @class(['mt-6 grid gap-x-10 gap-y-6', '@4xl/directory:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)_minmax(0,1fr)]' => $canAgreements, '@4xl/directory:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]' => ! $canAgreements])>
                                {{-- Funding --}}
                                @if ($canAgreements)
                                <div class="min-w-0" data-connection="funding">
                                    <p class="type-label {{ $mutedText }}">{{ __('common.funding') }}</p>
                                    @forelse ($proposal->donorProposals as $agreement)
                                        @php
                                            $money = $this->agreementMoney($agreement);
                                            $budget = (float) $agreement->budget;
                                        @endphp
                                        <div class="mt-2 [&+&]:mt-4" wire:key="agreement-{{ $agreement->id }}">
                                            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                                                <p class="min-w-0 text-sm">
                                                    <a href="{{ route('donors.show', $agreement->donor) }}" wire:navigate class="font-bold underline-offset-4 hover:underline {{ $inkText }}">{{ $agreement->donor->name }}</a>
                                                    <a href="{{ route('funding-agreements.show', $agreement) }}" wire:navigate class="ms-1.5 text-xs underline-offset-4 hover:underline {{ $mutedText }}">{{ filled($agreement->external_project_number) ? __('proposals.agreement_number', ['number' => $agreement->external_project_number]) : __('proposals.view_agreement') }}</a>
                                                </p>
                                                <p class="text-xl font-extrabold tracking-tight tabular-nums {{ $inkText }}">{{ $amount($budget) }} <span class="text-xs font-semibold tracking-normal {{ $mutedText }}">{{ $agreement->currency->value }}</span></p>
                                            </div>
                                            <div class="mt-2 flex h-2 w-full gap-0.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" role="img" aria-label="{{ __('proposals.money_bar_label', ['currency' => $agreement->currency->value, 'received' => $amount($money['received']), 'pending' => $amount($money['pending']), 'committed' => $amount($budget)]) }}">
                                                <span class="money-grow h-full rounded-s-full bg-funding" style="width: {{ $budget > 0 ? min(100, round($money['received'] / $budget * 100, 2)) : 0 }}%"></span>
                                                <span class="money-grow money-pending h-full" style="width: {{ $budget > 0 ? min(100, round($money['pending'] / $budget * 100, 2)) : 0 }}%"></span>
                                            </div>
                                            <p class="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs tabular-nums {{ $mutedText }}">
                                                <span><span class="font-bold {{ $receivedText }}">{{ $amount($money['received']) }}</span> {{ __('proposals.received_percent', ['percent' => App\Models\DonorProposal::percentLabel($money['received'], $budget)]) }}</span>
                                                <span><span class="font-bold text-status-warn">{{ $amount($money['pending']) }}</span> {{ __('proposals.pending_lower') }}</span>
                                                @if ($money['nextDue'] !== null)
                                                    <span @class(['font-semibold text-primary dark:text-brand-text' => $money['nextDue']->lt(today())])>{{ __('proposals.next_transfer_on', ['date' => $money['nextDue']->translatedFormat(__('formats.date'))]) }}</span>
                                                @endif
                                                @foreach ($money['otherCurrencies'] as $otherCurrency => $otherAmount)
                                                    <span>{{ __('proposals.also_received_in', ['amount' => $amount($otherAmount), 'currency' => $otherCurrency]) }}</span>
                                                @endforeach
                                            </p>
                                        </div>
                                    @empty
                                        <p class="mt-2 text-sm {{ $mutedText }}">{{ __('proposals.no_funding_agreements') }}</p>
                                    @endforelse
                                </div>
                                @endif

                                {{-- Projects --}}
                                <div class="min-w-0" data-connection="projects">
                                    <p class="type-label {{ $mutedText }}">{{ __('common.projects') }} <span class="tabular-nums">· {{ $proposal->projects->count() }}</span></p>
                                    @if ($proposal->projects->isEmpty())
                                        <p class="mt-2 text-sm {{ $mutedText }}">{{ __('proposals.no_projects_linked') }}</p>
                                    @else
                                        <ul class="relative mt-1 flex flex-col" role="list">
                                            <span class="absolute start-[0.1875rem] top-4 bottom-4 w-px bg-secondary-soft dark:bg-line-strong" aria-hidden="true"></span>
                                            @foreach ($proposal->projects as $project)
                                                <li class="relative flex min-h-9 items-center gap-x-2.5 gap-y-0.5 py-1 ps-5" wire:key="proposal-{{ $proposal->id }}-project-{{ $project->id }}">
                                                    <span class="absolute start-0 size-2 rounded-full border-2 border-secondary-dark bg-white dark:border-ink-muted dark:bg-surface-1" aria-hidden="true"></span>
                                                    <a href="{{ route('projects.show', $project) }}" wire:navigate class="min-w-0 text-sm leading-snug font-semibold underline-offset-4 hover:underline {{ $inkText }}">{{ $project->name }}</a>
                                                    <span class="shrink-0 text-[11px] {{ $mutedText }}">{{ $project->status->label() }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>

                                {{-- Delivery --}}
                                <div class="min-w-0" data-connection="delivery">
                                    <p class="type-label {{ $mutedText }}">{{ __('proposals.delivery') }}</p>
                                    <dl class="mt-2 flex flex-col gap-3.5">
                                        <div>
                                            <dt class="sr-only">{{ __('common.tasks') }}</dt>
                                            <dd>
                                                <p class="text-sm {{ $inkText }}">
                                                    @if ($proposal->tasks_count === 0)
                                                        <span class="{{ $mutedText }}">{{ __('proposals.no_tasks_yet') }}</span>
                                                    @else
                                                        {{ __('proposals.tasks_done_of', ['done' => $proposal->done_tasks_count, 'total' => $proposal->tasks_count]) }}
                                                        @if ($proposal->overdue_tasks_count > 0)
                                                            <span class="ms-1 font-bold text-primary dark:text-brand-text">{{ trans_choice('proposals.count_overdue', $proposal->overdue_tasks_count, ['count' => $proposal->overdue_tasks_count]) }}</span>
                                                        @endif
                                                    @endif
                                                </p>
                                                @if ($taskSlots > 0)
                                                    <div class="mt-2 flex h-1.5 w-full gap-0.5" aria-hidden="true">
                                                        @for ($slot = 0; $slot < $taskSlots; $slot++)
                                                            <span @class(['h-full flex-1 rounded-full', 'bg-status-good' => $slot < $doneSlots, 'bg-secondary-soft dark:bg-line-strong' => $slot >= $doneSlots])></span>
                                                        @endfor
                                                    </div>
                                                @endif
                                            </dd>
                                        </div>
                                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm">
                                            <dt class="{{ $mutedText }}">{{ __('common.targets') }}</dt>
                                            <dd class="me-3 font-bold tabular-nums {{ $inkText }}">{{ $proposal->plan_indicators_count }}</dd>
                                            <dt class="{{ $mutedText }}">{{ __('proposals.status_changes') }}</dt>
                                            <dd class="font-bold tabular-nums {{ $inkText }}">{{ $proposal->status_notes_count }}</dd>
                                        </div>
                                        @if ($latestNote !== null)
                                            <div class="relative ps-5">
                                                <span class="absolute start-0 top-1 size-2 rounded-full bg-secondary-light" aria-hidden="true"></span>
                                                <dt class="text-xs {{ $mutedText }}">{{ __('proposals.latest_note_on', ['date' => $latestNote->date?->translatedFormat(__('formats.date')), 'status' => $latestNote->status->label()]) }}</dt>
                                                <dd class="mt-0.5 line-clamp-2 text-sm {{ $inkText }}">{{ $latestNote->context }}</dd>
                                            </div>
                                        @endif
                                    </dl>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif

            <div class="mt-2 border-t pt-4 {{ $hairline }}">
                <flux:pagination :paginator="$this->proposals" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#proposal-pipeline-heading" />
            </div>
        </section>
