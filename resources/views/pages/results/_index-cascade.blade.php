{{-- The chain of statements, one panel per program and one per proposal framework. Shown when the page's view is cascade. --}}
@php
    $proposalStoryHrefs = [];
    $groupProposalIds = collect($this->groupedRoots)->map(fn (array $nodes): ?int => $nodes[0]['result']->proposal?->id)->filter()->unique()->values();
    if ($groupProposalIds->isNotEmpty() && auth()->user()?->can('projects.view') === true) {
        $storyLinks = DB::table('project_proposal')->whereIn('proposal_id', $groupProposalIds)->orderBy('project_id')->get(['proposal_id', 'project_id']);
        $storyProjects = \App\Models\Project::query()->select(['id', 'slug'])->whereIn('id', $storyLinks->pluck('project_id')->unique())->get()->keyBy('id');
        foreach ($storyLinks as $storyLink) {
            $storyProject = $storyProjects->get($storyLink->project_id);
            if (! isset($proposalStoryHrefs[$storyLink->proposal_id]) && $storyProject !== null && auth()->user()->can('view', $storyProject)) {
                $proposalStoryHrefs[$storyLink->proposal_id] = route('projects.show', ['project' => $storyProject, 'proposal' => $storyLink->proposal_id, 'ptab' => 'logframe']).'#proposal';
            }
        }
    }
@endphp
        @foreach ($this->groupedRoots as $programName => $nodes)
            @php
                $groupStatements = collect($nodes)->sum(fn (array $node): int => $node['rollup']['statements']);
                $groupIndicators = collect($nodes)->sum(fn (array $node): int => $node['rollup']['indicators']);
                $groupTargets = collect($nodes)->sum(fn (array $node): int => $node['rollup']['targets']);
                $groupUnmeasured = collect($nodes)->sum(fn (array $node): int => $node['rollup']['unmeasured']);
                $groupProposal = $nodes[0]['result']->proposal;
                $groupProgram = $groupProposal === null ? collect($nodes)->map(fn (array $node) => $node['result']->program)->filter()->first() : null;
                $groupProposalHref = $groupProposal === null ? null : ($proposalStoryHrefs[$groupProposal->id] ?? route('proposals.show', ['proposal' => $groupProposal, 'tab' => 'logframe']));
            @endphp
            <x-ui.panel
                :title="$programName"
                :count="$groupStatements"
                :subtitle="trans_choice('programs.logframe_count_indicators', $groupIndicators, ['count' => $groupIndicators]).' · '.trans_choice('common.count_target', $groupTargets, ['count' => $groupTargets])"
                wire:key="result-group-{{ $loop->index }}-{{ Str::slug($programName) }}"
            >
                <x-slot:toolbar>
                    <div class="flex flex-wrap items-center gap-3">
                        <span @class([
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 type-meta font-semibold',
                            'bg-status-warn-soft text-status-warn' => $groupUnmeasured > 0,
                            'bg-status-good-soft text-status-good' => $groupUnmeasured === 0,
                        ])>
                            <flux:icon :name="$groupUnmeasured > 0 ? 'exclamation-triangle' : 'check-circle'" variant="micro" class="size-3.5" aria-hidden="true" />
                            {{ trans_choice('programs.logframe_count_unmeasured', $groupUnmeasured, ['count' => $groupUnmeasured]) }}
                        </span>

                        @if ($groupProposal !== null)
                            @if (isset($proposalStoryHrefs[$groupProposal->id]) || auth()->user()->can('proposals.view'))<a href="{{ $groupProposalHref }}" wire:navigate class="type-meta font-semibold text-secondary-medium hover:text-primary dark:hover:text-brand-text hover:underline dark:text-ink-muted" data-test="proposal-framework-link-{{ $groupProposal->id }}">
                                {{ __('common.view_name', ['name' => $groupProposal->name]) }}
                            </a>@endif
                        @elseif ($groupProgram !== null)
                            <a href="{{ route('programs.show', $groupProgram) }}" wire:navigate class="type-meta font-semibold text-secondary-medium hover:text-primary dark:hover:text-brand-text hover:underline dark:text-ink-muted">
                                {{ __('common.view_name', ['name' => $groupProgram->name]) }}
                            </a>
                        @endif
                    </div>
                </x-slot:toolbar>

                <div class="space-y-5 bg-ground px-3 py-5 @2xl/directory:px-5 @2xl/directory:py-6">
                    @foreach ($nodes as $node)
                        <x-ui.framework-node :node="$node" :depth="0" :order="$loop->index" wire:key="framework-root-{{ $node['result']->id }}" />
                    @endforeach
                </div>
            </x-ui.panel>
        @endforeach
