@use('App\Models\AnnualPlan')
@use('App\Models\Proposal')
    <div class="flex flex-col">
        <div class="flex min-h-11 flex-wrap items-center justify-between gap-3 border-b border-secondary-soft pb-3 dark:border-line">
            <p class="type-meta text-secondary-medium dark:text-ink-muted" aria-live="polite">
                {{ trans_choice('targets.count_matching_target', $this->matchingCount, ['count' => $this->matchingCount]) }}
                @if ($this->resultPage->lastPage() > 1)
                    · {{ __('targets.results_page', ['from' => $this->resultPage->firstItem(), 'to' => $this->resultPage->lastItem(), 'total' => $this->resultPage->total()]) }}
                @endif
            </p>
            @if ($scopeLabel !== null)
                <button type="button" wire:click="clearIndicatorScope" class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-secondary-dark bg-secondary-dark px-3 py-1 text-xs font-semibold text-white hover:opacity-90 dark:border-ink dark:bg-ink dark:text-surface-0" data-test="indicator-scope-chip">
                    <span class="truncate">{{ $scopeLabel }}</span>
                    <flux:icon.x-mark variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                    <span class="sr-only">{{ __('common.reset_filters') }}</span>
                </button>
            @endif
        </div>

        @if ($this->targets->isEmpty())
            <x-ui.empty-state
                icon="chart-bar"
                :heading="$filtersAreActive ? __('targets.no_matching_targets') : __('common.no_targets_yet')"
                :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('targets.commit_the_first_target_in_an_annual_plan_or_a_proposal')"
            >
                <x-slot:action>
                    @if ($filtersAreActive)
                        <x-ui.button tone="primary" icon="arrow-path" wire:click="clearFilters" data-test="empty-reset-filters">{{ __('common.reset_filters') }}</x-ui.button>
                    @endif
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <div class="flex flex-col gap-10 pt-6 transition-opacity duration-200" wire:loading.class="opacity-50" wire:target="search,sourceType,location,period,donor,program,year,filterStatus,clearFilters,gotoPage,nextPage,previousPage">
                @foreach ($groups as $resultId => $groupTargets)
                    @php
                        $groupResult = $groupTargets->first()->indicator->result;
                    @endphp
                    <section class="target-group flex flex-col" style="--group-order: {{ $loop->index }}" wire:key="target-group-{{ $resultId }}" aria-label="{{ $groupResult->statement }}">
                        <header class="flex items-start gap-3 pb-3">
                            <span aria-hidden="true" class="mt-1 size-3 shrink-0 rounded-full ring-2 ring-inset {{ $resultMark($groupResult->type) }}"></span>
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <p class="type-label text-secondary-medium dark:text-ink-muted">
                                    {{ $groupResult->type?->label() }}
                                    <span class="normal-case tracking-normal">· {{ trans_choice('targets.count_target', $groupTargets->count(), ['count' => $groupTargets->count()]) }}</span>
                                </p>
                                <p class="max-w-4xl text-pretty text-base font-semibold leading-snug"><bdi>{{ $groupResult->statement }}</bdi></p>
                            </div>
                        </header>

                        {{-- The thread: every target hangs off its result. --}}
                        <ol class="ms-1.5 flex flex-col border-s border-secondary-soft dark:border-line">
                            @foreach ($groupTargets as $target)
                                @php
                                    $source = $target->source;
                                    $sourceLabel = $this->sourceLabel($target);
                                    $isProposal = $target->source_type === 'proposal';
                                    // Names stay for everyone; a link only for someone who may open its page.
                                    $sourceUrl = match (true) {
                                        $source instanceof AnnualPlan => route('annual-plans.show', $source),
                                        $source instanceof Proposal && $canOpenProposals => route('proposals.show', $source),
                                        default => null,
                                    };
                                    $targetIndicator = $target->indicator;
                                    $isPercentage = $targetIndicator->measure?->value === 'percentage';
                                    $unitSuffix = $isPercentage ? '%' : '';
                                    $unitWord = $isPercentage ? null : (filled($targetIndicator->unit) ? $targetIndicator->unit : null);
                                    $targetAmount = (float) $target->target;
                                    $actualAmount = (float) $target->actual_value;
                                    $rowStatus = $actualAmount <= 0 ? 'not_started' : ($actualAmount >= $targetAmount ? 'achieved' : 'in_progress');
                                    $achievedPercent = $targetAmount > 0 ? $actualAmount / $targetAmount * 100 : ($actualAmount > 0 ? 100 : 0);
                                    $approvedWidth = min(100, $achievedPercent);
                                    $isExpanded = $expandedTargetId === $target->id;
                                    $chainIssues = $sourceLabel === null ? [] : $target->chainIssues();
                                    $linkClass = 'rounded-sm font-medium text-secondary-dark underline decoration-secondary-soft decoration-1 underline-offset-[3px] transition-colors hover:decoration-current focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium dark:text-ink dark:decoration-line-strong';
                                @endphp
                                <li class="relative" wire:key="target-{{ $target->id }}">
                                    <span aria-hidden="true" class="absolute top-[1.4rem] -start-[5.5px] z-10 size-2.5 rounded-full ring-4 ring-white {{ $statusMeta[$rowStatus]['dot'] }} dark:ring-surface-1"></span>

                                    <div @class([
                                        'grid gap-3 rounded-e-lg py-4 ps-6 pe-2 transition-colors duration-150 @4xl/directory:grid-cols-[minmax(0,1fr)_minmax(14rem,18rem)_auto] @4xl/directory:items-center @4xl/directory:gap-8',
                                        'bg-ground dark:bg-white/[0.04]' => $isExpanded,
                                        'hover:bg-ground dark:hover:bg-white/[0.04]' => ! $isExpanded,
                                    ])>
                                        <div class="flex min-w-0 flex-col gap-1.5">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <a href="{{ route('indicators.index', ['inspect' => $targetIndicator->id]) }}" wire:navigate class="text-[15px] font-semibold leading-snug hover:text-primary dark:hover:text-brand-text hover:underline"><bdi>{{ $targetIndicator->name }}</bdi></a>
                                                <x-ui.enum-badge :case="$target->period" />
                                            </div>

                                            {{-- A row saved before the chain rules existed, or changed around them. --}}
                                            @foreach ($chainIssues as $issue)
                                                <p class="flex items-start gap-1.5 text-xs font-semibold text-primary dark:text-brand-text" data-test="chain-issue-{{ $target->id }}">
                                                    <flux:icon.link-slash variant="micro" class="mt-px size-3.5 shrink-0" aria-hidden="true" />
                                                    {{ __($issue) }}
                                                </p>
                                            @endforeach

                                            {{-- Where it belongs, in one line. The links, donors and money are behind Details. --}}
                                            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-secondary-medium dark:text-ink-muted">
                                                <span class="inline-flex items-center gap-1 font-medium">
                                                    @if ($isProposal)
                                                        <flux:icon.document-text variant="micro" class="size-3.5" aria-hidden="true" />
                                                    @else
                                                        <flux:icon.calendar variant="micro" class="size-3.5" aria-hidden="true" />
                                                    @endif
                                                    {{ $sourceTypeLabels[$target->source_type] ?? $target->source_type }}
                                                </span>
                                                @if ($sourceLabel === null)
                                                    {{-- source_id has no foreign key: the plan or proposal can be
                                                         removed out from under this row. --}}
                                                    <span class="rounded-md border border-primary px-2 py-0.5 text-[11px] font-semibold text-primary dark:text-brand-text">
                                                        {{ __('targets.source_no_longer_available') }}
                                                    </span>
                                                @else
                                                    <span class="font-medium text-secondary-dark dark:text-ink"><bdi>{{ $sourceLabel }}</bdi></span>
                                                @endif
                                                <flux:icon.arrow-long-right variant="micro" class="size-3.5 text-secondary-light rtl:-scale-x-100" aria-hidden="true" />
                                                <span><bdi>{{ $target->location?->label() ?? __('common.organisation_wide') }}</bdi></span>
                                            </p>
                                        </div>

                                        {{-- Achievement: one status word, the figure, then the bar that explains it. --}}
                                        <div class="flex flex-col gap-1.5">
                                            <div class="flex items-baseline justify-between gap-3">
                                                <span class="shrink-0 whitespace-nowrap text-sm font-semibold {{ $statusMeta[$rowStatus]['text'] }}" data-test="target-status-{{ $target->id }}">{{ $statusMeta[$rowStatus]['label'] }}</span>
                                                <span class="min-w-0 text-end text-[13px] tabular-nums text-secondary-medium dark:text-ink-muted" dir="ltr">
                                                    <span class="font-semibold text-secondary-dark dark:text-ink">{{ $formatValue($actualAmount) }}{{ $unitSuffix }}</span>
                                                    {{ __('targets.of_target', ['target' => $formatValue($targetAmount).$unitSuffix]) }}
                                                    @if ($unitWord !== null)
                                                        <span class="break-words">{{ $unitWord }}</span>
                                                    @endif
                                                </span>
                                            </div>
                                            <div class="flex h-2 w-full overflow-hidden rounded-full bg-secondary-soft/60 dark:bg-surface-2" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round($approvedWidth) }}" aria-label="{{ __('targets.achievement_of', ['indicator' => $targetIndicator->name]) }}">
                                                <span class="target-bar h-full rounded-full {{ $statusMeta[$rowStatus]['fill'] }}" style="width: {{ $approvedWidth }}%"></span>
                                            </div>
                                            @if ($target->reviewed_count > 0)
                                                <p class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted">{{ trans_choice('targets.count_awaiting_approval', $target->reviewed_count, ['count' => $target->reviewed_count]) }}</p>
                                            @endif
                                        </div>

                                        <div class="flex items-center gap-1 justify-self-start @4xl/directory:justify-self-end">
                                            @if ($sourceLabel !== null)
                                                <x-ui.button size="sm" icon="document-plus" class="min-h-11 md:min-h-9" :href="route('reports.index', ['create' => 1, 'target' => $target->id])" wire:navigate :aria-label="__('targets.record_a_report_for', ['indicator' => $targetIndicator->name])" data-test="record-report-{{ $target->id }}">
                                                    {{ __('targets.record_a_report') }}
                                                </x-ui.button>
                                            @endif

                                            <x-ui.button
                                                tone="ghost"
                                                size="sm"
                                                :icon="$isExpanded ? 'chevron-up' : 'chevron-down'"
                                                class="min-h-11 md:min-h-9"
                                                wire:click="toggleReports({{ $target->id }})"
                                                aria-expanded="{{ $isExpanded ? 'true' : 'false' }}"
                                                aria-controls="target-details-{{ $target->id }}"
                                                :aria-label="__('targets.details').' · '.$targetIndicator->name"
                                                data-test="toggle-reports-{{ $target->id }}"
                                            >
                                                {{ __('targets.details') }}
                                            </x-ui.button>

                                            @if (auth()->user()->can('targets.update') || auth()->user()->can('targets.delete'))
                                                <flux:dropdown align="end">
                                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" class="min-h-11 md:min-h-9" :aria-label="__('targets.more_actions_for_name', ['name' => $targetIndicator->name])" data-test="target-menu-{{ $target->id }}" />
                                                    <flux:menu>
                                                        @can('targets.update')
                                                            <flux:menu.item icon="pencil-square" wire:click="openEditModal({{ $target->id }})">{{ __('targets.edit_target') }}</flux:menu.item>
                                                        @endcan
                                                        @can('targets.delete')
                                                            @if ($target->reports_count === 0)
                                                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $target->id }})">{{ __('common.delete') }}</flux:menu.item>
                                                            @else
                                                                <flux:menu.item icon="lock-closed" disabled>{{ __('targets.targets_that_have_been_reported_against_cannot_be_deleted') }}</flux:menu.item>
                                                            @endif
                                                        @endcan
                                                    </flux:menu>
                                                </flux:dropdown>
                                            @endif
                                        </div>
                                    </div>

                                    @if ($isExpanded)
                                        <div id="target-details-{{ $target->id }}" class="mb-3 me-2 ms-6 mt-1 flex flex-col gap-4" data-test="target-details-{{ $target->id }}">
                                            {{-- Where it was committed: the plan or proposal, its program or donors, the project. --}}
                                            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-secondary-medium dark:text-ink-muted">
                                                <span class="font-semibold text-secondary-dark dark:text-ink">{{ __('targets.where_committed') }}:</span>
                                                @if ($sourceLabel !== null)
                                                    @if ($sourceUrl !== null)
                                                        <a href="{{ $sourceUrl }}" wire:navigate class="{{ $linkClass }}"><bdi>{{ $sourceLabel }}</bdi></a>
                                                    @else
                                                        <span class="font-medium text-secondary-dark dark:text-ink"><bdi>{{ $sourceLabel }}</bdi></span>
                                                    @endif
                                                    @if ($source instanceof AnnualPlan && $source->program !== null)
                                                        <span class="text-secondary-light" aria-hidden="true">/</span>
                                                        <span class="sr-only">{{ __('common.program') }}:</span>
                                                        <a href="{{ route('programs.show', $source->program) }}" wire:navigate class="{{ $linkClass }}"><bdi>{{ $source->program->name }}</bdi></a>
                                                    @elseif ($source instanceof Proposal)
                                                        <x-ui.enum-badge :case="$source->status" />
                                                        <span class="inline-flex flex-wrap items-center gap-x-1.5">
                                                            <flux:icon.banknotes variant="micro" class="size-3.5 text-funding dark:text-ink-muted" aria-hidden="true" />
                                                            <span class="sr-only">{{ __('common.donors') }}:</span>
                                                            @forelse ($source->getRelation($donorRelation) as $donor)
                                                                <span class="inline-flex items-baseline gap-1.5">
                                                                    @if ($canOpenDonors)
                                                                        <a href="{{ route('donors.show', $donor) }}" wire:navigate class="{{ $linkClass }}"><bdi>{{ $donor->name }}</bdi></a>
                                                                    @else
                                                                        <span class="font-medium text-secondary-dark dark:text-ink"><bdi>{{ $donor->name }}</bdi></span>
                                                                    @endif
                                                                    @if ($canViewBudgets && $donor->pivot?->budget !== null)
                                                                        <x-ui.money :amount="$donor->pivot->budget" :currency="$donor->pivot->currency" class="text-xs text-funding dark:text-ink-muted" />
                                                                    @endif
                                                                </span>@if (! $loop->last)<span aria-hidden="true">,</span>@endif
                                                            @empty
                                                                <span class="italic">{{ __('targets.no_donor_linked') }}</span>
                                                            @endforelse
                                                        </span>
                                                    @endif
                                                @endif
                                                <flux:icon.arrow-long-right variant="micro" class="size-3.5 text-secondary-light rtl:-scale-x-100" aria-hidden="true" />
                                                @if ($target->location === null)
                                                    <span>{{ __('common.organisation_wide') }}</span>
                                                @else
                                                    {{-- Y-16: the row names the place. The link still goes through the
                                                         row's project while projects.show binds one; Y-16 slice 2 binds
                                                         the row and this becomes route('projects.show', $target->location). --}}
                                                    @if ($target->location->project === null)
                                                        <span><bdi>{{ $target->location->label() }}</bdi></span>
                                                    @else
                                                        <a href="{{ route('projects.show', $target->location->project) }}" wire:navigate class="{{ $linkClass }}"><bdi>{{ $target->location->label() }}</bdi></a>
                                                    @endif
                                                    <span class="inline-flex items-center gap-1">
                                                        <flux:icon.map-pin variant="micro" class="size-3.5 text-secondary-light" aria-hidden="true" />
                                                        {{ $target->location->city?->name ?? __('common.unknown_city') }}
                                                    </span>
                                                @endif
                                            </p>

                                            <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs tabular-nums text-secondary-medium dark:text-ink-muted">
                                                <span>{{ trans_choice('targets.count_report', $target->reports_count, ['count' => $target->reports_count]) }}</span>
                                                @if ($achievedPercent > 100)
                                                    <span class="font-semibold text-status-good">{{ __('targets.exceeded_by', ['percent' => number_format($achievedPercent - 100)]) }}</span>
                                                @endif
                                                @if ($target->draft_count > 0)
                                                    <span>{{ trans_choice('targets.count_draft', $target->draft_count, ['count' => $target->draft_count]) }}</span>
                                                @endif
                                                @if (filled($targetIndicator->baseline))
                                                    <span dir="ltr">{{ __('targets.baseline_value', ['value' => $targetIndicator->baseline.$unitSuffix]) }}</span>
                                                @endif
                                            </p>

                                            {{-- The report trail: every measurement behind the figure, newest first. --}}
                                            @if ($this->expandedReports->isNotEmpty())
                                                <div id="target-reports-{{ $target->id }}" class="target-trail">
                                                    <ol class="flex flex-col divide-y divide-secondary-soft dark:divide-line">
                                                        @foreach ($this->expandedReports as $report)
                                                            <li wire:key="target-report-{{ $report->id }}">
                                                                <a href="{{ route('reports.show', $report) }}" wire:navigate class="group grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 rounded-md px-3 py-2.5 transition-colors hover:bg-ground focus-visible:outline-2 focus-visible:outline-secondary-medium @2xl/directory:grid-cols-[minmax(0,1fr)_auto_auto_auto] dark:hover:bg-white/[0.04]">
                                                                    <span class="flex min-w-0 flex-col text-[13px] font-medium">
                                                                        <span class="truncate">
                                                                            @if ($report->period_start && $report->period_end)
                                                                                {{ $report->period_start->translatedFormat(__('formats.date')) }} – {{ $report->period_end->translatedFormat(__('formats.date')) }}
                                                                            @else
                                                                                {{ $report->planned_date?->translatedFormat(__('formats.date')) }}
                                                                            @endif
                                                                            <span class="font-normal text-secondary-medium dark:text-ink-muted">· {{ $report->user?->name }}</span>
                                                                        </span>
                                                                        <span class="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs font-normal text-secondary-medium dark:text-ink-muted">
                                                                            <span class="inline-flex items-center gap-1">
                                                                                <flux:icon.paper-clip variant="micro" class="size-3.5" aria-hidden="true" />
                                                                                {{ trans_choice('targets.count_evidence_file', $report->files_count, ['count' => $report->files_count]) }}
                                                                            </span>
                                                                            <span @class(['inline-flex items-center gap-1', 'text-status-good' => $report->is_seen_by_meal])>
                                                                                @if ($report->is_seen_by_meal)
                                                                                    <flux:icon.eye variant="micro" class="size-3.5" aria-hidden="true" />
                                                                                    {{ __('reports.seen_by_meal') }}
                                                                                @else
                                                                                    <flux:icon.eye-slash variant="micro" class="size-3.5" aria-hidden="true" />
                                                                                    {{ __('targets.not_yet_seen_by_meal') }}
                                                                                @endif
                                                                            </span>
                                                                        </span>
                                                                    </span>
                                                                    <span class="justify-self-end text-sm font-semibold tabular-nums @2xl/directory:order-3" dir="ltr">{{ $formatValue((float) $report->value) }}{{ $unitSuffix }}</span>
                                                                    <span class="@2xl/directory:order-2"><x-ui.enum-badge :case="$report->status" /></span>
                                                                    <flux:icon.arrow-up-right variant="micro" class="hidden size-3.5 justify-self-end text-secondary-light transition-transform group-hover:-translate-y-px group-hover:translate-x-px @2xl/directory:order-4 @2xl/directory:block rtl:-scale-x-100" aria-hidden="true" />
                                                                </a>
                                                            </li>
                                                        @endforeach
                                                    </ol>
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endforeach
            </div>
        @endif

        <flux:pagination :paginator="$this->resultPage" class="pt-4 text-secondary-medium! dark:text-ink-muted!" scroll-to="#target-directory-heading" />
    </div>
