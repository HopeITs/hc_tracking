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
            <div class="flex items-center gap-4">
                <span class="hidden items-center gap-4 text-xs text-secondary-medium @3xl/directory:inline-flex dark:text-ink-muted">
                    <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="h-1.5 w-4 rounded-full bg-secondary-dark dark:bg-ink-muted"></span>{{ __('targets.legend_approved') }}</span>
                    <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="target-pending h-1.5 w-4 rounded-full"></span>{{ __('targets.legend_pending') }}</span>
                </span>
                @if ($filtersAreActive)
                    <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-ink-muted!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
                @endif
            </div>
        </div>

        @if ($this->targets->isEmpty())
            <x-ui.empty-state
                icon="chart-bar"
                :heading="$filtersAreActive ? __('targets.no_matching_targets') : __('common.no_targets_yet')"
                :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('targets.commit_the_first_target_in_an_annual_plan_or_a_proposal')"
            />
        @else
            <div class="flex flex-col gap-10 pt-6 transition-opacity duration-200" wire:loading.class="opacity-50" wire:target="search,sourceType,project,period,donor,program,year,filterStatus,clearFilters,gotoPage,nextPage,previousPage">
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
                                <p class="max-w-4xl text-pretty text-base font-semibold leading-snug">{{ $groupResult->statement }}</p>
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
                                    $pendingAmount = (float) $target->pending_value;
                                    $rowStatus = $actualAmount <= 0 ? 'not_started' : ($actualAmount >= $targetAmount ? 'achieved' : 'in_progress');
                                    $achievedPercent = $targetAmount > 0 ? $actualAmount / $targetAmount * 100 : ($actualAmount > 0 ? 100 : 0);
                                    $approvedWidth = min(100, $achievedPercent);
                                    $pendingWidth = $targetAmount > 0 ? max(0, min(100 - $approvedWidth, $pendingAmount / $targetAmount * 100)) : 0;
                                    $isExpanded = $expandedTargetId === $target->id;
                                    $chainIssues = $sourceLabel === null ? [] : $target->chainIssues();
                                    $linkClass = 'rounded-sm font-medium text-secondary-dark underline decoration-secondary-soft decoration-1 underline-offset-[3px] transition-colors hover:decoration-current focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-ink dark:decoration-line-strong';
                                @endphp
                                <li class="relative" wire:key="target-{{ $target->id }}">
                                    <span aria-hidden="true" class="absolute top-[1.4rem] -start-[5.5px] z-10 size-2.5 rounded-full ring-4 ring-white {{ $statusMeta[$rowStatus]['dot'] }} dark:ring-surface-1"></span>

                                    <div @class([
                                        'grid gap-4 rounded-e-lg py-4 ps-6 pe-2 transition-colors duration-150 @4xl/directory:grid-cols-[minmax(0,1fr)_minmax(16rem,20rem)_auto] @4xl/directory:items-center @4xl/directory:gap-10',
                                        'bg-ground dark:bg-white/[0.04]' => $isExpanded,
                                        'hover:bg-ground dark:hover:bg-white/[0.04]' => ! $isExpanded,
                                    ])>
                                        <div class="flex min-w-0 flex-col gap-2">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <a href="{{ route('indicators.index', ['inspect' => $targetIndicator->id]) }}" wire:navigate class="text-[15px] font-semibold leading-snug hover:text-primary dark:hover:text-brand-text hover:underline">{{ $targetIndicator->name }}</a>
                                                <x-ui.enum-badge :case="$target->period" />
                                            </div>

                                            {{-- A row saved before the chain rules existed, or changed around them. --}}
                                            @foreach ($chainIssues as $issue)
                                                <p class="flex items-start gap-1.5 text-xs font-semibold text-primary dark:text-brand-text" data-test="chain-issue-{{ $target->id }}">
                                                    <flux:icon.link-slash variant="micro" class="mt-px size-3.5 shrink-0" aria-hidden="true" />
                                                    {{ __($issue) }}
                                                </p>
                                            @endforeach

                                            {{-- Lineage: where it was committed, the money or plan behind it, then who delivers it. --}}
                                            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-secondary-medium dark:text-ink-muted">
                                                {{-- Rendered from the morph map key, never a class name. --}}
                                                <span @class(['inline-flex items-center gap-1 font-medium', 'text-funding dark:text-ink-muted' => $isProposal])>
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
                                                    @if ($sourceUrl !== null)
                                                        <a href="{{ $sourceUrl }}" wire:navigate class="{{ $linkClass }}">{{ $sourceLabel }}</a>
                                                    @else
                                                        <span class="font-medium text-secondary-dark dark:text-ink">{{ $sourceLabel }}</span>
                                                    @endif
                                                    @if ($source instanceof AnnualPlan && $source->program !== null)
                                                        <span class="text-secondary-light" aria-hidden="true">/</span>
                                                        <span class="sr-only">{{ __('common.program') }}:</span>
                                                        <a href="{{ route('programs.show', $source->program) }}" wire:navigate class="{{ $linkClass }}">{{ $source->program->name }}</a>
                                                    @elseif ($source instanceof Proposal)
                                                        <x-ui.enum-badge :case="$source->status" />
                                                        <span class="inline-flex flex-wrap items-center gap-x-1.5">
                                                            <flux:icon.banknotes variant="micro" class="size-3.5 text-funding dark:text-ink-muted" aria-hidden="true" />
                                                            <span class="sr-only">{{ __('common.donors') }}:</span>
                                                            @forelse ($source->getRelation($donorRelation) as $donor)
                                                                <span class="inline-flex items-baseline gap-1.5">
                                                                    @if ($canOpenDonors)
                                                                        <a href="{{ route('donors.show', $donor) }}" wire:navigate class="{{ $linkClass }}">{{ $donor->name }}</a>
                                                                    @else
                                                                        <span class="font-medium text-secondary-dark dark:text-ink">{{ $donor->name }}</span>
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
                                                @if ($target->project === null)
                                                    <span>{{ __('common.organisation_wide') }}</span>
                                                @else
                                                    <a href="{{ route('projects.show', $target->project) }}" wire:navigate class="{{ $linkClass }}">{{ $target->project->name }}</a>
                                                    <span class="inline-flex items-center gap-1">
                                                        <flux:icon.map-pin variant="micro" class="size-3.5 text-secondary-light" aria-hidden="true" />
                                                        {{ $target->project->city?->name ?? __('common.unknown_city') }}
                                                    </span>
                                                @endif
                                            </p>
                                        </div>

                                        {{-- Achievement: the figure first, then the bar that explains it. --}}
                                        <div class="flex flex-col gap-2">
                                            <div class="flex items-baseline justify-between gap-3">
                                                <span class="text-3xl font-bold leading-none tracking-tight tabular-nums {{ $statusMeta[$rowStatus]['text'] }}">{{ number_format($achievedPercent) }}<span class="text-lg font-semibold">%</span></span>
                                                <span class="min-w-0 text-end text-[13px] tabular-nums text-secondary-medium dark:text-ink-muted">
                                                    <span class="font-semibold text-secondary-dark dark:text-ink">{{ $formatValue($actualAmount) }}{{ $unitSuffix }}</span>
                                                    {{ __('targets.of_target', ['target' => $formatValue($targetAmount).$unitSuffix]) }}
                                                    @if ($unitWord !== null)
                                                        <span class="break-words">{{ $unitWord }}</span>
                                                    @endif
                                                </span>
                                            </div>
                                            <div class="flex h-2 w-full overflow-hidden rounded-full bg-secondary-soft/60 dark:bg-surface-2" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round($approvedWidth) }}" aria-label="{{ __('targets.achievement_of', ['indicator' => $targetIndicator->name]) }}">
                                                <span class="target-bar h-full rounded-full {{ $statusMeta[$rowStatus]['fill'] }}" style="width: {{ $approvedWidth }}%"></span>
                                                @if ($pendingWidth > 0)
                                                    <span class="target-pending h-full" style="width: {{ $pendingWidth }}%"></span>
                                                @endif
                                            </div>
                                            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs text-secondary-medium dark:text-ink-muted">
                                                @if ($target->reports_count > 0)
                                                    <button
                                                        type="button"
                                                        wire:click="toggleReports({{ $target->id }})"
                                                        aria-expanded="{{ $isExpanded ? 'true' : 'false' }}"
                                                        aria-controls="target-reports-{{ $target->id }}"
                                                        data-test="toggle-reports-{{ $target->id }}"
                                                        class="-mx-1 inline-flex min-h-7 cursor-pointer items-center gap-1 rounded-md px-1 font-medium tabular-nums text-secondary-dark transition-colors hover:bg-secondary-soft/60 focus-visible:outline-2 focus-visible:outline-primary dark:text-ink dark:hover:bg-white/10"
                                                    >
                                                        {{ trans_choice('targets.count_report', $target->reports_count, ['count' => $target->reports_count]) }}
                                                        <flux:icon.chevron-down variant="micro" @class(['size-3.5 transition-transform duration-200', 'rotate-180' => $isExpanded]) aria-hidden="true" />
                                                    </button>
                                                @else
                                                    <span>{{ trans_choice('targets.count_report', 0, ['count' => 0]) }}</span>
                                                @endif

                                                <span class="flex flex-wrap items-center gap-x-3 gap-y-1 tabular-nums">
                                                    @if ($achievedPercent > 100)
                                                        <span class="font-semibold text-status-good">{{ __('targets.exceeded_by', ['percent' => number_format($achievedPercent - 100)]) }}</span>
                                                    @endif
                                                    @if ($target->reviewed_count > 0)
                                                        <span>{{ trans_choice('targets.count_awaiting_approval', $target->reviewed_count, ['count' => $target->reviewed_count]) }}</span>
                                                    @endif
                                                    @if ($target->draft_count > 0)
                                                        <span>{{ trans_choice('targets.count_draft', $target->draft_count, ['count' => $target->draft_count]) }}</span>
                                                    @endif
                                                    @if (filled($targetIndicator->baseline))
                                                        <span>{{ __('targets.baseline_value', ['value' => $targetIndicator->baseline.$unitSuffix]) }}</span>
                                                    @endif
                                                </span>
                                            </div>
                                        </div>

                                        <div class="flex items-center gap-1 justify-self-end">
                                            @if ($sourceLabel !== null)
                                                <flux:tooltip :content="__('targets.record_a_report')">
                                                    <x-ui.button tone="ghost" size="sm" icon="document-plus" class="size-9!" :href="route('reports.index', ['create' => 1, 'target' => $target->id])" wire:navigate :aria-label="__('targets.record_a_report_for', ['indicator' => $targetIndicator->name])" data-test="record-report-{{ $target->id }}" />
                                                </flux:tooltip>
                                            @endif
                                            @can('targets.update')<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $target->id }})" :aria-label="__('targets.edit_target')" />@endcan

                                            @if ($target->reports_count === 0)
                                                @can('targets.delete')<x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDelete({{ $target->id }})" :aria-label="__('targets.delete_target')" />@endcan
                                            @else
                                                <flux:tooltip :content="__('targets.targets_that_have_been_reported_against_cannot_be_deleted')">
                                                    <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                                        <flux:icon.lock-closed class="size-4" />
                                                    </span>
                                                </flux:tooltip>
                                            @endif
                                        </div>
                                    </div>

                                    @if ($isExpanded)
                                        {{-- The report trail: every measurement behind the figure, newest first. --}}
                                        <div id="target-reports-{{ $target->id }}" class="target-trail ms-6 me-2 mb-3 mt-1">
                                            <ol class="flex flex-col divide-y divide-secondary-soft dark:divide-line">
                                                @foreach ($this->expandedReports as $report)
                                                    <li wire:key="target-report-{{ $report->id }}">
                                                        <a href="{{ route('reports.show', $report) }}" wire:navigate class="group grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 rounded-md px-3 py-2.5 transition-colors hover:bg-ground focus-visible:outline-2 focus-visible:outline-primary @2xl/directory:grid-cols-[minmax(0,1fr)_auto_auto_auto] dark:hover:bg-white/[0.04]">
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
                                                            <span class="justify-self-end text-sm font-semibold tabular-nums @2xl/directory:order-3">{{ $formatValue((float) $report->value) }}{{ $unitSuffix }}</span>
                                                            <span class="@2xl/directory:order-2"><x-ui.enum-badge :case="$report->status" /></span>
                                                            <flux:icon.arrow-up-right variant="micro" class="hidden size-3.5 justify-self-end text-secondary-light transition-transform group-hover:-translate-y-px group-hover:translate-x-px @2xl/directory:order-4 @2xl/directory:block rtl:-scale-x-100" aria-hidden="true" />
                                                        </a>
                                                    </li>
                                                @endforeach
                                            </ol>
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
