{{--
    "Risks to be aware of" on the project's Overview (W-09, D-61, Dev 1 ruling
    of 4 Nov): a read-only alert for the project's manager. It lists the risks
    picked onto the logframes of the proposals linked to this project, one
    group per proposal, then the older register rows logged against the
    project itself. Nothing is edited here: risks are added and removed on the
    proposal's Logical Framework tab, which each group links to. Included by
    _story-chapters.blade.php.
--}}
@can('viewAny', App\Models\Risk::class)
    @php
        $awareness = $this->riskAwareness;
        $groups = $awareness->groupBy(fn ($risk) => $risk->proposal_id ?? 0);
    @endphp

    @if ($awareness->isNotEmpty())
        <section class="mb-4 rounded-xl border border-status-warn/40 bg-status-warn-soft/40 p-4 dark:border-line-strong dark:bg-surface-1" aria-labelledby="risk-awareness-heading" role="region" data-test="risk-awareness">
            <div class="flex flex-wrap items-center gap-2">
                <flux:icon.exclamation-triangle variant="micro" class="size-4 text-status-warn" aria-hidden="true" />
                <h3 id="risk-awareness-heading" class="type-label text-secondary-dark dark:text-ink">{{ __('projects.risk_awareness_title') }}</h3>
                <span class="ms-auto text-xs font-semibold tabular-nums text-secondary-dark dark:text-ink">{{ trans_choice('risks.node_risk_count', $awareness->count(), ['count' => $awareness->count()]) }}</span>
            </div>
            <p class="mt-1 type-meta text-secondary-medium dark:text-ink-muted">{{ __('projects.risk_awareness_note') }}</p>

            <div class="mt-3 grid gap-3 @lg/detail:grid-cols-2">
                @foreach ($groups as $proposalId => $risks)
                    <div class="overflow-hidden rounded-lg border border-secondary-soft bg-white dark:border-line dark:bg-surface-2" wire:key="project-risk-group-{{ $proposalId }}" data-risk-group="{{ $proposalId === 0 ? 'register' : $proposalId }}">
                        <div class="flex items-center gap-2 border-b border-secondary-soft px-3 py-2 dark:border-line">
                            @if ($proposalId === 0)
                                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('projects.risk_awareness_register') }}</p>
                            @else
                                <a href="{{ route('proposals.show', ['proposal' => $proposalId, 'ptab' => 'logframe']) }}" wire:navigate class="min-w-0 truncate type-label font-semibold text-secondary-dark underline decoration-secondary-light underline-offset-2 hover:text-primary dark:text-ink dark:hover:text-brand-text" dir="auto">{{ $risks->first()->proposal?->name }}</a>
                            @endif
                            <span class="ms-auto text-xs font-semibold tabular-nums text-secondary-medium dark:text-ink-muted">{{ $risks->count() }}</span>
                        </div>
                        <ul class="divide-y divide-secondary-soft dark:divide-line">
                            @foreach ($risks as $risk)
                                <li class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-2" wire:key="project-risk-{{ $risk->id }}" data-risk="{{ $risk->id }}">
                                    <span class="min-w-0 flex-1 basis-36 text-sm font-semibold text-secondary-dark dark:text-ink" dir="auto">{{ $risk->localizedName() }}</span>
                                    @if ($risk->happened)
                                        <span class="inline-flex items-center gap-1 rounded-md border border-status-critical bg-status-critical-soft px-2 py-0.5 text-xs font-semibold text-status-critical">
                                            <flux:icon.exclamation-triangle variant="micro" class="size-3.5" aria-hidden="true" />{{ __('common.materialised') }}
                                        </span>
                                    @endif
                                    <x-ui.risk-rating kind="likelihood" :value="$risk->likelihood" />
                                    <x-ui.risk-rating kind="severity" :value="$risk->severity" />
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
@endcan
