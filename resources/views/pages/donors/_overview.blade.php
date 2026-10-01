{{--
    The Overview view of the donors page: the pulse strip, who funds us, the
    transfers ahead, the donor mix and the donor list. Included by
    ⚡index.blade.php, which defines $amount, $shortAmount, $initials,
    $canAgreements, $canTransfers, $mutedText, $inkText and $hairline. The
    Alpine state it reads (focus, lit) lives on the page's root <section>.
--}}
@php
    $pulse = $this->pulse;
    $pageDonorIds = $this->donors->getCollection()->pluck('id')->all();
@endphp

        {{-- Overview: one framed band — the money by funder beside what is due next. --}}
        <div class="overflow-hidden rounded-2xl border {{ $hairline }} bg-white dark:bg-surface-1" data-donor-overview>
            {{-- Pulse strip --}}
            <dl @class(['grid grid-cols-2 border-b bg-board-head/70 dark:bg-white/[0.03]', $hairline, '@3xl/directory:grid-cols-4' => $canTransfers, '@3xl/directory:grid-cols-3' => ! $canTransfers]) data-donor-pulse>
                @foreach ([
                    ['label' => __('donors.funding_now'), 'value' => $pulse['funding'], 'suffix' => __('donors.of_count_donors', ['count' => $pulse['donors']]), 'tone' => $inkText],
                    ['label' => __('common.proposals'), 'value' => $pulse['proposals'], 'suffix' => __('donors.funded'), 'tone' => $inkText],
                    ['label' => __('common.projects'), 'value' => $pulse['projects'], 'suffix' => __('donors.reached'), 'tone' => 'text-funding'],
                    ...($canTransfers ? [['label' => __('donors.overdue'), 'value' => $pulse['overdue'], 'suffix' => trans_choice('donors.transfers_word', $pulse['overdue']), 'tone' => $pulse['overdue'] > 0 ? 'text-status-critical' : $mutedText]] : []),
                ] as $figure)
                    <div @class(['px-5 py-4', 'border-s '.$hairline => $loop->index % 2 === 1, '@3xl/directory:border-s' => $loop->index === 2, 'border-t @3xl/directory:border-t-0 '.$hairline => $loop->index >= 2])>
                        <dt class="type-label {{ $mutedText }}">{{ $figure['label'] }}</dt>
                        <dd class="mt-1 flex items-baseline gap-2">
                            <span class="text-3xl leading-none font-extrabold tracking-tight tabular-nums {{ $figure['tone'] }}" x-data="countUp({{ $figure['value'] }})" x-text="display">{{ $figure['value'] }}</span>
                            <span class="text-xs font-medium {{ $mutedText }}">{{ $figure['suffix'] }}</span>
                        </dd>
                    </div>
                @endforeach
            </dl>

            <div @class(['grid', '@5xl/directory:grid-cols-[minmax(0,1fr)_21rem]' => $canAgreements])>
                {{-- Who funds us: per currency, a ranked bar per donor. --}}
                @if ($canAgreements)
                <section aria-labelledby="wallet-heading" class="min-w-0 p-5 @3xl/directory:p-6" data-wallet>
                    <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                        <h2 id="wallet-heading" class="text-base font-bold {{ $inkText }}">{{ __('donors.who_funds_us') }}</h2>
                        <p class="flex items-center gap-4 text-xs {{ $mutedText }}">
                            <span class="flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-funding" aria-hidden="true"></span>{{ __('donors.received') }}</span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-funding/20" aria-hidden="true"></span>{{ __('donors.still_to_come') }}</span>
                        </p>
                    </div>

                    @forelse ($this->wallet as $line)
                        @php
                            $largest = max(collect($line['segments'])->max('committed'), 1);
                            $isRoomy = count($line['segments']) <= 4;
                            $currencyShare = App\Models\DonorProposal::percentLabel($line['received'], $line['committed']);
                        @endphp
                        <div @class(['mt-5', 'border-t pt-5 '.$hairline => ! $loop->first]) wire:key="wallet-{{ $line['currency'] }}" data-wallet-currency="{{ $line['currency'] }}">
                            <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-1">
                                <p class="flex items-baseline gap-2.5">
                                    <span class="rounded-md bg-funding-soft px-1.5 py-0.5 text-xs font-extrabold tracking-wide text-funding">{{ $line['currency'] }}</span>
                                    <span class="text-3xl leading-none font-extrabold tracking-tight tabular-nums {{ $inkText }}" x-data="countUp({{ (int) round($line['committed']) }})" x-text="new Intl.NumberFormat('en-US').format(display)">{{ $amount($line['committed']) }}</span>
                                    <span class="text-xs font-semibold {{ $mutedText }}">{{ __('donors.committed_lower') }}</span>
                                </p>
                                <p class="text-sm tabular-nums {{ $mutedText }}">
                                    <span class="font-extrabold text-funding">{{ $amount($line['received']) }}</span> {{ __('donors.received_lower') }} · <span class="font-bold {{ $inkText }}">{{ $currencyShare }}%</span>
                                </p>
                            </div>

                            <ol class="-mx-2 mt-4 flex flex-col {{ $isRoomy ? 'gap-1.5' : 'gap-0.5' }}" aria-label="{{ __('donors.share_of_currency', ['currency' => $line['currency']]) }}">
                                @foreach ($line['segments'] as $segment)
                                    @php
                                        $receivedShare = $segment['committed'] > 0 ? min(100, round($segment['received'] / $segment['committed'] * 100, 2)) : 0;
                                        $isRest = $segment['donorId'] === null;
                                        $rowHref = $isRest ? null : (in_array($segment['donorId'], $pageDonorIds, true) ? '#donor-'.$segment['donorId'] : route('donors.show', tap(new \App\Models\Donor, fn ($donor) => $donor->id = $segment['donorId'])));
                                    @endphp
                                    <li wire:key="wallet-{{ $line['currency'] }}-{{ $segment['donorId'] ?? 'rest' }}">
                                        <a
                                            @if ($rowHref !== null) href="{{ $rowHref }}" @if (! str_starts_with($rowHref, '#')) wire:navigate @endif @endif
                                            x-on:mouseenter="focus = {{ $segment['donorId'] ?? 'null' }}"
                                            x-on:mouseleave="focus = null"
                                            :class="lit({{ $segment['donorId'] ?? 'null' }}) ? '' : 'opacity-40'"
                                            class="grid grid-cols-[minmax(0,7rem)_minmax(0,1fr)_auto] items-center gap-3 rounded-lg px-2 {{ $isRoomy ? 'py-2.5' : 'py-1.5' }} transition-[background-color,opacity] duration-200 hover:bg-board-head focus-visible:outline-2 focus-visible:outline-primary @3xl/directory:grid-cols-[minmax(0,10rem)_minmax(0,1fr)_8rem] dark:hover:bg-white/5"
                                            aria-label="{{ __('donors.segment_label', ['name' => $segment['name'], 'currency' => $line['currency'], 'committed' => $amount($segment['committed']), 'received' => $amount($segment['received']), 'percent' => (int) round($segment['share'])]) }}"
                                            data-wallet-segment="{{ $segment['donorId'] ?? 'rest' }}"
                                        >
                                            <span class="truncate text-sm font-semibold {{ $isRest ? $mutedText : $inkText }}">{{ $segment['name'] }}</span>
                                            <span class="relative w-full {{ $isRoomy ? 'h-5' : 'h-3' }}" aria-hidden="true">
                                                <span class="wallet-grow absolute inset-y-0 start-0 overflow-hidden rounded-full {{ $isRest ? 'bg-secondary-soft dark:bg-line-strong' : 'bg-funding/20' }}" style="width: {{ max(1.5, round($segment['committed'] / $largest * 100, 2)) }}%; --wallet-order: {{ $loop->index }}">
                                                    <span class="absolute inset-y-0 start-0 rounded-full {{ $isRest ? 'bg-secondary-light' : 'bg-funding' }}" style="width: {{ $receivedShare }}%"></span>
                                                </span>
                                            </span>
                                            <span class="text-end text-sm tabular-nums">
                                                <span class="font-bold {{ $inkText }}">{{ $shortAmount($segment['committed']) }}</span>
                                                <span class="ms-1 inline-block w-9 text-xs {{ $mutedText }}">{{ (int) round($segment['share']) }}%</span>
                                            </span>
                                        </a>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @empty
                        <p class="mt-4 text-sm {{ $mutedText }}">{{ __('donors.no_money_committed_yet') }}</p>
                    @endforelse
                </section>
                @endif

                {{-- Right rail: transfers ahead and the donor mix. --}}
                <aside @class(['flex min-w-0 flex-col', 'border-t @5xl/directory:border-t-0 @5xl/directory:border-s '.$hairline => $canAgreements])>
                    @if ($canTransfers)
                    <section aria-labelledby="transfers-heading" class="p-5 @3xl/directory:p-6" data-upcoming-transfers>
                        <h2 id="transfers-heading" class="text-base font-bold {{ $inkText }}">{{ __('donors.transfers_ahead') }}</h2>
                        @if ($this->upcomingTransfers->isEmpty())
                            <p class="mt-3 text-sm {{ $mutedText }}">{{ __('donors.no_transfer_scheduled_ahead') }}</p>
                        @else
                            <ol class="relative mt-4 flex flex-col gap-4" role="list">
                                <span class="absolute start-[0.28rem] top-2 bottom-2 w-px bg-secondary-soft dark:bg-line-strong" aria-hidden="true"></span>
                                @foreach ($this->upcomingTransfers as $transfer)
                                    <li class="relative ps-6" wire:key="upcoming-{{ $transfer['agreementId'] }}-{{ $loop->index }}">
                                        <span @class(['absolute start-0 top-1 size-2.5 rounded-full ring-4 ring-white dark:ring-surface-1', 'bg-primary dark:bg-status-critical' => $transfer['overdue'], 'bg-funding' => ! $transfer['overdue']]) aria-hidden="true"></span>
                                        <div class="flex items-baseline justify-between gap-3">
                                            <p @class(['text-xs font-bold', 'text-status-critical' => $transfer['overdue'], $mutedText => ! $transfer['overdue']])>
                                                {{ $transfer['date']->translatedFormat(__('formats.date')) }}
                                                @if ($transfer['overdue'])
                                                    · {{ __('donors.overdue') }}
                                                @endif
                                            </p>
                                            <p class="shrink-0 text-sm font-extrabold tabular-nums {{ $inkText }}">{{ $shortAmount($transfer['amount']) }} <span class="text-[11px] font-semibold {{ $mutedText }}">{{ $transfer['currency'] }}</span></p>
                                        </div>
                                        <p class="mt-0.5 truncate text-sm">
                                            <a href="{{ route('donors.show', tap(new \App\Models\Donor, fn ($donor) => $donor->id = $transfer['donorId'])) }}" wire:navigate class="font-semibold underline-offset-4 hover:underline {{ $inkText }}">{{ $transfer['donor'] }}</a>
                                            @if ($canAgreements)
                                                <a href="{{ route('funding-agreements.show', tap(new \App\Models\DonorProposal, fn ($agreement) => $agreement->id = $transfer['agreementId'])) }}" wire:navigate class="text-xs underline-offset-4 hover:underline {{ $mutedText }}">· {{ $transfer['proposal'] }}</a>
                                            @else
                                                <span class="text-xs {{ $mutedText }}">· {{ $transfer['proposal'] }}</span>
                                            @endif
                                        </p>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </section>
                    @endif

                    <nav aria-label="{{ __('donors.donor_types') }}" class="mt-auto border-t p-5 {{ $hairline }} @3xl/directory:px-6" data-type-rail>
                        <h2 class="type-label {{ $mutedText }}">{{ __('donors.donor_mix') }}</h2>
                        @php
                            $typeMax = max(collect($this->typeRail)->max('count'), 1);
                            $shownTypes = collect($this->typeRail)->filter(fn (array $stop): bool => $stop['count'] > 0 || $stop['value'] === $type);
                            $unusedTypes = collect($this->typeRail)->diffKeys($shownTypes)->pluck('label');
                        @endphp
                        <ul class="-mx-2 mt-2 flex flex-col" role="list">
                            @foreach ($shownTypes as $stop)
                                @php
                                    $isSelected = $type === $stop['value'];
                                @endphp
                                <li wire:key="type-{{ $stop['value'] }}">
                                    <button
                                        type="button"
                                        wire:click="toggleType('{{ $stop['value'] }}')"
                                        aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                                        @class([
                                            'grid w-full grid-cols-[7rem_minmax(0,1fr)_1.5rem] items-center gap-3 rounded-md px-2 py-1.5 text-start text-sm transition-colors hover:bg-board-head focus-visible:outline-2 focus-visible:outline-primary dark:hover:bg-white/5',
                                            'bg-primary/8 dark:bg-brand/10' => $isSelected,
                                            'opacity-50' => $stop['count'] === 0 && ! $isSelected,
                                        ])
                                        data-type-stop="{{ $stop['value'] }}"
                                    >
                                        <span @class(['truncate font-semibold', 'text-status-critical' => $isSelected, $inkText => ! $isSelected])>{{ $stop['label'] }}</span>
                                        <span class="h-1.5 rounded-full bg-secondary-soft dark:bg-surface-2" aria-hidden="true">
                                            <span class="block h-full rounded-full {{ $isSelected ? 'bg-primary dark:bg-ink' : 'bg-secondary-dark dark:bg-ink-muted' }}" style="width: {{ round($stop['count'] / $typeMax * 100) }}%"></span>
                                        </span>
                                        <span class="text-end font-extrabold tabular-nums {{ $inkText }}">{{ $stop['count'] }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        @if ($unusedTypes->isNotEmpty())
                            <p class="mt-2 text-xs {{ $mutedText }}">{{ __('donors.no_donors_of_type', ['types' => $unusedTypes->implode(', ')]) }}</p>
                        @endif
                    </nav>
                </aside>
            </div>
        </div>

        {{-- The ledger: aligned columns, one donor per row. --}}
        <section aria-labelledby="ledger-heading" class="pt-4">
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                <h2 id="ledger-heading" class="text-lg font-bold {{ $inkText }}">{{ __('donors.every_donor_and_what_they_fund') }}</h2>
                <p class="flex flex-wrap items-baseline gap-x-3 text-xs {{ $mutedText }}">
                    @if ($pulse['followUps'] > 0 && $sort !== 'follow_up')
                        <button type="button" wire:click="$set('sort', 'follow_up')" class="font-semibold text-status-critical underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-primary" data-follow-ups-due>
                            {{ trans_choice('donors.count_follow_ups_due', $pulse['followUps'], ['count' => $pulse['followUps']]) }}
                        </button>
                    @endif
                    <span>{{ trans_choice('donors.count_matching_donor', $this->donors->total(), ['count' => $this->donors->total()]) }}</span>
                </p>
            </div>

            @if ($this->donors->isEmpty())
                <x-ui.empty-state icon="building-library" :heading="__('donors.no_matching_donors')" :body="__('common.change_the_search_or_filters_and_try_again')" />
            @else
                @php
                    $columns = match (true) {
                        $canAgreements && $canTransfers => '@4xl/directory:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1.4fr)_8.5rem_7.25rem]',
                        $canAgreements => '@4xl/directory:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1.4fr)_7.25rem]',
                        $canTransfers => '@4xl/directory:grid-cols-[minmax(0,1.3fr)_minmax(0,1.4fr)_8.5rem_7.25rem]',
                        default => '@4xl/directory:grid-cols-[minmax(0,1.3fr)_minmax(0,1.4fr)_7.25rem]',
                    };
                @endphp
                <div class="mt-3 overflow-hidden rounded-2xl border {{ $hairline }}">
                    <div class="hidden gap-x-8 border-b bg-board-head/70 px-5 py-2.5 {{ $hairline }} {{ $columns }} @4xl/directory:grid dark:bg-white/[0.03]" aria-hidden="true">
                        <span class="type-label {{ $mutedText }}">{{ __('donors.donor') }}</span>
                        @if ($canAgreements)
                            <span class="type-label {{ $mutedText }}">{{ __('donors.committed') }}</span>
                        @endif
                        <span class="type-label {{ $mutedText }}">{{ __('donors.funds') }}</span>
                        @if ($canTransfers)
                            <span class="type-label {{ $mutedText }}">{{ __('donors.next_transfer') }}</span>
                        @endif
                        <span></span>
                    </div>

                    @foreach ($this->donors as $donor)
                        @php
                            $money = $this->donorMoney($donor);
                            $isProspect = $donor->donor_proposals_count === 0;
                            $shownAgreements = $donor->donorProposals->take(3);
                            $hiddenAgreements = $donor->donorProposals->count() - $shownAgreements->count();
                        @endphp
                        <article
                            id="donor-{{ $donor->id }}"
                            wire:key="donor-{{ $donor->id }}"
                            x-on:mouseenter="focus = {{ $donor->id }}"
                            x-on:mouseleave="focus = null"
                            :class="focus === {{ $donor->id }} ? 'bg-board-head/70 dark:bg-white/[0.04]' : (lit({{ $donor->id }}) ? '' : 'opacity-45')"
                            class="logframe-reveal grid scroll-mt-24 items-start gap-x-8 gap-y-4 border-b px-5 py-5 transition-[background-color,opacity] duration-200 last:border-b-0 target:bg-board-head {{ $hairline }} {{ $columns }}"
                            style="--logframe-order: {{ $loop->index }}"
                            data-donor-row
                        >
                            {{-- Identity and contact --}}
                            <div class="flex min-w-0 gap-3">
                                <span @class([
                                    'flex size-10 shrink-0 items-center justify-center rounded-full text-xs font-extrabold',
                                    'bg-secondary-dark text-white dark:bg-ink dark:text-surface-0' => ! $isProspect,
                                    'border border-dashed border-secondary-light text-secondary-medium dark:text-ink-muted' => $isProspect,
                                ]) aria-hidden="true">{{ $initials($donor->name) }}</span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="text-base leading-tight font-bold {{ $inkText }}">{{ $donor->name }}</span>
                                        <x-ui.enum-badge :case="$donor->type" />
                                    </div>
                                    <p class="mt-0.5 truncate text-xs {{ $mutedText }}">
                                        {{ collect([$donor->country, $donor->contact_person])->filter()->implode(' · ') ?: __('donors.no_contact_on_file') }}
                                    </p>
                                    @if (filled($donor->email) || filled($donor->phone))
                                        <p class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                                            @if (filled($donor->email))
                                                <a href="mailto:{{ $donor->email }}" class="inline-flex min-w-0 max-w-full items-center gap-1 font-medium underline-offset-4 hover:underline {{ $inkText }}" title="{{ $donor->email }}">
                                                    <flux:icon.envelope variant="micro" class="size-3.5 shrink-0 text-secondary-light" /><span class="truncate">{{ $donor->email }}</span>
                                                </a>
                                            @endif
                                            @if (filled($donor->phone))
                                                <a href="tel:{{ preg_replace('/[^\d+]/', '', $donor->phone) }}" dir="ltr" class="inline-flex items-center gap-1 font-medium tabular-nums underline-offset-4 hover:underline {{ $inkText }}">
                                                    <flux:icon.phone variant="micro" class="size-3.5 text-secondary-light" />{{ $donor->phone }}
                                                </a>
                                            @endif
                                        </p>
                                    @endif
                                    @if (filled($donor->next_step))
                                        <p @class(['mt-1.5 flex min-w-0 items-start gap-1 text-xs', 'text-status-critical' => $donor->isFollowUpOverdue(), $inkText => ! $donor->isFollowUpOverdue()]) data-follow-up>
                                            <flux:icon.arrow-uturn-right variant="micro" class="mt-px size-3.5 shrink-0 rtl:-scale-x-100 {{ $donor->isFollowUpOverdue() ? '' : 'text-secondary-light' }}" aria-hidden="true" />
                                            <span class="min-w-0">
                                                <span class="font-semibold">{{ $donor->next_step }}</span>
                                                @if ($donor->next_step_due_on !== null)
                                                    · <span class="tabular-nums">{{ $donor->isFollowUpOverdue() ? __('donors.follow_up_was_due', ['date' => $donor->next_step_due_on->translatedFormat(__('formats.date'))]) : __('donors.follow_up_due', ['date' => $donor->next_step_due_on->translatedFormat(__('formats.date'))]) }}</span>
                                                @endif
                                            </span>
                                        </p>
                                    @endif
                                    @if ($donor->last_contacted_on !== null)
                                        <p class="mt-1 text-[11px] {{ $mutedText }}">{{ __('donors.last_contacted', ['date' => $donor->last_contacted_on->translatedFormat(__('formats.date'))]) }}</p>
                                    @endif
                                </div>
                            </div>

                            {{-- Money --}}
                            @if ($canAgreements)
                            <div class="min-w-0" data-connection="money">
                                <p class="type-label mb-1 {{ $mutedText }} @4xl/directory:hidden">{{ __('donors.committed') }}</p>
                                @forelse ($money['lines'] as $currency => $line)
                                    <div class="[&+&]:mt-3" wire:key="donor-{{ $donor->id }}-money-{{ $currency }}">
                                        <p class="flex items-baseline justify-between gap-3">
                                            <span class="text-lg leading-none font-extrabold tracking-tight tabular-nums {{ $inkText }}">{{ $amount($line['committed']) }} <span class="text-[11px] font-semibold tracking-normal {{ $mutedText }}">{{ $currency }}</span></span>
                                            <span class="text-xs font-bold tabular-nums text-funding">{{ App\Models\DonorProposal::percentLabel($line['received'], $line['committed']) }}%</span>
                                        </p>
                                        <div class="mt-2 flex h-1.5 w-full gap-0.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" role="img" aria-label="{{ __('donors.money_bar_label', ['currency' => $currency, 'received' => $amount($line['received']), 'pending' => $amount($line['pending']), 'committed' => $amount($line['committed'])]) }}">
                                            <span class="money-grow h-full rounded-s-full bg-funding" style="width: {{ $line['committed'] > 0 ? min(100, round($line['received'] / $line['committed'] * 100, 2)) : 0 }}%"></span>
                                            <span class="money-grow money-pending h-full" style="width: {{ $line['committed'] > 0 ? min(100, round($line['pending'] / $line['committed'] * 100, 2)) : 0 }}%"></span>
                                        </div>
                                        <p class="mt-1 text-[11px] tabular-nums {{ $mutedText }}">
                                            <span class="font-bold text-funding">{{ $shortAmount($line['received']) }}</span> {{ __('donors.received_lower') }}
                                            @if ($line['pending'] > 0)
                                                · <span class="font-bold text-status-warn">{{ $shortAmount($line['pending']) }}</span> {{ __('donors.pending_lower') }}
                                            @endif
                                        </p>
                                    </div>
                                @empty
                                    <p class="text-sm {{ $mutedText }}">{{ __('donors.prospect_no_agreement_yet') }}</p>
                                @endforelse
                            </div>
                            @endif

                            {{-- Proposals on a thread --}}
                            <div class="min-w-0" data-connection="proposals">
                                <p class="type-label mb-1 {{ $mutedText }} @4xl/directory:hidden">{{ __('donors.funds') }}</p>
                                @if ($shownAgreements->isEmpty())
                                    <p class="text-sm {{ $mutedText }}">{{ __('donors.nothing_funded_yet') }}</p>
                                @else
                                    <ul class="relative flex flex-col gap-2" role="list">
                                        <span class="absolute start-[0.1875rem] top-2 bottom-2 w-px bg-secondary-soft dark:bg-line-strong" aria-hidden="true"></span>
                                        @foreach ($shownAgreements as $agreement)
                                            <li class="relative flex items-start gap-3 ps-5" wire:key="donor-{{ $donor->id }}-agreement-{{ $agreement->id }}">
                                                <span class="absolute start-0 top-1.5 size-2 rounded-full border-2 border-secondary-dark bg-white dark:border-ink-muted dark:bg-surface-1" aria-hidden="true"></span>
                                                <span class="min-w-0 flex-1">
                                                    @can('proposals.view')
                                                        <a href="{{ route('proposals.show', $agreement->proposal) }}" wire:navigate class="block truncate text-sm leading-snug font-semibold underline-offset-4 hover:underline {{ $inkText }}">{{ $agreement->proposal?->name }}</a>
                                                    @else
                                                        <span class="block truncate text-sm leading-snug font-semibold {{ $inkText }}">{{ $agreement->proposal?->name }}</span>
                                                    @endcan
                                                    <span class="block truncate text-[11px] {{ $mutedText }}">
                                                        {{ $agreement->proposal?->status->label() }}
                                                        @if ($agreement->proposal?->projects->isNotEmpty())
                                                            · {{ trans_choice('common.count_project', $agreement->proposal->projects->count(), ['count' => $agreement->proposal->projects->count()]) }}
                                                        @endif
                                                    </span>
                                                </span>
                                                @if ($canAgreements)
                                                    <a href="{{ route('funding-agreements.show', $agreement) }}" wire:navigate class="shrink-0 pt-px text-xs font-bold tabular-nums underline-offset-4 hover:underline {{ $inkText }}" aria-label="{{ __('donors.view_agreement_for', ['name' => $agreement->proposal?->name]) }}">{{ $shortAmount((float) $agreement->budget) }} <span class="font-semibold {{ $mutedText }}">{{ $agreement->currency->value }}</span></a>
                                                @endif
                                            </li>
                                        @endforeach
                                        @if ($hiddenAgreements > 0)
                                            <li class="ps-5">
                                                <a href="{{ route('donors.show', $donor) }}" wire:navigate class="text-xs font-semibold underline-offset-4 hover:underline {{ $mutedText }}">{{ trans_choice('donors.and_count_more', $hiddenAgreements, ['count' => $hiddenAgreements]) }}</a>
                                            </li>
                                        @endif
                                    </ul>
                                @endif
                            </div>

                            {{-- Next transfer --}}
                            @if ($canTransfers)
                            <div class="min-w-0" data-connection="transfers">
                                <p class="type-label mb-1 {{ $mutedText }} @4xl/directory:hidden">{{ __('donors.next_transfer') }}</p>
                                @if ($money['next'] !== null)
                                    <p @class(['text-sm font-bold', 'text-status-critical' => $money['next']['overdue'], $inkText => ! $money['next']['overdue']])>{{ $money['next']['date']->translatedFormat(__('formats.date')) }}</p>
                                    <p class="text-xs tabular-nums {{ $mutedText }}">{{ $money['next']['currency'] }} {{ $shortAmount($money['next']['amount']) }}</p>
                                    @if ($money['overdue'] > 0)
                                        <p class="mt-1 text-[11px] font-semibold text-status-critical">{{ trans_choice('donors.count_transfers_overdue', $money['overdue'], ['count' => $money['overdue']]) }}</p>
                                    @endif
                                @else
                                    <p class="text-sm {{ $mutedText }}">{{ __('donors.none_scheduled') }}</p>
                                @endif
                                @if ($money['lastReceived'] !== null)
                                    <p class="mt-1 text-[11px] {{ $mutedText }}">{{ __('donors.last_received_on', ['date' => $money['lastReceived']->translatedFormat(__('formats.date'))]) }}</p>
                                @endif
                            </div>
                            @endif

                            {{-- Actions --}}
                            <div class="flex items-center gap-0.5 @4xl/directory:justify-end">
                                <x-ui.button tone="ghost" size="sm" icon="eye" icon:variant="outline" class="size-9! touch-manipulation" :href="route('donors.show', $donor)" wire:navigate :aria-label="__('common.view_name', ['name' => $donor->name])" />
                                @can('donors.update')
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" icon:variant="outline" class="size-9! touch-manipulation" wire:click="openEditModal({{ $donor->id }})" :aria-label="__('common.edit_name', ['name' => $donor->name])" />
                                @endcan
                                @can('donors.delete')
                                @if ($isProspect)
                                    <x-ui.button tone="ghost" size="sm" icon="trash" icon:variant="outline" class="size-9! touch-manipulation" wire:click="confirmDelete({{ $donor->id }})" :aria-label="__('common.delete_name', ['name' => $donor->name])" />
                                @else
                                    <flux:tooltip :content="__('donors.donors_holding_an_agreement_cannot_be_deleted')">
                                        <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                            <flux:icon.lock-closed class="size-5" />
                                        </span>
                                    </flux:tooltip>
                                @endif
                                @endcan
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif

            <div class="mt-3">
                <flux:pagination :paginator="$this->donors" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#donor-directory-heading" />
            </div>
        </section>
