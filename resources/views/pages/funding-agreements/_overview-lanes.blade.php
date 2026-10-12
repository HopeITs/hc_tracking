{{--
    The lanes of the cash-flow calendar on the funding agreements page: one
    row per agreement on the visible page, with its identity and money pinned
    at the start and its tranches placed on the month grid. Included by
    _overview-calendar.blade.php inside the calendar's Alpine scope, and reads
    the variables that partial defines ($calendar, $beadClass, $tagClass,
    $trancheStateLabels, $amount, $shortAmount and the colour classes).
--}}
                        {{-- Lanes --}}
                        <ol class="relative" aria-label="{{ __('funding_agreements.cash_flow_calendar') }}">
                            @foreach ($this->agreements as $agreement)
                                @php
                                    $money = $this->agreementMoney($agreement);
                                    $budget = (float) $agreement->budget;
                                    $agreementName = $agreement->donor->name.' · '.$agreement->proposal->name;
                                    $projects = $agreement->proposal->projects;
                                    $placed = $agreement->transfers->filter(fn ($transfer) => $transfer->scheduled_date !== null)->values();
                                    $offsets = $placed->map(fn ($transfer) => $this->monthOffset($calendar['start'], $transfer->scheduled_date));
                                    $lastReceived = $placed->filter(fn ($transfer) => $transfer->ar_status === \App\Enums\ArStatus::Received)->keys()->last();
                                    $hasOverdue = $money['overdueSince'] !== null;
                                @endphp
                                <li
                                    wire:key="agreement-{{ $agreement->id }}"
                                    class="cal-lane group/lane relative border-b last:border-b-0 {{ $hairline }}"
                                    :class="open === {{ $agreement->id }} && 'is-open'"
                                    data-agreement-row="{{ $agreement->id }}"
                                    style="--lane-order: {{ min($loop->index, 10) }}"
                                >
                                    <div class="grid @3xl/cal:grid-cols-[var(--lane-w)_var(--track-w)]">
                                        {{-- Identity and money, pinned while the months scroll. --}}
                                        <div class="relative z-10 flex gap-3.5 bg-white py-4 transition-colors duration-150 group-hover/lane:bg-board-head group-[.is-open]/lane:bg-board-head @3xl/cal:sticky @3xl/cal:start-0 @3xl/cal:border-e @3xl/cal:px-4 dark:bg-surface-1 {{ $hairline }}">
                                            <x-ui.progress-ring :value="$money['share']" size="md" tone="funding" :label="__('funding_agreements.percent_received', ['percent' => $money['percentLabel']])" class="mt-0.5" />
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-start justify-between gap-2">
                                                    <p class="min-w-0 text-sm leading-snug font-bold {{ $inkText }}">
                                                        <a href="{{ route('donors.show', $agreement->donor) }}" wire:navigate class="underline-offset-4 hover:underline">{{ $agreement->donor->name }}</a>
                                                        <span class="{{ $mutedText }}" aria-hidden="true">·</span>
                                                        <a href="{{ route('proposals.show', $agreement->proposal) }}" wire:navigate class="underline-offset-4 hover:underline">{{ $agreement->proposal->name }}</a>
                                                    </p>
                                                    {{-- No delete: transfers_tracking cascades from this row, so
                                                         deleting an agreement would erase the money received. --}}
                                                    <div class="-me-1.5 -mt-1.5 flex shrink-0 items-center">
                                                        <x-ui.button tone="ghost" size="sm" icon="eye" class="size-9! shrink-0 touch-manipulation" :href="route('funding-agreements.show', $agreement)" wire:navigate :aria-label="__('common.view_name', ['name' => $agreementName])" />
                                                        @can('funding-agreements.update')
                                                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9! shrink-0 touch-manipulation" wire:click="openEditModal({{ $agreement->id }})" :aria-label="__('common.edit_name', ['name' => $agreementName])" />
                                                        @endcan
                                                    </div>
                                                </div>

                                                <p class="mt-1 text-lg leading-none font-extrabold tracking-tight tabular-nums {{ $inkText }}">
                                                    {{ $amount($budget) }} <span class="text-[11px] font-bold tracking-normal {{ $mutedText }}">{{ $agreement->currency->value }}</span>
                                                    <span class="ms-1 text-xs font-bold tracking-normal text-funding">{{ __('funding_agreements.percent_received', ['percent' => $money['percentLabel']]) }}</span>
                                                </p>

                                                @if ($hasOverdue)
                                                    <p class="mt-1.5 flex items-center gap-1.5 text-xs font-bold text-status-critical tabular-nums" data-overdue>
                                                        <span class="size-1.5 rounded-full bg-primary dark:bg-status-critical" aria-hidden="true"></span>
                                                        {{ __('funding_agreements.amount_overdue_since', ['amount' => $shortAmount($money['overdueAmount']), 'date' => $money['overdueSince']->translatedFormat(__('formats.date_short'))]) }}
                                                        · {{ trans_choice('funding_agreements.count_days_late', (int) $money['overdueSince']->diffInDays(\Carbon\CarbonImmutable::today()), ['count' => (int) $money['overdueSince']->diffInDays(\Carbon\CarbonImmutable::today())]) }}
                                                    </p>
                                                @elseif ($money['nextDue'] !== null)
                                                    <p class="mt-1.5 text-xs tabular-nums {{ $mutedText }}">{{ __('funding_agreements.next_due_on', ['date' => $money['nextDue']->translatedFormat(__('formats.date'))]) }}</p>
                                                @elseif ($money['unscheduled'] > 0)
                                                    <p class="mt-1.5 text-xs tabular-nums {{ $mutedText }}">{{ __('funding_agreements.amount_not_scheduled', ['amount' => $shortAmount($money['unscheduled'])]) }}</p>
                                                @endif

                                                @if ($money['failedAmount'] > 0)
                                                    <p class="mt-1 flex items-center gap-1.5 text-xs font-bold text-status-critical tabular-nums" data-failed>
                                                        <span class="money-failed size-1.5 rounded-full" aria-hidden="true"></span>
                                                        {{ __('funding_agreements.amount_failed', ['amount' => $shortAmount($money['failedAmount'])]) }}
                                                    </p>
                                                @endif

                                                @if ($money['missingNet'] > 0)
                                                    <p class="mt-1 text-xs font-semibold text-status-warn" data-net-missing>{{ trans_choice('funding_agreements.count_tranches_without_net', $money['missingNet'], ['count' => $money['missingNet']]) }}</p>
                                                @endif

                                                @if ($agreement->overdue_reports_count > 0)
                                                    <p class="mt-1 text-xs font-bold text-status-critical" data-report-overdue>{{ trans_choice('funding_agreements.count_donor_reports_overdue', $agreement->overdue_reports_count, ['count' => $agreement->overdue_reports_count]) }}</p>
                                                @elseif ($agreement->next_report_due !== null)
                                                    <p class="mt-1 text-xs tabular-nums {{ $mutedText }}" data-report-due>{{ __('funding_agreements.donor_report_due_on', ['date' => \Carbon\CarbonImmutable::parse($agreement->next_report_due)->translatedFormat(__('formats.date'))]) }}</p>
                                                @endif

                                                <button
                                                    type="button"
                                                    x-on:click="toggleLane({{ $agreement->id }})"
                                                    :aria-expanded="(open === {{ $agreement->id }}).toString()"
                                                    aria-controls="agreement-ledger-{{ $agreement->id }}"
                                                    class="mt-2 inline-flex min-h-8 items-center gap-1.5 rounded-md text-xs font-semibold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-primary {{ $inkText }}"
                                                    data-lane-toggle
                                                >
                                                    <flux:icon.chevron-down variant="micro" class="size-4 transition-transform duration-200" ::class="open === {{ $agreement->id }} && 'rotate-180'" />
                                                    {{ trans_choice('common.count_tranche', $agreement->transfers->count(), ['count' => $agreement->transfers->count()]) }}
                                                    @if ($projects->isNotEmpty())
                                                        <span class="{{ $mutedText }}" aria-hidden="true">·</span>
                                                        <span class="truncate">{{ trans_choice('funding_agreements.funds_count_projects', $projects->count(), ['count' => $projects->count()]) }}</span>
                                                    @endif
                                                </button>
                                            </div>
                                        </div>

                                        {{-- The lane's track: tranches placed on their dates. --}}
                                        <div class="relative hidden min-h-full @3xl/cal:block" style="width: var(--track-w)">
                                            @if ($placed->isEmpty())
                                                <p class="sticky start-[calc(var(--lane-w)+1rem)] flex h-full w-max items-center gap-2 px-4 text-xs {{ $mutedText }}">
                                                    <span class="size-3 rounded-full border border-dashed border-secondary-medium dark:border-ink-faint" aria-hidden="true"></span>
                                                    {{ __('funding_agreements.no_tranches_scheduled_yet') }}
                                                </p>
                                            @else
                                                <ol class="absolute inset-0" aria-label="{{ __('funding_agreements.tranches_of_name', ['name' => $agreementName]) }}">
                                                    {{-- The agreement's life: first to last tranche, teal as far as money has landed. --}}
                                                    <span class="absolute top-1/2 h-px -translate-y-1/2 bg-secondary-light/70" style="inset-inline-start: calc(var(--month-w) * {{ $offsets->min() }}); width: calc(var(--month-w) * {{ max(0, $offsets->max() - $offsets->min()) }})" aria-hidden="true"></span>
                                                    @if ($lastReceived !== null)
                                                        <span class="cal-thread absolute top-1/2 h-[3px] -translate-y-1/2 rounded-full bg-funding" style="inset-inline-start: calc(var(--month-w) * {{ $offsets->min() }}); width: calc(var(--month-w) * {{ max(0, $offsets[$lastReceived] - $offsets->min()) }})" aria-hidden="true"></span>
                                                    @endif

                                                    @foreach ($placed as $position => $transfer)
                                                        @php
                                                            $state = $this->trancheState($transfer);
                                                            $offset = $offsets[$position];
                                                            $crowded = $position > 0 && ($offset - $offsets[$position - 1]) < 0.85;
                                                            // At quarter zoom a month is under 3rem wide: only label a tranche with two clear months after it.
                                                            $roomy = ! isset($offsets[$position + 1]) || ($offsets[$position + 1] - $offset) >= 2;
                                                            $size = round(0.625 + 0.625 * sqrt($budget > 0 ? min(1, (float) $transfer->payment_amount / $budget) : 0.2), 3);
                                                            $monthKey = $transfer->scheduled_date->format('Y-m');
                                                            $stateLabel = $trancheStateLabels[$state];
                                                            $trancheText = __('funding_agreements.tranche_summary', [
                                                                'amount' => $amount((float) $transfer->payment_amount),
                                                                'currency' => $transfer->amount_transfered_currency->value,
                                                                'date' => $transfer->scheduled_date->translatedFormat(__('formats.date')),
                                                                'state' => $stateLabel,
                                                            ]);
                                                        @endphp
                                                        @if ($state === 'late')
                                                            {{-- How late: a red tail from the missed date to today. --}}
                                                            <span class="cal-late-tail absolute top-1/2 h-0 -translate-y-1/2 border-t-2 border-dashed border-status-critical" style="inset-inline-start: calc(var(--month-w) * {{ $offset }}); width: calc(var(--month-w) * {{ max(0, $calendar['today'] - $offset) }})" aria-hidden="true"></span>
                                                        @endif
                                                        <li
                                                            class="cal-pill group/pill absolute top-1/2 z-[2] transition-opacity duration-200 hover:z-30 focus-within:z-30"
                                                            :class="dims(@js($monthKey), @js($this->quarterKey($transfer->scheduled_date))) && 'opacity-20'"
                                                            style="inset-inline-start: calc(var(--month-w) * {{ $offset }}); --pill-order: {{ min((int) floor($offset), 24) }}"
                                                            wire:key="pill-{{ $transfer->id }}"
                                                        >
                                                            <button
                                                                type="button"
                                                                x-on:click="toggleLane({{ $agreement->id }}, {{ $transfer->id }})"
                                                                aria-label="{{ $trancheText }}"
                                                                aria-controls="agreement-ledger-{{ $agreement->id }}"
                                                                class="relative flex -translate-x-1/2 -translate-y-1/2 items-center rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary rtl:translate-x-1/2"
                                                                data-bead="{{ $state }}"
                                                            >
                                                                <span @class(['tranche-bead block shrink-0 rounded-full transition-transform duration-150 ease-out group-hover/pill:scale-125', $beadClass[$state]]) style="width: {{ $size }}rem; height: {{ $size }}rem" aria-hidden="true"></span>
                                                                <span
                                                                    @class([
                                                                        'absolute start-full ms-1.5 rounded-full px-1.5 py-px text-[11px] leading-4 font-bold whitespace-nowrap tabular-nums',
                                                                        $tagClass[$state],
                                                                        'bottom-full mb-0.5 -ms-1' => $crowded,
                                                                    ])
                                                                    x-show="zoom === 'month' || @js($roomy)"
                                                                    aria-hidden="true"
                                                                >{{ $shortAmount((float) $transfer->payment_amount) }}</span>
                                                            </button>
                                                            {{-- Tooltip: the tranche in words. --}}
                                                            <span class="pointer-events-none invisible absolute top-full start-0 z-30 mt-3 w-max max-w-60 -translate-x-1/2 rounded-lg bg-secondary-dark px-2.5 py-2 text-start text-[11px] leading-snug text-white opacity-0 shadow-[0_8px_24px_-8px_rgb(0_0_0/0.4)] transition-opacity duration-150 group-hover/pill:visible group-hover/pill:opacity-100 group-focus-within/pill:visible group-focus-within/pill:opacity-100 rtl:translate-x-1/2 dark:bg-surface-3 dark:shadow-raised dark:text-ink" aria-hidden="true">
                                                                <span class="block text-xs font-bold tabular-nums">{{ $amount((float) $transfer->payment_amount) }} {{ $transfer->amount_transfered_currency->value }}</span>
                                                                <span class="block tabular-nums opacity-80">{{ $transfer->scheduled_date->translatedFormat(__('formats.date')) }} · {{ $stateLabel }}</span>
                                                                @if ($state === 'received' && $transfer->net_amount_received !== null)
                                                                    <span class="block tabular-nums opacity-80">{{ __('funding_agreements.net_amount_landed', ['amount' => $amount((float) $transfer->net_amount_received), 'currency' => $transfer->amount_received_currency->value]) }}</span>
                                                                @elseif ($transfer->isMissingNetAmount())
                                                                    <span class="block opacity-80">{{ __('funding_agreements.net_not_recorded') }}</span>
                                                                @endif
                                                                <span class="block opacity-80">{{ $transfer->account?->account_name ?? __('funding_agreements.no_account_assigned') }}</span>
                                                            </span>
                                                        </li>
                                                    @endforeach
                                                </ol>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Phones: no shared month grid, so the tranches read as a short list. --}}
                                    @if ($placed->isNotEmpty())
                                        <ol class="-mt-1 flex flex-wrap gap-1.5 pb-4 ps-[3.875rem] @3xl/cal:hidden" aria-label="{{ __('funding_agreements.tranches_of_name', ['name' => $agreementName]) }}">
                                            @foreach ($placed as $transfer)
                                                @php
                                                    $state = $this->trancheState($transfer);
                                                @endphp
                                                <li class="inline-flex items-center gap-1.5 rounded-full py-0.5 ps-1.5 pe-2 text-[11px] font-bold tabular-nums {{ $tagClass[$state] }}" wire:key="chip-{{ $transfer->id }}">
                                                    <span class="size-2 shrink-0 rounded-full {{ $beadClass[$state] }}" aria-hidden="true"></span>
                                                    {{ $shortAmount((float) $transfer->payment_amount) }}
                                                    <span class="font-semibold opacity-80">{{ $transfer->scheduled_date->translatedFormat(__('formats.date_short')) }}</span>
                                                    <span class="sr-only">{{ $trancheStateLabels[$state] }}</span>
                                                </li>
                                            @endforeach
                                        </ol>
                                    @endif

                                    {{-- The agreement opened in place: its connections and every tranche. --}}
                                    <div
                                        id="agreement-ledger-{{ $agreement->id }}"
                                        class="cal-ledger grid"
                                        :class="open === {{ $agreement->id }} ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
                                        :inert="open !== {{ $agreement->id }}"
                                        data-agreement-ledger
                                    >
                                        <div class="min-h-0 overflow-clip">
                                            <div class="sticky start-0 z-[3] grid gap-x-10 gap-y-6 border-t bg-board-head px-4 py-5 @3xl/cal:w-[var(--view-w)] @4xl/cal:grid-cols-[minmax(14rem,0.8fr)_minmax(0,2fr)] dark:bg-white/[0.03] {{ $hairline }}">
                                                <dl class="grid content-start gap-3 text-xs">
                                                    <div>
                                                        <dt class="font-semibold {{ $mutedText }}">{{ __('common.proposal') }}</dt>
                                                        <dd class="mt-1 flex flex-wrap items-center gap-2">
                                                            <a href="{{ route('proposals.show', $agreement->proposal) }}" wire:navigate class="font-bold underline-offset-4 hover:underline {{ $inkText }}">{{ $agreement->proposal->name }}</a>
                                                            <x-ui.enum-badge :case="$agreement->proposal->status" />
                                                        </dd>
                                                    </div>
                                                    <div class="grid grid-cols-2 gap-3">
                                                        <div>
                                                            <dt class="font-semibold {{ $mutedText }}">{{ __('common.internal_project_number') }}</dt>
                                                            <dd class="mt-1 font-bold {{ $inkText }}"><x-ui.project-number :number="$agreement->proposal->internal_project_number" kind="internal" /></dd>
                                                        </div>
                                                        <div>
                                                            <dt class="font-semibold {{ $mutedText }}">{{ __('funding_agreements.external_project_number') }}</dt>
                                                            <dd class="mt-1 font-bold {{ $inkText }}"><x-ui.project-number :number="$agreement->external_project_number" kind="external" /></dd>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <dt class="font-semibold {{ $mutedText }}">{{ __('funding_agreements.projects_funded') }}</dt>
                                                        <dd class="mt-1 flex flex-wrap gap-x-3 gap-y-1">
                                                            @forelse ($projects as $project)
                                                                <a href="{{ route('projects.show', $project) }}" wire:navigate class="inline-flex items-center gap-1.5 font-bold underline-offset-4 hover:underline {{ $inkText }}" wire:key="agreement-{{ $agreement->id }}-project-{{ $project->id }}">
                                                                    <span class="size-1.5 shrink-0 rounded-full border border-secondary-dark dark:border-ink-muted" aria-hidden="true"></span>{{ $project->name }}
                                                                </a>
                                                            @empty
                                                                <span class="{{ $mutedText }}">{{ __('funding_agreements.no_projects_linked_yet') }}</span>
                                                            @endforelse
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt class="font-semibold {{ $mutedText }}">{{ __('funding_agreements.lands_in') }}</dt>
                                                        <dd class="mt-1 font-bold {{ $inkText }}">{{ $money['accounts'] !== [] ? implode(', ', $money['accounts']) : __('funding_agreements.no_account_assigned') }}</dd>
                                                    </div>
                                                    @foreach ($money['otherCurrencies'] as $otherCurrency => $otherAmount)
                                                        <p class="tabular-nums {{ $mutedText }}">{{ __('funding_agreements.also_landed_in', ['amount' => $amount($otherAmount), 'currency' => $otherCurrency]) }}</p>
                                                    @endforeach
                                                </dl>

                                                <div class="min-w-0">
                                                    @if ($agreement->transfers->isEmpty())
                                                        <p class="text-xs {{ $mutedText }}">{{ __('funding_agreements.no_tranches_scheduled_yet') }}</p>
                                                    @else
                                                        <div class="@container">
                                                            <table class="w-full text-xs @max-xl:block">
                                                                <caption class="sr-only">{{ __('funding_agreements.tranches_of_name', ['name' => $agreementName]) }}</caption>
                                                                <thead class="@max-xl:hidden">
                                                                    <tr class="text-start {{ $mutedText }}">
                                                                        <th scope="col" class="pb-2 text-start font-semibold">{{ __('funding_agreements.due') }}</th>
                                                                        <th scope="col" class="pb-2 text-end font-semibold">{{ __('funding_agreements.amount') }}</th>
                                                                        <th scope="col" class="pb-2 ps-4 text-start font-semibold">{{ __('common.status') }}</th>
                                                                        <th scope="col" class="pb-2 text-start font-semibold">{{ __('funding_agreements.sent') }}</th>
                                                                        <th scope="col" class="pb-2 text-end font-semibold">{{ __('funding_agreements.landed') }}</th>
                                                                        <th scope="col" class="pb-2 ps-4 text-start font-semibold">{{ __('funding_agreements.account') }}</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody class="[&>tr>td]:@max-xl:block [&>tr>td]:@max-xl:whitespace-normal [&>tr>td]:@max-xl:before:block [&>tr>td]:@max-xl:before:text-[11px] [&>tr>td]:@max-xl:before:font-semibold [&>tr>td]:@max-xl:before:content-[attr(data-label)] [&>tr>td]:@max-xl:before:text-secondary-medium dark:[&>tr>td]:@max-xl:before:text-secondary-soft [&>tr>td]:@max-xl:p-0 [&>tr]:@max-xl:grid [&>tr]:@max-xl:grid-cols-2 [&>tr]:@max-xl:gap-x-4 [&>tr]:@max-xl:gap-y-2 [&>tr]:@max-xl:py-3 @max-xl:block">
                                                                    @foreach ($agreement->transfers as $transfer)
                                                                        @php
                                                                            $state = $this->trancheState($transfer);
                                                                        @endphp
                                                                        <tr
                                                                            class="border-t transition-colors duration-300 {{ $hairline }}"
                                                                            :class="focusTranche === {{ $transfer->id }} && 'bg-white shadow-[inset_3px_0_0_var(--color-primary)] dark:bg-white/5'"
                                                                            wire:key="ledger-row-{{ $transfer->id }}"
                                                                            data-ledger-row="{{ $state }}"
                                                                        >
                                                                            <td data-label="{{ __('funding_agreements.due') }}" class="py-2 pe-3 font-semibold whitespace-nowrap tabular-nums {{ $inkText }}">{{ $transfer->scheduled_date?->translatedFormat(__('formats.date')) ?? '—' }}</td>
                                                                            <td data-label="{{ __('funding_agreements.amount') }}" class="py-2 pe-3 text-end font-bold whitespace-nowrap tabular-nums {{ $inkText }}">{{ $amount((float) $transfer->payment_amount) }} <span class="font-semibold {{ $mutedText }}">{{ $transfer->amount_transfered_currency->value }}</span></td>
                                                                            <td data-label="{{ __('common.status') }}" class="py-2 ps-4 pe-3 whitespace-nowrap">
                                                                                <span class="inline-flex items-center gap-1.5 rounded-full py-0.5 ps-1.5 pe-2 text-[11px] font-bold {{ $tagClass[$state] }}">
                                                                                    <span class="size-2 rounded-full {{ $beadClass[$state] }}" aria-hidden="true"></span>{{ $trancheStateLabels[$state] }}
                                                                                </span>
                                                                            </td>
                                                                            <td data-label="{{ __('funding_agreements.sent') }}" class="py-2 pe-3 whitespace-nowrap tabular-nums {{ $mutedText }}">{{ $transfer->transfer_date?->translatedFormat(__('formats.date')) ?? '—' }}</td>
                                                                            <td data-label="{{ __('funding_agreements.landed') }}" class="py-2 pe-3 text-end whitespace-nowrap tabular-nums">
                                                                                @if ($state === 'received' && $transfer->net_amount_received !== null)
                                                                                    <span class="font-bold text-funding">{{ $amount((float) $transfer->net_amount_received) }} {{ $transfer->amount_received_currency->value }}</span>
                                                                                    @if ($transfer->amount_received !== null)
                                                                                        <span class="block text-[11px] {{ $mutedText }}">{{ __('funding_agreements.gross_amount', ['amount' => $amount((float) $transfer->amount_received)]) }}</span>
                                                                                    @endif
                                                                                    @if ((float) $transfer->fees > 0)
                                                                                        <span class="block text-[11px] {{ $mutedText }}">{{ __('funding_agreements.fees_amount', ['amount' => $amount((float) $transfer->fees), 'currency' => $transfer->fees_currency->value]) }}</span>
                                                                                    @endif
                                                                                @elseif ($transfer->isMissingNetAmount())
                                                                                    <span class="font-semibold text-status-warn">{{ __('funding_agreements.net_not_recorded') }}</span>
                                                                                @else
                                                                                    <span class="{{ $mutedText }}">—</span>
                                                                                @endif
                                                                            </td>
                                                                            <td data-label="{{ __('funding_agreements.account') }}" class="py-2 ps-4 {{ $inkText }}">{{ $transfer->account?->account_name ?? __('funding_agreements.no_account_assigned') }}</td>
                                                                        </tr>
                                                                    @endforeach
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    @endif
                                                    <div class="mt-4 flex flex-wrap items-center gap-2">
                                                        <x-ui.button tone="secondary" size="sm" icon="eye" :href="route('funding-agreements.show', $agreement)" wire:navigate>{{ __('funding_agreements.open_agreement') }}</x-ui.button>
                                                        <button type="button" x-on:click="open = null" class="inline-flex min-h-8 items-center rounded-md px-2 text-xs font-semibold hover:underline focus-visible:outline-2 focus-visible:outline-primary {{ $mutedText }}">{{ __('common.close') }}</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
