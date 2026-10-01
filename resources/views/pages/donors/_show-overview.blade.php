{{--
    The Overview view of the donor page: the money river per currency, the
    three readings, the money calendar and one card per agreement. Included
    by ⚡show.blade.php, which defines $amount, $formatDate, $percentOf,
    $stateLabels, $donor, $mutedText, $inkText and $hairline.
--}}
        {{-- The money river: one per currency, committed split into what landed, what is coming, what is late and what nobody has scheduled. --}}
        <section class="mt-9 flex flex-col gap-10" aria-label="{{ __('donors.money_position') }}" data-money-river>
            @foreach ($this->moneyByCurrency as $currency => $line)
                @php
                    $landedPercent = App\Models\DonorProposal::percentLabel($line['received'], $line['committed']);
                    $parts = [
                        ['key' => 'received', 'label' => __('donors.received'), 'value' => $line['received'], 'bar' => 'bg-funding', 'dot' => 'bg-funding', 'text' => 'text-funding'],
                        ['key' => 'due', 'label' => __('donors.due_ahead'), 'value' => $line['due'], 'bar' => 'money-pending', 'dot' => 'money-pending', 'text' => 'text-status-warn'],
                        ['key' => 'overdue', 'label' => __('donors.overdue'), 'value' => $line['overdue'], 'bar' => 'bg-primary dark:bg-status-critical', 'dot' => 'bg-primary dark:bg-status-critical', 'text' => 'text-status-critical'],
                        ...($line['failed'] > 0 ? [['key' => 'failed', 'label' => \App\Enums\ArStatus::Failed->label(), 'value' => $line['failed'], 'bar' => 'money-failed', 'dot' => 'money-failed', 'text' => 'text-status-critical']] : []),
                        ['key' => 'unscheduled', 'label' => __('donors.not_yet_scheduled'), 'value' => $line['unscheduled'], 'bar' => '', 'dot' => 'bg-secondary-soft dark:bg-line-strong', 'text' => $mutedText],
                    ];
                @endphp
                <div class="min-w-0" wire:key="river-{{ $currency }}" data-river="{{ $currency }}">
                    <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-3">
                        <div class="min-w-0">
                            <p class="type-label {{ $mutedText }}">{{ __('donors.committed_currency', ['currency' => $currency]) }}</p>
                            <p class="mt-2 flex flex-wrap items-baseline gap-x-2 text-5xl leading-none font-extrabold tracking-tight tabular-nums @3xl/detail:text-6xl {{ $inkText }}">
                                <span>{{ $amount($line['committed']) }}</span>
                                <span class="text-xl font-bold tracking-normal {{ $mutedText }}">{{ $currency }}</span>
                            </p>
                        </div>
                        <p class="flex items-baseline gap-2 text-funding">
                            <span class="text-5xl leading-none font-extrabold tracking-tight tabular-nums @3xl/detail:text-6xl" data-landed-percent>{{ $landedPercent }}<span class="text-2xl">%</span></span>
                            <span class="text-sm font-semibold">{{ __('donors.landed') }}</span>
                        </p>
                    </div>

                    <div
                        class="mt-5 flex h-4 w-full gap-0.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2"
                        role="img"
                        aria-label="{{ __('donors.river_label', ['currency' => $currency, 'received' => $amount($line['received']), 'due' => $amount($line['due']), 'overdue' => $amount($line['overdue']), 'committed' => $amount($line['committed'])]) }}"
                    >
                        @foreach ($parts as $part)
                            @if ($part['bar'] !== '' && $part['value'] > 0)
                                <span class="money-grow h-full first:rounded-s-full {{ $part['bar'] }}" style="width: {{ $percentOf($part['value'], $line['committed']) }}%"></span>
                            @endif
                        @endforeach
                    </div>

                    <dl @class(['mt-5 grid grid-cols-2 gap-x-6 gap-y-5', '@3xl/detail:grid-cols-4' => count($parts) === 4, '@3xl/detail:grid-cols-5' => count($parts) === 5])>
                        @foreach ($parts as $part)
                            <div class="min-w-0 border-t pt-3 {{ $hairline }}" data-river-part="{{ $part['key'] }}">
                                <dt class="flex items-center gap-2 text-xs font-semibold {{ $mutedText }}">
                                    <span class="size-2.5 shrink-0 rounded-full {{ $part['dot'] }}" aria-hidden="true"></span>
                                    {{ $part['label'] }}
                                </dt>
                                <dd @class(['mt-1.5 truncate text-xl font-bold tabular-nums @5xl/detail:text-2xl', $part['value'] > 0 ? $part['text'] : $mutedText])>{{ $amount($part['value']) }}</dd>
                                <dd class="mt-0.5 text-xs tabular-nums {{ $mutedText }}">{{ __('donors.share_of_committed', ['percent' => round($percentOf($part['value'], $line['committed']))]) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endforeach

            @foreach ($this->receivedElsewhere as $currency => $total)
                <p class="-mt-6 text-xs {{ $mutedText }}" wire:key="elsewhere-{{ $currency }}">{{ __('donors.received_in_other_currency', ['currency' => $currency, 'amount' => $amount($total)]) }}</p>
            @endforeach

            @if (count($this->moneyByCurrency) > 1)
                <p class="-mt-6 text-xs {{ $mutedText }}">{{ __('donors.budgets_are_shown_per_currency_and_never_added_together') }}</p>
            @endif
        </section>

        {{-- Three readings of what the money reaches. --}}
        <dl class="mt-10 grid gap-x-8 gap-y-6 border-y py-6 @2xl/detail:grid-cols-3 {{ $hairline }}" data-readings>
            <div class="min-w-0">
                <dt class="type-label {{ $mutedText }}">{{ __('donors.projects_funded') }}</dt>
                <dd class="mt-2 text-4xl leading-none font-extrabold tracking-tight tabular-nums {{ $inkText }}" data-projects-funded>{{ $this->projects->count() }}</dd>
                <dd class="mt-2 text-xs tabular-nums {{ $mutedText }}">{{ __('donors.count_active_count_closed', ['active' => $this->projectCounts['open'], 'closed' => $this->projectCounts['closed']]) }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="type-label {{ $mutedText }}">{{ __('donors.reports_submitted') }}</dt>
                <dd class="mt-2 text-4xl leading-none font-extrabold tracking-tight tabular-nums {{ $inkText }}" data-reports-submitted>{{ $this->reportsSubmittedCount }}</dd>
                <dd class="mt-2 text-xs {{ $mutedText }}">{{ __('donors.against_targets_these_grants_committed') }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="type-label {{ $mutedText }}">{{ __('donors.next_money_in') }}</dt>
                @if ($this->nextTranche !== null)
                    @php
                        $next = $this->nextTranche['transfer'];
                    @endphp
                    <dd class="mt-2 flex flex-wrap items-baseline gap-x-1.5 text-4xl leading-none font-extrabold tracking-tight tabular-nums text-status-warn" data-next-tranche>
                        {{ $amount((float) $next->payment_amount) }}
                        <span class="text-base font-bold tracking-normal {{ $mutedText }}">{{ $next->amount_transfered_currency?->value ?? $this->nextTranche['agreement']->currency->value }}</span>
                    </dd>
                    <dd class="mt-2 text-xs tabular-nums {{ $mutedText }}">{{ __('donors.next_money_line', ['proposal' => $this->nextTranche['agreement']->proposal->name, 'date' => $next->scheduled_date->translatedFormat(__('formats.date'))]) }}</dd>
                @else
                    <dd class="mt-2 text-4xl leading-none font-extrabold {{ $mutedText }}">—</dd>
                    <dd class="mt-2 text-xs {{ $mutedText }}">{{ __('donors.no_transfer_scheduled_ahead') }}</dd>
                @endif
            </div>
        </dl>

        {{-- The money calendar: every dated tranche on one time axis, one lane per agreement, today marked. --}}
        <section class="mt-10" aria-labelledby="calendar-heading" data-money-calendar>
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2">
                <h2 id="calendar-heading" class="text-lg font-bold {{ $inkText }}">{{ __('donors.money_calendar') }}</h2>
                <ul class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs {{ $mutedText }}">
                    <li class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-funding" aria-hidden="true"></span>{{ __('donors.received') }}</li>
                    <li class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full border-2 border-status-warn" aria-hidden="true"></span>{{ __('donors.due_ahead') }}</li>
                    <li class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-primary dark:bg-status-critical" aria-hidden="true"></span>{{ __('donors.overdue') }}</li>
                    <li class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full border-2 border-dashed border-status-critical" aria-hidden="true"></span>{{ $stateLabels['failed'] }}</li>
                </ul>
            </div>

            @if ($this->calendar === null)
                <p class="mt-4 text-sm {{ $mutedText }}">{{ __('donors.no_dated_tranches') }}</p>
            @else
                <div class="mt-5 overflow-x-auto pb-2">
                    <div class="min-w-[48rem]">
                        @foreach ($this->calendar['lanes'] as $lane)
                            <div class="grid grid-cols-[15rem_minmax(0,1fr)] items-center gap-4 border-b {{ $hairline }}" wire:key="lane-{{ $lane['agreement']->id }}" data-lane="{{ $lane['agreement']->id }}">
                                <a href="{{ route('funding-agreements.show', $lane['agreement']) }}" wire:navigate class="min-w-0 py-3 text-xs underline-offset-4 hover:underline">
                                    <span class="block truncate font-semibold {{ $inkText }}">{{ $lane['agreement']->proposal->name }}</span>
                                    <span class="mt-0.5 block {{ $mutedText }}">
                                        <x-ui.project-number :number="$lane['agreement']->proposal->internal_project_number" kind="internal" />
                                    </span>
                                    <span class="mt-0.5 block truncate {{ $mutedText }}" data-lane-external>
                                        <x-ui.project-number :number="$lane['agreement']->external_project_number" kind="external" />
                                    </span>
                                </a>
                                <div class="relative h-14">
                                    <span class="absolute inset-x-0 top-1/2 h-px bg-secondary-soft dark:bg-line" aria-hidden="true"></span>
                                    <span class="absolute inset-y-0 w-px bg-secondary-dark/70 dark:bg-white/70" style="inset-inline-start: {{ $this->calendar['today'] }}%" aria-hidden="true"></span>
                                    @foreach ($lane['points'] as $point)
                                        <a
                                            href="{{ route('funding-agreements.show', $lane['agreement']) }}"
                                            wire:navigate
                                            class="group absolute top-1/2 z-10 -translate-x-1/2 hover:z-30 focus-visible:z-30 -translate-y-1/2 rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary rtl:translate-x-1/2"
                                            style="inset-inline-start: {{ $point['position'] }}%"
                                            aria-label="{{ __('donors.tranche_point_label', ['state' => $stateLabels[$point['state']], 'amount' => $amount($point['amount']), 'currency' => $point['currency'], 'date' => $point['date']->translatedFormat(__('formats.date')), 'proposal' => $lane['agreement']->proposal->name]) }}"
                                            wire:key="point-{{ $point['id'] }}"
                                            data-point="{{ $point['state'] }}"
                                        >
                                            <span @class([
                                                'block rounded-full transition-transform duration-200 ease-out group-hover:scale-125',
                                                'bg-funding' => $point['state'] === 'received',
                                                'bg-primary dark:bg-status-critical ring-4 ring-primary/15' => $point['state'] === 'overdue',
                                                'border-2 border-dashed border-status-critical bg-white dark:bg-surface-1' => $point['state'] === 'failed',
                                                'border-[3px] border-status-warn bg-white dark:bg-surface-1' => in_array($point['state'], ['due', 'transit', 'undated'], true),
                                            ]) style="width: {{ $point['size'] }}rem; height: {{ $point['size'] }}rem"></span>
                                            <span @class(['pointer-events-none absolute hidden', 'top-full mt-2' => $loop->parent->first, 'bottom-full mb-2' => ! $loop->parent->first, 'start-0' => $point['position'] < 20, 'end-0' => $point['position'] > 80, 'start-1/2 -translate-x-1/2 rtl:translate-x-1/2' => $point['position'] >= 20 && $point['position'] <= 80, 'rounded-md bg-secondary-dark px-2.5 py-1.5 text-start text-[11px] whitespace-nowrap text-white shadow-[0_6px_16px_-6px_rgb(0_0_0/0.4)] group-hover:block group-focus-visible:block dark:bg-surface-3 dark:shadow-raised dark:text-ink'])>
                                                <span class="block font-bold tabular-nums">{{ $amount($point['amount']) }} {{ $point['currency'] }}</span>
                                                <span class="block tabular-nums opacity-80">{{ $stateLabels[$point['state']] }} · {{ $point['date']->translatedFormat(__('formats.date')) }}</span>
                                            </span>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach

                        <div class="grid grid-cols-[15rem_minmax(0,1fr)] gap-4">
                            <span></span>
                            <div class="relative h-12" aria-hidden="true">
                                @foreach ($this->calendar['ticks'] as $tick)
                                    <span class="absolute top-2 text-[10px] whitespace-nowrap tabular-nums {{ $mutedText }}" style="inset-inline-start: {{ $tick['position'] }}%">{{ $tick['label'] }}</span>
                                @endforeach
                                <span class="absolute top-6 -translate-x-1/2 rounded-sm bg-secondary-dark px-1.5 py-0.5 text-[10px] font-bold text-white rtl:translate-x-1/2 dark:bg-ink dark:text-surface-0" style="inset-inline-start: {{ $this->calendar['today'] }}%">{{ __('donors.today') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </section>

        {{-- Each agreement threaded to the projects it funds and the reports filed against its targets. --}}
        <section class="mt-10 pb-10" aria-labelledby="agreements-heading" data-agreements>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="agreements-heading" class="text-lg font-bold {{ $inkText }}">{{ __('donors.agreements_and_what_they_fund') }}</h2>
                @can('funding-agreements.create')
                <x-ui.button size="sm" icon="plus" class="min-h-10 touch-manipulation" wire:click="recordAgreementForDonor" data-test="record-agreement-for-donor">{{ __('funding_agreements.record_agreement') }}</x-ui.button>
                @endcan
            </div>

            <div class="mt-2 flex flex-col">
                @foreach ($this->agreements as $agreement)
                    @php
                        $money = $this->agreementMoney($agreement);
                        $currency = $agreement->currency->value;
                        $reports = $this->reportsByProposal[$agreement->proposal_id] ?? collect();
                        $reportCount = $reports->count();
                    @endphp
                    <article class="grid gap-6 border-b py-7 last:border-b-0 @4xl/detail:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)] @4xl/detail:gap-12 {{ $hairline }}" wire:key="donor-agreement-{{ $agreement->id }}" data-agreement="{{ $agreement->id }}">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                                <a href="{{ route('proposals.show', $agreement->proposal) }}" wire:navigate class="text-base font-bold underline-offset-4 hover:underline @3xl/detail:text-lg {{ $inkText }}">{{ $agreement->proposal->name }}</a>
                                <x-ui.enum-badge :case="$agreement->proposal->status" />
                            </div>
                            <a href="{{ route('funding-agreements.show', $agreement) }}" wire:navigate class="mt-1 inline-flex flex-wrap items-center gap-1 text-xs underline-offset-4 hover:underline {{ $mutedText }}">
                                <x-ui.project-number :number="$agreement->proposal->internal_project_number" kind="internal" />
                                · {{ filled($agreement->external_project_number) ? __('donors.agreement_ref', ['number' => $agreement->external_project_number]) : __('donors.view_agreement') }}
                                · {{ trans_choice('common.count_tranche', $agreement->transfers->count(), ['count' => $agreement->transfers->count()]) }}
                                <flux:icon.arrow-up-right variant="micro" class="size-3.5 rtl:-scale-x-100" aria-hidden="true" />
                            </a>

                            <p class="mt-4 flex flex-wrap items-baseline gap-x-1.5 text-3xl leading-none font-extrabold tracking-tight tabular-nums {{ $inkText }}">
                                {{ $amount($money['committed']) }}
                                <span class="text-sm font-semibold tracking-normal {{ $mutedText }}">{{ $currency }}</span>
                            </p>

                            <div class="mt-3 flex h-2 w-full gap-0.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" role="img" aria-label="{{ __('donors.river_label', ['currency' => $currency, 'received' => $amount($money['received']), 'due' => $amount($money['due']), 'overdue' => $amount($money['overdue']), 'committed' => $amount($money['committed'])]) }}">
                                @if ($money['received'] > 0)
                                    <span class="money-grow h-full rounded-s-full bg-funding" style="width: {{ $percentOf($money['received'], $money['committed']) }}%"></span>
                                @endif
                                @if ($money['due'] > 0)
                                    <span class="money-grow money-pending h-full first:rounded-s-full" style="width: {{ $percentOf($money['due'], $money['committed']) }}%"></span>
                                @endif
                                @if ($money['overdue'] > 0)
                                    <span class="money-grow h-full bg-primary dark:bg-status-critical first:rounded-s-full" style="width: {{ $percentOf($money['overdue'], $money['committed']) }}%"></span>
                                @endif
                            </div>

                            <p class="mt-2.5 flex flex-wrap gap-x-4 gap-y-1 text-xs tabular-nums {{ $mutedText }}">
                                <span><span class="font-bold text-funding">{{ $amount($money['received']) }}</span> {{ __('donors.received_lower') }}</span>
                                @if ($money['due'] > 0)
                                    <span><span class="font-bold text-status-warn">{{ $amount($money['due']) }}</span> {{ __('donors.due_lower') }}</span>
                                @endif
                                @if ($money['overdue'] > 0)
                                    <span><span class="font-bold text-status-critical">{{ $amount($money['overdue']) }}</span> {{ __('donors.overdue_lower') }}</span>
                                @endif
                                @if ($money['missingNet'] > 0)
                                    <span class="font-semibold text-status-warn" data-missing-net>{{ trans_choice('funding_agreements.received_tranches_missing_net', $money['missingNet'], ['count' => $money['missingNet']]) }}</span>
                                @endif
                            </p>
                        </div>

                        <div class="min-w-0">
                            <h3 class="type-label {{ $mutedText }}">{{ __('donors.funds') }}</h3>
                            @if ($agreement->proposal->projects->isEmpty())
                                <p class="mt-3 text-sm {{ $mutedText }}">{{ __('donors.no_projects_linked') }}</p>
                            @else
                                <ol class="mt-3 flex flex-col">
                                    @foreach ($agreement->proposal->projects as $project)
                                        <li class="relative flex min-w-0 gap-3 pb-3 last:pb-0" wire:key="agreement-{{ $agreement->id }}-project-{{ $project->id }}" data-funded-project="{{ $project->id }}">
                                            <span class="relative flex w-3 shrink-0 justify-center" aria-hidden="true">
                                                @unless ($loop->last)
                                                    <span class="absolute top-3 bottom-[-0.75rem] w-px bg-secondary-soft dark:bg-line-strong"></span>
                                                @endunless
                                                <span @class([
                                                    'relative z-10 mt-1.5 size-2.5 rounded-full',
                                                    'bg-secondary-dark dark:bg-ink-muted' => $project->status->isOpen(),
                                                    'border-2 border-secondary-light bg-white dark:bg-surface-1' => ! $project->status->isOpen(),
                                                ])></span>
                                            </span>
                                            <div class="flex min-w-0 flex-1 flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                                <a href="{{ route('projects.show', $project) }}" wire:navigate @class(['min-w-0 text-sm underline-offset-4 hover:underline', 'font-semibold '.$inkText => $project->status->isOpen(), $mutedText => ! $project->status->isOpen()])>{{ $project->name }}</a>
                                                <x-ui.enum-badge :case="$project->status" />
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                            @if ($agreement->reports->isNotEmpty())
                                <div class="mt-5" data-owed-donor-reports>
                                    <h3 class="type-label inline-flex items-center gap-1.5 {{ $mutedText }}">
                                        <flux:icon.paper-airplane variant="micro" class="size-4 rtl:-scale-x-100" aria-hidden="true" />
                                        {{ trans_choice('donors.count_reports_owed_to_donor', $agreement->reports->count(), ['count' => $agreement->reports->count()]) }}
                                    </h3>
                                    <ul class="mt-2 flex flex-col" role="list">
                                        @foreach ($agreement->reports as $owedReport)
                                            <li wire:key="agreement-{{ $agreement->id }}-owed-{{ $owedReport->id }}" data-owed-donor-report="{{ $owedReport->id }}">
                                                <a href="{{ route('funding-agreements.show', $agreement) }}#reports-heading" wire:navigate class="group flex min-h-9 items-center justify-between gap-3 border-b py-1.5 text-sm focus-visible:outline-2 focus-visible:outline-primary {{ $hairline }}">
                                                    <span class="min-w-0">
                                                        <span class="block truncate font-medium underline-offset-4 group-hover:underline {{ $inkText }}">{{ $owedReport->title }}</span>
                                                        <span class="block text-[11px] {{ $mutedText }}">{{ $owedReport->type->label() }}</span>
                                                    </span>
                                                    <span @class(['shrink-0 text-xs font-semibold tabular-nums', 'text-status-critical' => $owedReport->isOverdue(), $mutedText => ! $owedReport->isOverdue()])>
                                                        {{ $owedReport->isOverdue() ? __('donors.report_overdue_since', ['date' => $formatDate($owedReport->due_on)]) : __('donors.report_due_on', ['date' => $formatDate($owedReport->due_on)]) }}
                                                    </span>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            <div class="mt-5" data-agreement-reports>
                                <h3 class="type-label inline-flex items-center gap-1.5 {{ $mutedText }}">
                                    <flux:icon.document-text variant="micro" class="size-4" aria-hidden="true" />
                                    {{ trans_choice('donors.count_reports_against_targets', $reportCount, ['count' => $reportCount]) }}
                                </h3>
                                @if ($reportCount > 0)
                                    <ul class="mt-2 flex flex-col" role="list">
                                        @foreach ($reports->take($this::LISTED_REPORTS) as $report)
                                            <li wire:key="agreement-{{ $agreement->id }}-report-{{ $report->id }}" data-agreement-report="{{ $report->id }}">
                                                <a href="{{ route('reports.show', $report) }}" wire:navigate class="group flex min-h-9 items-center justify-between gap-3 border-b py-1.5 text-sm focus-visible:outline-2 focus-visible:outline-primary {{ $hairline }}">
                                                    <span class="min-w-0">
                                                        <span class="block truncate font-medium underline-offset-4 group-hover:underline {{ $inkText }}">{{ $report->planIndicator?->indicator?->name ?? __('donors.report_number', ['number' => $report->id]) }}</span>
                                                        <span class="block text-[11px] tabular-nums {{ $mutedText }}">{{ $report->planned_date->translatedFormat(__('formats.date')) }}</span>
                                                    </span>
                                                    <x-ui.enum-badge :case="$report->status" />
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if ($reportCount > $this::LISTED_REPORTS)
                                        <p class="mt-1.5 text-xs {{ $mutedText }}">{{ trans_choice('donors.and_count_older_reports', $reportCount - $this::LISTED_REPORTS, ['count' => $reportCount - $this::LISTED_REPORTS]) }}</p>
                                    @endif
                                @endif
                            </div>
                        </div>

                        @if ($agreement->transfers->isNotEmpty())
                            <details class="group min-w-0 @4xl/detail:col-span-2" data-tranche-ledger="{{ $agreement->id }}">
                                <summary class="inline-flex min-h-9 cursor-pointer list-none items-center gap-1.5 text-xs font-semibold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-primary {{ $inkText }} [&::-webkit-details-marker]:hidden">
                                    <flux:icon.chevron-right variant="micro" class="size-4 transition-transform group-open:rotate-90 rtl:-scale-x-100" aria-hidden="true" />
                                    {{ trans_choice('donors.every_tranche_in_detail', $agreement->transfers->count(), ['count' => $agreement->transfers->count()]) }}
                                </summary>
                                <div class="@container mt-3">
                                    <table class="w-full text-start text-xs tabular-nums @max-3xl:block">
                                        <thead class="@max-3xl:hidden">
                                            <tr class="border-b {{ $hairline }}">
                                                @foreach ([__('common.status'), __('transfers.scheduled'), __('transfers.sent_on'), __('transfers.payment_amount'), __('transfers.fees'), __('transfers.exchange_rate'), __('transfers.amount_received'), __('transfers.received_on'), __('transfers.account')] as $heading)
                                                    <th scope="col" class="type-label py-2 pe-4 text-start font-semibold {{ $mutedText }}">{{ $heading }}</th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="[&>tr>td]:@max-3xl:block [&>tr>td]:@max-3xl:whitespace-normal [&>tr>td]:@max-3xl:before:block [&>tr>td]:@max-3xl:before:text-[11px] [&>tr>td]:@max-3xl:before:font-semibold [&>tr>td]:@max-3xl:before:content-[attr(data-label)] [&>tr>td]:@max-3xl:before:text-secondary-medium dark:[&>tr>td]:@max-3xl:before:text-secondary-soft [&>tr>td]:@max-3xl:p-0 [&>tr]:@max-3xl:grid [&>tr]:@max-3xl:grid-cols-2 [&>tr]:@max-3xl:gap-x-4 [&>tr]:@max-3xl:gap-y-2 [&>tr]:@max-3xl:py-3 @max-3xl:block">
                                            @foreach ($agreement->transfers as $transfer)
                                                @php
                                                    $state = $this->transferState($transfer);
                                                @endphp
                                                <tr class="border-b last:border-b-0 {{ $hairline }}" wire:key="tranche-row-{{ $transfer->id }}" data-tranche-row="{{ $state }}">
                                                    <td data-label="{{ __('common.status') }}" @class(['py-2 pe-4 font-semibold whitespace-nowrap', 'text-funding' => $state === 'received', 'text-status-warn' => in_array($state, ['due', 'transit'], true), 'text-status-critical' => in_array($state, ['overdue', 'failed'], true), $mutedText => $state === 'undated'])>{{ $stateLabels[$state] }}</td>
                                                    <td data-label="{{ __('transfers.scheduled') }}" class="py-2 pe-4 whitespace-nowrap">{{ $formatDate($transfer->scheduled_date) }}</td>
                                                    <td data-label="{{ __('transfers.sent_on') }}" class="py-2 pe-4 whitespace-nowrap">{{ $formatDate($transfer->transfer_date) }}</td>
                                                    <td data-label="{{ __('transfers.payment_amount') }}" class="py-2 pe-4 font-semibold whitespace-nowrap {{ $inkText }}">{{ $amount((float) $transfer->payment_amount) }} <span class="font-normal {{ $mutedText }}">{{ $transfer->amount_transfered_currency?->value }}</span></td>
                                                    <td data-label="{{ __('transfers.fees') }}" class="py-2 pe-4 whitespace-nowrap">{{ (float) $transfer->fees > 0 ? $amount((float) $transfer->fees).' '.$transfer->fees_currency?->value : '—' }}</td>
                                                    <td data-label="{{ __('transfers.exchange_rate') }}" class="py-2 pe-4 whitespace-nowrap">{{ (float) $transfer->exchange_rate !== 1.0 ? rtrim(rtrim(number_format((float) $transfer->exchange_rate, 6), '0'), '.') : '—' }}</td>
                                                    <td data-label="{{ __('transfers.amount_received') }}" class="py-2 pe-4 whitespace-nowrap">{{ $transfer->net_amount_received !== null ? $amount((float) $transfer->net_amount_received).' '.$transfer->amount_received_currency?->value : '—' }}</td>
                                                    <td data-label="{{ __('transfers.received_on') }}" class="py-2 pe-4 whitespace-nowrap">{{ $formatDate($transfer->receive_date) }}</td>
                                                    <td data-label="{{ __('transfers.account') }}" class="py-2 pe-4 whitespace-nowrap">{{ $transfer->account?->account_name ?? __('transfers.not_yet_assigned') }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
