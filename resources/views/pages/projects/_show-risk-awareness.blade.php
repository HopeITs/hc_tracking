{{--
    "Risks to be aware of" on the project's Overview (W-09, D-61, Dev 1 ruling
    of 4 Nov): a read-only alert for the project's manager. It lists the risks
    picked onto the logframes of the proposals linked to this project, one
    group per proposal, then the place's own register rows (Y-13: a register
    risk carries its row's proposal too, so the groups are told apart by
    whether the risk sits on a result). Nothing is edited here: risks are added and removed on the
    proposal's Logical Framework tab, which each group links to. Included by
    _story-chapters.blade.php.
--}}
@can('viewAny', App\Models\Risk::class)
    @php
        $awareness = $this->riskAwareness;
        // Y-13 (D-93): one group per proposal logframe, then one per location
        // row of this place, named by the place itself.
        $groups = $awareness->groupBy(fn ($risk) => $risk->isOnLogframe() ? 'proposal-'.$risk->proposal_id : 'row-'.($risk->proposal_location_id ?? 0));
        $places = App\Models\ProposalLocation::placeLabels($awareness->pluck('location')->filter()->unique('id')->values());
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
                @foreach ($groups as $groupKey => $risks)
                    @php
                        $onLogframe = str_starts_with($groupKey, 'proposal-');
                        $rowId = $risks->first()->proposal_location_id;
                        $place = $onLogframe ? null : ($places[$rowId] ?? null);
                    @endphp
                    <div class="overflow-hidden rounded-lg border border-secondary-soft bg-white dark:border-line dark:bg-surface-2" wire:key="project-risk-group-{{ $groupKey }}" data-risk-group="{{ $onLogframe ? $risks->first()->proposal_id : 'register' }}">
                        <div class="flex items-center gap-2 border-b border-secondary-soft px-3 py-2 dark:border-line">
                            @if (! $onLogframe)
                                <p class="min-w-0 truncate type-label text-secondary-medium dark:text-ink-muted" dir="auto" data-test="risk-group-place">{{ $place ?? __('projects.risk_awareness_register') }}</p>
                            @else
                                <a href="{{ route('proposals.show', ['proposal' => $risks->first()->proposal_id, 'ptab' => 'logframe']) }}" wire:navigate class="min-w-0 truncate type-label font-semibold text-secondary-dark underline decoration-secondary-light underline-offset-2 hover:text-primary dark:text-ink dark:hover:text-brand-text" dir="auto">{{ $risks->first()->proposal?->name }}</a>
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
