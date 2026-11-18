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

    /*
     * A program's own framework stays general (no proposal needed). The proposals of the
     * program's projects show beside it as links to their Logical Framework tab.
     */
    $programIds = collect($this->groupedRoots)
        ->map(fn (array $nodes): ?int => $nodes[0]['result']->proposal_id === null ? $nodes[0]['result']->program_id : null)
        ->filter()->unique()->values();
    $programProposals = [];
    $mayViewProposals = auth()->user()?->can('proposals.view') === true;
    $mayViewProjects = auth()->user()?->can('projects.view') === true;
    if ($programIds->isNotEmpty() && ($mayViewProposals || $mayViewProjects)) {
        $linked = \App\Models\Proposal::query()
            ->select(['proposals.id', 'proposals.slug', 'proposals.name'])
            ->where('proposals.kind', \App\Enums\ProposalKind::Project)
            ->whereHas('projects', fn ($projects) => $projects->whereIn('projects.program_id', $programIds))
            ->with(['projects' => fn ($projects) => $projects->select(['projects.id', 'projects.slug', 'projects.program_id'])->whereIn('projects.program_id', $programIds)])
            ->orderBy('proposals.name')
            ->get();
        foreach ($linked as $linkedProposal) {
            $linkedProject = $linkedProposal->projects->first();
            $href = $mayViewProjects && $linkedProject !== null && auth()->user()->can('view', $linkedProject)
                ? route('projects.show', ['project' => $linkedProject, 'proposal' => $linkedProposal->id, 'ptab' => 'logframe']).'#proposal'
                : ($mayViewProposals ? route('proposals.show', ['proposal' => $linkedProposal, 'ptab' => 'logframe']) : null);
            if ($href === null) {
                continue;
            }
            foreach ($linkedProposal->projects->pluck('program_id')->unique() as $linkedProgramId) {
                $programProposals[$linkedProgramId][] = ['id' => $linkedProposal->id, 'name' => $linkedProposal->name, 'href' => $href];
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

                @php
                    $freeActivityCount = collect($nodes)->filter(fn (array $node): bool => $node['result']->type === \App\Enums\ResultType::Activity)->count();
                    $canAddFreeActivity = $groupProposal === null && auth()->user()->can('results.create');
                    $freeActivityHeadingShown = false;
                @endphp
                @if ($groupProposal === null && $groupProgram !== null)
                    @php
                        $proposalLinks = $programProposals[$groupProgram->id] ?? [];
                    @endphp
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1.5 border-b border-secondary-soft px-4 py-2.5 dark:border-line" x-data="{ all: false }" data-test="program-proposal-links-{{ $groupProgram->id }}">
                        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('results_framework.program_proposals') }}</span>
                        @forelse ($proposalLinks as $proposalLink)
                            <a href="{{ $proposalLink['href'] }}" wire:navigate @if ($loop->index >= 4) x-show="all" x-cloak @endif class="inline-flex items-center gap-1 rounded-full border border-secondary-light px-2.5 py-0.5 type-meta font-semibold text-secondary-dark hover:border-primary hover:text-primary dark:border-line-strong dark:text-ink dark:hover:text-brand-text" data-test="program-proposal-link-{{ $proposalLink['id'] }}" dir="auto">
                                <flux:icon.arrow-top-right-on-square variant="micro" class="size-3 rtl:-scale-x-100" aria-hidden="true" />{{ $proposalLink['name'] }}
                            </a>
                        @empty
                            <span class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('results_framework.program_proposals_none') }}</span>
                        @endforelse
                        @if (count($proposalLinks) > 4)
                            <button type="button" class="type-meta font-semibold text-secondary-medium hover:text-primary dark:text-ink-muted" x-on:click="all = ! all" x-text="all ? @js(__('results_framework.program_proposals_fewer')) : @js(__('results_framework.program_proposals_more', ['count' => count($proposalLinks) - 4]))"></button>
                        @endif
                    </div>
                @endif
                <div class="space-y-5 bg-ground px-3 py-5 @2xl/directory:px-5 @2xl/directory:py-6">
                    @foreach ($nodes as $node)
                        {{-- Free activities (no statement above them) follow the chain under their own heading. --}}
                        @if ($node['result']->type === \App\Enums\ResultType::Activity && ! $freeActivityHeadingShown)
                            @php $freeActivityHeadingShown = true; @endphp
                            <h3 class="type-label text-secondary-medium dark:text-ink-muted" data-test="free-activities-heading">{{ __('results_framework.free_activities') }}</h3>
                        @endif
                        <x-ui.framework-node :node="$node" :depth="0" :order="$loop->index" wire:key="framework-root-{{ $node['result']->id }}" />
                    @endforeach

                    @if ($canAddFreeActivity)
                        <div class="flex flex-wrap items-center gap-2 border-t border-dashed border-secondary-soft pt-4 dark:border-line">
                            @if ($freeActivityCount === 0)
                                <span class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('results_framework.free_activities_empty') }}</span>
                            @endif
                            <x-ui.button tone="ghost" size="sm" icon="plus" class="ms-auto" wire:click="openCreateActivity({{ $groupProgram?->id ?? 'null' }})" data-test="add-free-activity-{{ $groupProgram?->id ?? 'none' }}">{{ __('results_framework.add_free_activity') }}</x-ui.button>
                        </div>
                    @endif
                </div>
            </x-ui.panel>
        @endforeach
