{{--
    The Overview view of the proposals page: where the money stands, the
    pipeline rail, the funding flow and one dossier per proposal. Included by
    ⚡index.blade.php, which defines $amount, $shortAmount, $canAgreements,
    $mutedText, $inkText, $receivedText and $hairline.
--}}
@php
    $lifecycle = \App\Enums\ProposalStatus::lifecycle();
    $matchingCount = $this->filteredProposalIds->count();
    // The flow names every matching proposal but the list below shows one page
    // of them: a proposal on this page is scrolled to, any other one opened.
    $pageProposalIds = $this->proposals->getCollection()->modelKeys();
    $proposalHref = fn (int $id): string => in_array($id, $pageProposalIds, true) ? '#proposal-'.$id : route('proposals.show', tap(new \App\Models\Proposal, fn ($proposal) => $proposal->id = $id));
@endphp

        {{-- Where the money stands: one line per currency, never summed across them. --}}
        @if ($canAgreements)
        <section aria-labelledby="money-heading" class="pt-3" data-funding-position>
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                <h2 id="money-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.where_the_money_stands') }}</h2>
                <p class="text-xs {{ $mutedText }}">{{ __('proposals.budgets_are_shown_per_currency_they_are_never_added_together') }}</p>
            </div>

            @forelse ($this->fundingPosition as $line)
                @php
                    $barBase = max($line['committed'], $line['received'] + $line['pending'], 1);
                    $figures = [
                        ['key' => 'committed', 'label' => __('proposals.committed'), 'value' => $line['committed'], 'tone' => $inkText, 'note' => trans_choice('proposals.across_count_proposals', $matchingCount, ['count' => $matchingCount])],
                        ['key' => 'received', 'label' => __('proposals.received'), 'value' => $line['received'], 'tone' => $receivedText, 'note' => __('proposals.percent_of_committed', ['percent' => App\Models\DonorProposal::percentLabel($line['received'], $line['committed'])])],
                        ['key' => 'pending', 'label' => __('proposals.pending'), 'value' => $line['pending'], 'tone' => 'text-status-warn', 'note' => trans_choice('proposals.count_transfers_scheduled', $line['pendingCount'], ['count' => $line['pendingCount']])],
                        ['key' => 'unscheduled', 'label' => __('proposals.not_yet_scheduled'), 'value' => $line['unscheduled'], 'tone' => $mutedText, 'note' => __('proposals.committed_with_no_transfer_yet')],
                    ];
                @endphp
                <div class="mt-5 border-t pt-5 {{ $hairline }}" wire:key="money-{{ $line['currency'] }}">
                    <div class="grid grid-cols-2 gap-x-6 gap-y-6 @4xl/directory:grid-cols-[3.5rem_repeat(4,minmax(0,1fr))]">
                        <p class="col-span-2 text-sm font-extrabold tracking-wide @4xl/directory:col-span-1 @4xl/directory:pt-0.5 {{ $receivedText }}">{{ $line['currency'] }}</p>
                        @foreach ($figures as $figure)
                            <div class="logframe-reveal min-w-0" style="--logframe-order: {{ $loop->index }}" data-figure="{{ $figure['key'] }}">
                                <p class="text-xs font-semibold {{ $mutedText }}">{{ $figure['label'] }}</p>
                                <p
                                    class="mt-1.5 truncate text-3xl leading-none font-extrabold tracking-tight tabular-nums @6xl/directory:text-[2.625rem] {{ $figure['tone'] }}"
                                    x-data="countUp({{ (int) round($figure['value']) }})"
                                    x-text="new Intl.NumberFormat('en-US').format(display)"
                                >{{ $amount($figure['value']) }}</p>
                                <p class="mt-2 text-xs {{ $mutedText }}">{{ $figure['note'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-6 flex h-3 w-full gap-0.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2 @4xl/directory:ms-[calc(3.5rem+1.5rem)] @4xl/directory:w-[calc(100%-5rem)]" role="img" aria-label="{{ __('proposals.money_bar_label', ['currency' => $line['currency'], 'received' => $amount($line['received']), 'pending' => $amount($line['pending']), 'committed' => $amount($line['committed'])]) }}">
                        <span class="money-grow h-full rounded-s-full bg-funding" style="width: {{ round($line['received'] / $barBase * 100, 2) }}%"></span>
                        <span class="money-grow money-pending h-full" style="width: {{ round($line['pending'] / $barBase * 100, 2) }}%"></span>
                    </div>
                </div>
            @empty
                <p class="mt-4 border-t pt-4 text-sm {{ $hairline }} {{ $mutedText }}">{{ __('proposals.no_money_committed_to_these_proposals_yet') }}</p>
            @endforelse
        </section>
        @endif

        {{-- The pipeline rail: five phases on one thread; a stop filters the page. --}}
        <nav aria-label="{{ __('proposals.pipeline_phases') }}" @class(['pt-6', 'border-t '.$hairline => $canAgreements]) data-pipeline>
            <ol class="relative grid grid-cols-5 gap-1">
                <span class="absolute inset-x-[10%] top-[0.4375rem] h-px bg-secondary-soft dark:bg-line-strong" aria-hidden="true"></span>
                @foreach ($this->pipeline as $stop)
                    @php
                        $isSelected = $phase === $stop['phase']->value;
                        $isEmpty = $stop['count'] === 0;
                    @endphp
                    <li class="relative min-w-0" wire:key="phase-{{ $stop['phase']->value }}">
                        <button
                            type="button"
                            wire:click="togglePhase('{{ $stop['phase']->value }}')"
                            aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                            class="group flex w-full flex-col items-center gap-1.5 rounded-lg px-1 pb-2 text-center transition-colors duration-150 hover:bg-board-head focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:hover:bg-white/5"
                            data-phase-stop="{{ $stop['phase']->value }}"
                        >
                            <span @class([
                                'relative z-10 size-3.5 rounded-full border-2 transition-transform duration-200 ease-out group-hover:scale-125',
                                'border-primary bg-primary dark:bg-brand dark:border-brand ring-4 ring-primary/15' => $isSelected,
                                'border-secondary-dark bg-secondary-dark dark:border-ink-muted dark:bg-ink-muted' => ! $isSelected && ! $isEmpty,
                                'border-secondary-light bg-white dark:bg-surface-1' => ! $isSelected && $isEmpty,
                            ]) aria-hidden="true"></span>
                            <span title="{{ $stop['phase']->label() }}" @class(['mt-1 w-full truncate text-[11px] font-bold @xl/directory:text-xs @3xl/directory:text-sm', 'text-primary dark:text-brand-text' => $isSelected, $inkText => ! $isSelected && ! $isEmpty, $mutedText => ! $isSelected && $isEmpty])>{{ $stop['phase']->label() }}</span>
                            <span @class(['text-2xl leading-none font-extrabold tabular-nums @3xl/directory:text-3xl', 'text-secondary-light' => $isEmpty, $inkText => ! $isEmpty])>{{ $stop['count'] }}</span>
                            <span class="flex min-h-4 flex-col items-center gap-0.5 text-[11px] font-medium tabular-nums {{ $mutedText }}">
                                @foreach ($stop['money'] as $currency => $total)
                                    <span>{{ $currency }} {{ $shortAmount($total) }}</span>
                                @endforeach
                                @if ($stop['rejected'] > 0)
                                    <span class="font-semibold text-primary dark:text-brand-text">{{ trans_choice('proposals.count_rejected', $stop['rejected'], ['count' => $stop['rejected']]) }}</span>
                                @endif
                            </span>
                        </button>
                    </li>
                @endforeach
            </ol>
        </nav>

        {{-- Funding flow: donors → proposals → projects. --}}
        @if (count($this->flow['currencies']) > 0 || count($this->flow['unfunded']) > 0)
            <section aria-labelledby="flow-heading" class="border-t pt-6 {{ $hairline }}" x-data="{ currency: @js($this->flow['currencies'][0]['currency'] ?? null) }" data-funding-flow>
                <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
                    <div class="min-w-0">
                        <h2 id="flow-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.funding_flow') }}</h2>
                        <p class="mt-0.5 text-xs {{ $mutedText }}">{{ __('proposals.funding_flow_hint') }}</p>
                    </div>
                    @if (count($this->flow['currencies']) > 1)
                        <div class="inline-flex rounded-lg bg-board-head p-1 dark:bg-white/5" role="group" aria-label="{{ __('proposals.currency') }}">
                            @foreach ($this->flow['currencies'] as $graph)
                                <button type="button" x-on:click="currency = @js($graph['currency'])" :aria-pressed="(currency === @js($graph['currency'])).toString()" class="min-h-8 rounded-md px-3 text-xs font-bold tabular-nums transition-colors {{ $mutedText }} aria-pressed:bg-white aria-pressed:text-secondary-dark aria-pressed:shadow-sm dark:aria-pressed:bg-surface-2 dark:aria-pressed:text-ink" wire:key="flow-tab-{{ $graph['currency'] }}">{{ $graph['currency'] }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>

                @foreach ($this->flow['currencies'] as $graph)
                    @php
                        $rows = max(count($graph['donors']), count($graph['proposals']), (int) ceil(count($graph['projects']) * 0.55));
                        $flowHeight = max(16, $rows * 5.25);
                        $flowKey = 'flow-'.$graph['currency'].'-'.md5(json_encode($graph['graph']).$donor);
                    @endphp
                    <div x-show="currency === @js($graph['currency'])" wire:key="{{ $flowKey }}">
                        {{-- Wide screens: the drawn flow. --}}
                        <div class="relative mt-6 hidden @3xl/directory:block" x-data="fundingFlow(@js($graph['graph']))">
                            <svg class="pointer-events-none absolute inset-0 size-full overflow-visible" aria-hidden="true" wire:ignore>
                                <g class="flow-reveal" x-ref="paths"></g>
                            </svg>

                            <div class="grid grid-cols-[minmax(0,0.75fr)_minmax(3.5rem,0.75fr)_minmax(0,1.35fr)_minmax(3rem,0.6fr)_minmax(0,1.3fr)] gap-y-3">
                                <p class="type-label text-end {{ $mutedText }}">{{ __('common.donors') }}</p>
                                <span></span>
                                <p class="type-label ps-5 {{ $mutedText }}">{{ __('common.proposals') }}</p>
                                <span></span>
                                <p class="type-label ps-5 {{ $mutedText }}">{{ __('common.projects') }}</p>

                                {{-- Donors --}}
                                <ul class="flex flex-col gap-2.5" style="height: {{ $flowHeight }}rem" role="list">
                                    @foreach ($graph['donors'] as $node)
                                        @php
                                            $isFiltered = $donor === (string) $node['id'];
                                        @endphp
                                        <li class="flex min-h-15" style="flex: {{ max(1, round($node['total'] / max($graph['total'], 1) * 100, 2)) }} 1 0%" wire:key="flow-donor-{{ $graph['currency'] }}-{{ $node['id'] }}">
                                            <button
                                                type="button"
                                                wire:click="toggleDonor({{ $node['id'] }})"
                                                aria-pressed="{{ $isFiltered ? 'true' : 'false' }}"
                                                x-on:mouseenter="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:mouseleave="$store.proposalFlow.focus = null"
                                                x-on:focus="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:blur="$store.proposalFlow.focus = null"
                                                :class="$store.proposalFlow.isDimmed('{{ $node['key'] }}') && 'opacity-30'"
                                                class="group flex w-full cursor-pointer items-stretch justify-end gap-3 rounded-sm text-end transition-opacity duration-200 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                                                title="{{ __('proposals.filter_by_donor', ['name' => $node['name']]) }}"
                                                data-flow-node="{{ $node['key'] }}"
                                            >
                                                <span class="flex min-w-0 flex-col justify-center">
                                                    <span @class(['truncate text-sm font-bold underline-offset-4 group-hover:underline', 'text-primary dark:text-brand-text' => $isFiltered, $inkText => ! $isFiltered])>{{ $node['name'] }}</span>
                                                    <span class="truncate text-xs tabular-nums {{ $mutedText }}">{{ $amount($node['total']) }} · {{ trans_choice('common.count_agreement', $node['agreements'], ['count' => $node['agreements']]) }}</span>
                                                </span>
                                                <span data-flow-out="{{ $node['key'] }}" @class(['w-2 shrink-0 rounded-full', 'bg-primary dark:bg-ink' => $isFiltered, 'bg-funding' => ! $isFiltered])></span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>

                                <span></span>

                                {{-- Proposals --}}
                                <ul class="flex flex-col gap-2.5" style="height: {{ $flowHeight }}rem" role="list">
                                    @foreach ($graph['proposals'] as $node)
                                        <li class="flex min-h-15" style="flex: {{ max(1, round($node['total'] / max($graph['total'], 1) * 100, 2)) }} 1 0%" wire:key="flow-proposal-{{ $graph['currency'] }}-{{ $node['id'] }}">
                                            <a
                                                href="{{ $proposalHref($node['id']) }}"
                                                x-on:mouseenter="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:mouseleave="$store.proposalFlow.focus = null"
                                                x-on:focus="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:blur="$store.proposalFlow.focus = null"
                                                :class="$store.proposalFlow.isDimmed('{{ $node['key'] }}') && 'opacity-30'"
                                                class="group flex w-full items-stretch gap-3 rounded-sm transition-opacity duration-200 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                                                data-flow-node="{{ $node['key'] }}"
                                            >
                                                <span data-flow-in="{{ $node['key'] }}" class="w-2 shrink-0 rounded-full bg-secondary-dark dark:bg-ink-muted"></span>
                                                <span class="flex min-w-0 flex-1 flex-col justify-center">
                                                    <span class="line-clamp-2 text-sm leading-snug font-bold underline-offset-4 group-hover:underline {{ $inkText }}">{{ $node['name'] }}</span>
                                                    <span class="flex min-w-0 items-center gap-1.5 text-xs tabular-nums {{ $mutedText }}">
                                                        <span class="truncate">{{ $amount($node['total']) }} {{ $graph['currency'] }}</span>
                                                        <span aria-hidden="true">·</span>
                                                        <span class="truncate">{{ $node['status']->label() }}</span>
                                                    </span>
                                                </span>
                                                <span data-flow-out="{{ $node['key'] }}" class="my-auto size-2 shrink-0 rounded-full bg-secondary-light"></span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>

                                <span></span>

                                {{-- Projects --}}
                                <ul class="flex flex-col justify-around gap-1" style="height: {{ $flowHeight }}rem" role="list">
                                    @forelse ($graph['projects'] as $node)
                                        <li wire:key="flow-project-{{ $graph['currency'] }}-{{ $node['id'] }}">
                                            <a
                                                href="{{ route('projects.show', tap(new \App\Models\Project, fn ($project) => $project->id = $node['id'])) }}"
                                                wire:navigate
                                                x-on:mouseenter="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:mouseleave="$store.proposalFlow.focus = null"
                                                x-on:focus="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:blur="$store.proposalFlow.focus = null"
                                                :class="$store.proposalFlow.isDimmed('{{ $node['key'] }}') && 'opacity-30'"
                                                class="group flex min-h-8 items-center gap-2.5 rounded-sm transition-opacity duration-200 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                                                data-flow-node="{{ $node['key'] }}"
                                            >
                                                <span data-flow-in="{{ $node['key'] }}" class="size-2.5 shrink-0 rounded-full border-2 border-secondary-dark bg-white dark:border-ink-muted dark:bg-surface-1"></span>
                                                <span class="line-clamp-2 text-sm leading-snug font-semibold underline-offset-4 group-hover:underline {{ $inkText }}">{{ $node['name'] }}</span>
                                            </a>
                                        </li>
                                    @empty
                                        <li class="ps-5 text-xs {{ $mutedText }}">{{ __('proposals.no_projects_linked') }}</li>
                                    @endforelse
                                </ul>
                            </div>
                        </div>

                        {{-- Narrow screens: the same chain as a stacked thread. --}}
                        <ul class="mt-4 flex flex-col gap-5 @3xl/directory:hidden" role="list">
                            @foreach ($graph['donors'] as $node)
                                <li wire:key="flow-stack-{{ $graph['currency'] }}-{{ $node['id'] }}">
                                    <button type="button" wire:click="toggleDonor({{ $node['id'] }})" aria-pressed="{{ $donor === (string) $node['id'] ? 'true' : 'false' }}" class="flex min-h-11 w-full items-center gap-2.5 text-start">
                                        <span class="size-3 shrink-0 rounded-full bg-funding" aria-hidden="true"></span>
                                        <span class="min-w-0 flex-1 truncate text-sm font-bold {{ $inkText }}">{{ $node['name'] }}</span>
                                        <span class="shrink-0 text-xs font-semibold tabular-nums {{ $mutedText }}">{{ $amount($node['total']) }} {{ $graph['currency'] }}</span>
                                    </button>
                                    <ul class="ms-1.5 flex flex-col border-s-2 border-funding/30 ps-4" role="list">
                                        @foreach ($graph['graph']['money'] as $ribbon)
                                            @if ($ribbon['from'] === $node['key'])
                                                @php
                                                    $target = collect($graph['proposals'])->firstWhere('key', $ribbon['to']);
                                                @endphp
                                                <li class="flex items-center gap-2 text-sm" wire:key="flow-stack-{{ $graph['currency'] }}-{{ $ribbon['from'] }}-{{ $ribbon['to'] }}">
                                                    <a href="{{ $proposalHref($target['id']) }}" class="min-w-0 flex-1 truncate py-2.5 font-semibold hover:underline {{ $inkText }}">{{ $target['name'] }}</a>
                                                    <span class="shrink-0 text-xs tabular-nums {{ $mutedText }}">{{ $amount($ribbon['amount']) }}</span>
                                                </li>
                                            @endif
                                        @endforeach
                                    </ul>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach

                @if (count($this->flow['unfunded']) > 0)
                    <p class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-dashed pt-4 text-xs {{ $hairline }} {{ $mutedText }}" data-unfunded>
                        <span class="font-semibold">{{ __('proposals.not_yet_funded') }}</span>
                        @foreach ($this->flow['unfunded'] as $unfunded)
                            <a href="{{ $proposalHref($unfunded['id']) }}" class="inline-flex min-h-8 items-center gap-1.5 font-semibold hover:underline {{ $inkText }}" wire:key="unfunded-{{ $unfunded['id'] }}">
                                <span class="size-2 rounded-full border border-dashed border-secondary-medium dark:border-ink-faint" aria-hidden="true"></span>
                                {{ $unfunded['name'] }}
                            </a>
                        @endforeach
                    </p>
                @endif
            </section>
        @endif

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
                            // A rejection answers a submission, so the rail stops at Submitted and marks the next stop.
                            $reachedStep = (int) array_search($isRejected ? \App\Enums\ProposalStatus::Submitted : $proposal->status, $lifecycle, true);
                            $nextStatus = $proposal->status->next();
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
                                        <x-ui.button size="sm" icon:trailing="arrow-right" class="min-h-10 touch-manipulation rtl:[&_svg]:rotate-180" wire:click="openStageModal({{ $proposal->id }})" data-test="advance-proposal-{{ $proposal->id }}">
                                            {{ __('proposals.move_to_status', ['status' => $nextStatus->label()]) }}
                                        </x-ui.button>
                                    @else
                                        <x-ui.button size="sm" tone="ghost" icon="arrows-right-left" class="min-h-10 touch-manipulation" wire:click="openStageModal({{ $proposal->id }})" data-test="advance-proposal-{{ $proposal->id }}">
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
                                            <span class="inline-flex size-10 cursor-not-allowed items-center justify-center rounded-lg text-secondary-light focus-visible:outline-2 focus-visible:outline-primary" aria-disabled="true" tabindex="0" aria-label="{{ __('proposals.funded_proposals_cannot_be_deleted_remove_the_funding_agreem') }}">
                                                <flux:icon.lock-closed class="size-4" />
                                            </span>
                                        </flux:tooltip>
                                    @endif
                                    @endcan
                                </div>
                            </div>

                            {{-- Lifecycle stepper --}}
                            <ol class="mt-5 grid grid-cols-7" aria-label="{{ __('proposals.lifecycle') }}">
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
                                            'bg-primary dark:bg-brand ring-4 ring-primary/20' => $isCurrent || $isRejectionPoint,
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
