        <x-ui.panel :title="__('projects.linked_proposals')" :count="$this->attachedProposals->count()" :subtitle="__('projects.a_project_may_be_funded_by_several_proposals_and_a_proposal')">
            <x-slot:toolbar>
                @can('update', $this->project)
                <div class="flex w-full flex-col items-stretch gap-2 sm:w-auto sm:flex-row sm:items-end">
                    <x-ui.select :label="__('projects.link_a_proposal')" wire:model="proposalToAttach" class="min-w-56">
                        <flux:select.option value="">{{ __('common.select_a_proposal') }}</flux:select.option>
                        @foreach ($this->attachableProposals as $proposalOption)
                            <flux:select.option :value="$proposalOption->id" :wire:key="'attachable-'.$proposalOption->id">{{ $proposalOption->name }} · {{ $proposalOption->internal_project_number }}</flux:select.option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.button tone="primary" size="sm" icon="link" class="min-h-10" wire:click="attachProposal">{{ __('projects.link') }}</x-ui.button>
                </div>
                @endcan
            </x-slot:toolbar>

            @if ($this->attachedProposals->isEmpty())
                <x-ui.empty-state icon="document-text" :heading="__('projects.no_proposals_linked')" :body="__('projects.link_a_proposal_to_record_which_funding_covers_this_project')" />
            @else
                @php $summary = $this->proposalsSummary; @endphp
                <div @class(['grid gap-3 border-b border-secondary-soft p-3.5 dark:border-line', '@lg/detail:grid-cols-3' => $this->canViewMoney()])>
                    @if ($this->canViewMoney())
                        <x-ui.stat-tile :label="__('projects.stat_donors_funding')" :value="$summary['donors']" icon="building-library" />
                        <x-ui.stat-tile :label="__('projects.stat_funding_agreements')" :value="$summary['agreements']" icon="document-check" />
                    @endif
                    <x-ui.stat-tile :label="__('projects.stat_proposals_underway')" :value="$summary['underway']" icon="signal" :tone="$summary['underway'] > 0 ? 'good' : null" />
                </div>

                <div class="grid gap-4 p-3.5 @3xl/detail:grid-cols-2 @3xl/detail:p-5">
                    @foreach ($this->attachedProposals as $proposal)
                        <article
                            class="group flex flex-col gap-3.5 rounded-xl border border-secondary-soft bg-white p-4 transition-all hover:-translate-y-0.5 hover:shadow-card-hover dark:border-transparent dark:bg-surface-1 dark:hover:shadow-raised"
                            wire:key="project-proposal-{{ $proposal->id }}"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    @if ($this->canViewProposals())
                                        <a href="{{ route('proposals.show', $proposal) }}" wire:navigate class="truncate text-base font-bold text-secondary-dark hover:underline dark:text-ink">{{ $proposal->name }}</a>
                                    @else
                                        <p class="truncate text-base font-bold text-secondary-dark dark:text-ink">{{ $proposal->name }}</p>
                                    @endif
                                    <x-ui.project-number :number="$proposal->internal_project_number" kind="internal" class="mt-1 text-xs text-secondary-medium dark:text-ink-muted" />
                                    <p class="mt-1 flex items-center gap-1.5 text-xs text-secondary-medium dark:text-ink-muted">
                                        <flux:icon name="calendar-days" variant="micro" class="size-3.5 shrink-0" />
                                        <span class="tabular-nums">{{ $proposal->planned_date_begin?->translatedFormat(__('formats.date')) }} – {{ $proposal->planned_date_end?->translatedFormat(__('formats.date')) }}</span>
                                    </p>
                                </div>
                                @can('update', $this->project)
                                    <x-ui.button
                                        tone="ghost"
                                        size="sm"
                                        icon="x-mark"
                                        class="size-8! shrink-0 opacity-0 transition-opacity focus-visible:opacity-100 group-hover:opacity-100"
                                        wire:click="detachProposal({{ $proposal->id }})"
                                        :aria-label="__('projects.unlink_name', ['name' => $proposal->name])"
                                    />
                                @endcan
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.enum-badge :case="$proposal->status" />
                                <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-ink-muted">
                                    {{ $proposal->phase->label() }}
                                </span>
                            </div>

                            @if ($proposal->donorNames->isEmpty())
                                <p class="type-meta border-t border-secondary-soft pt-3 italic text-secondary-medium dark:border-line dark:text-ink-muted">
                                    {{ __('projects.no_funding_agreement_recorded_yet') }}
                                </p>
                            @else
                                <div class="flex flex-col gap-3 border-t border-secondary-soft pt-3 dark:border-line">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @foreach ($proposal->donorNames->unique('id') as $donor)
                                            <span class="inline-flex items-center gap-1 rounded-full border border-secondary-light bg-board px-2 py-0.5 text-[11px] font-semibold text-secondary-dark dark:border-line dark:text-ink" wire:key="proposal-{{ $proposal->id }}-donor-{{ $donor->id }}">
                                                <flux:icon name="building-library" variant="micro" class="size-3 shrink-0 text-secondary-medium dark:text-ink-muted" />
                                                {{ $donor->name }}
                                            </span>
                                        @endforeach
                                    </div>

                                    <div class="flex flex-col gap-2.5">
                                        @foreach ($this->proposalFunding($proposal) as $row)
                                            <x-ui.capacity-bar :committed="$row['committed']" :used="$row['received']" :currency="$row['currency']" wire:key="proposal-{{ $proposal->id }}-funding-{{ $row['currency']->value }}" />
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
