<?php

use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Risk;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Project')] class extends Component {
    public int $projectId;

    #[Url(except: 'milestones')]
    public string $tab = 'milestones';

    public string $proposalToAttach = '';

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()
            ->select([
                'id',
                'name',
                'description',
                'status',
                'start_date',
                'end_date',
                'target_beneficiaries',
                'target_men',
                'target_women',
                'target_boys',
                'target_girls',
                'city_id',
                'program_id',
                'user_id',
                'updated_at',
            ])
            ->with([
                'city:id,name',
                'program:id,name,strategy_id',
                'program.strategy:id,name',
                'user:id,name,email,role',
                'media' => fn ($query) => $query
                    ->select(['id', 'mediable_type', 'mediable_id', 'collection', 'original_name', 'type', 'uploaded_at'])
                    ->orderByDesc('uploaded_at')
                    ->orderByDesc('id'),
            ])
            ->withCount(['risks', 'milestones', 'planIndicators', 'proposals', 'media'])
            ->findOrFail($this->projectId);
    }

    /** @return Collection<int, Milestone> */
    #[Computed]
    public function milestoneRoots(): Collection
    {
        return Milestone::query()
            ->select(['id', 'project_id', 'name', 'planned_date', 'actual_date', 'status', 'sequence', 'weight_percentage', 'responsible_user_id', 'parent_milestone_id'])
            ->where('project_id', $this->projectId)
            ->roots()
            ->with('childrenRecursive')
            ->get();
    }

    /** @return Collection<int, Risk> */
    #[Computed]
    public function risks(): Collection
    {
        return Risk::query()
            ->select(['id', 'name', 'impact', 'solution', 'happened', 'project_id'])
            ->where('project_id', $this->projectId)
            ->orderByDesc('happened')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, PlanIndicator> */
    #[Computed]
    public function targets(): Collection
    {
        return PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period'])
            ->where('project_id', $this->projectId)
            ->with(['indicator:id,name,result_id,measure', 'indicator.result:id,statement,type'])
            ->withCount('reports')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Proposal> */
    #[Computed]
    public function attachedProposals(): Collection
    {
        return $this->project->proposals()
            ->select(['proposals.id', 'proposals.name', 'proposals.status', 'proposals.planned_date_begin', 'proposals.planned_date_end'])
            ->orderBy('proposals.name')
            ->orderBy('proposals.id')
            ->get();
    }

    /** @return Collection<int, Proposal> */
    #[Computed]
    public function attachableProposals(): Collection
    {
        return Proposal::query()
            ->select(['id', 'name', 'status'])
            ->whereDoesntHave('projects', fn ($query) => $query->whereKey($this->projectId))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function attachProposal(): void
    {
        $validated = $this->validate([
            'proposalToAttach' => ['required', 'integer', Rule::exists(Proposal::class, 'id')],
        ], [
            'proposalToAttach.required' => __('Select a proposal to link.'),
            'proposalToAttach.exists' => __('The selected proposal is no longer available.'),
        ]);

        $this->project->proposals()->syncWithoutDetaching([(int) $validated['proposalToAttach']]);

        $this->proposalToAttach = '';
        unset($this->project, $this->attachedProposals, $this->attachableProposals);

        Flux::toast(variant: 'success', text: __('Proposal linked to this project.'));
    }

    public function detachProposal(int $proposalId): void
    {
        $this->project->proposals()->detach($proposalId);

        unset($this->project, $this->attachedProposals, $this->attachableProposals);

        Flux::toast(variant: 'success', text: __('Proposal unlinked from this project.'));
    }

    /** @return array<int, array{key: string, label: string, count: int}> */
    #[Computed]
    public function tabs(): array
    {
        return [
            ['key' => 'milestones', 'label' => __('Milestones'), 'count' => $this->project->milestones_count],
            ['key' => 'risks', 'label' => __('Risks'), 'count' => $this->project->risks_count],
            ['key' => 'targets', 'label' => __('Targets'), 'count' => $this->project->plan_indicators_count],
            ['key' => 'proposals', 'label' => __('Proposals'), 'count' => $this->project->proposals_count],
            ['key' => 'files', 'label' => __('Files'), 'count' => $this->project->media_count],
        ];
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('Projects'), 'url' => route('projects.index')],
            ['label' => $this->project->name],
        ]"
        :title="$this->project->name"
        :subtitle="$this->project->city->name"
    >
        <x-slot:meta>
            <a href="{{ route('programs.show', $this->project->program) }}" wire:navigate class="font-medium hover:underline">{{ $this->project->program->name }}</a>
            <span>{{ $this->project->program->strategy->name }}</span>
            <span>{{ __('Updated :date', ['date' => $this->project->updated_at?->format('M j, Y')]) }}</span>
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.enum-badge :case="$this->project->status" />
                <x-ui.user-chip :user="$this->project->user" class="rounded-lg border border-secondary-soft p-2.5 dark:border-secondary-medium" />
            </div>
        </x-slot:actions>
    </x-ui.detail-header>

    <div class="grid gap-3 @2xl/detail:grid-cols-2 @5xl/detail:grid-cols-4">
        <x-ui.stat-tile :label="__('Start date')" :value="$this->project->start_date?->format('M j, Y') ?? '—'" />
        <x-ui.stat-tile :label="__('End date')" :value="$this->project->end_date?->format('M j, Y') ?? '—'" />
        <x-ui.stat-tile :label="__('Headline target')" :value="$this->project->target_beneficiaries === null ? '—' : number_format($this->project->target_beneficiaries)" />
        <x-ui.stat-tile :label="__('Demographic total')" :value="$this->project->targetDemographicTotal() === null ? '—' : number_format($this->project->targetDemographicTotal())" />
    </div>

    <div class="grid gap-4 @4xl/detail:grid-cols-[1.4fr_1fr]">
        <x-ui.panel :title="__('Description')">
            @if ($this->project->description === null)
                <p class="p-3.5 text-sm italic text-secondary-medium dark:text-secondary-soft">{{ __('No description recorded.') }}</p>
            @else
                <p class="p-3.5 text-sm leading-6 text-secondary-medium dark:text-secondary-soft">{{ $this->project->description }}</p>
            @endif
        </x-ui.panel>

        <x-ui.panel :title="__('Beneficiary breakdown')" :subtitle="__('Reported separately from the headline target.')">
            <dl class="grid grid-cols-2 gap-px bg-secondary-soft dark:bg-secondary-medium">
                @foreach ([__('Men') => $this->project->target_men, __('Women') => $this->project->target_women, __('Boys') => $this->project->target_boys, __('Girls') => $this->project->target_girls] as $label => $value)
                    <div class="bg-white p-3 dark:bg-secondary-dark" wire:key="project-demographic-{{ strtolower($label) }}">
                        <dt class="text-xs font-semibold tracking-wide text-secondary-medium uppercase dark:text-secondary-soft">{{ $label }}</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums">{{ $value === null ? '—' : number_format($value) }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.panel>
    </div>

    <x-ui.relation-tabs :tabs="$this->tabs" :active="$tab" wire-target="tab" />

    @if ($tab === 'milestones')
        <x-ui.panel :title="__('Milestones')" :count="$this->project->milestones_count" :subtitle="__('Nested as recorded, ordered by sequence.')">
            @if ($this->milestoneRoots->isEmpty())
                <x-ui.empty-state icon="flag" :heading="__('No milestones yet')" :body="__('Milestones planned for this project will appear here.')" />
            @else
                <x-ui.tree-rows
                    :nodes="$this->milestoneRoots"
                    :label="fn (\App\Models\Milestone $milestone): string => $milestone->name"
                    :badge="fn (\App\Models\Milestone $milestone) => $milestone->status"
                />
            @endif
        </x-ui.panel>
    @elseif ($tab === 'risks')
        <x-ui.panel :title="__('Risk register')" :count="$this->risks->count()" :subtitle="__('Risks that have materialised are listed first.')">
            @if ($this->risks->isEmpty())
                <x-ui.empty-state icon="exclamation-triangle" :heading="__('No risks logged')" :body="__('Risks logged against this project will appear here.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->risks as $risk)
                        <article class="flex flex-col gap-1.5 p-3.5" wire:key="project-risk-{{ $risk->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <p class="min-w-0 text-sm font-semibold">{{ $risk->name }}</p>
                                @if ($risk->happened)
                                    <span class="shrink-0 rounded-md border border-primary bg-primary px-2 py-0.5 text-xs font-semibold text-white">{{ __('Materialised') }}</span>
                                @else
                                    <span class="shrink-0 rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">{{ __('Watching') }}</span>
                                @endif
                            </div>
                            <p class="text-xs text-secondary-medium dark:text-secondary-soft"><span class="font-semibold">{{ __('Impact') }}:</span> {{ $risk->impact }}</p>
                            @if ($risk->solution !== null)
                                <p class="text-xs text-secondary-medium dark:text-secondary-soft"><span class="font-semibold">{{ __('Mitigation') }}:</span> {{ $risk->solution }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'targets')
        <x-ui.panel :title="__('Targets naming this project')" :count="$this->targets->count()">
            @if ($this->targets->isEmpty())
                <x-ui.empty-state icon="chart-bar" :heading="__('No targets yet')" :body="__('Indicator targets that name this project will appear here.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->targets as $target)
                        <article class="flex flex-col gap-2 p-3.5 @3xl/detail:flex-row @3xl/detail:items-center" wire:key="project-target-{{ $target->id }}">
                            <div class="min-w-0 @3xl/detail:me-auto">
                                <p class="truncate text-sm font-semibold">{{ $target->indicator->name }}</p>
                                <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $target->indicator->result->statement }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">
                                    {{ $target->source_type === 'yearly_plan' ? __('Annual plan') : __('Proposal') }}
                                </span>
                                <x-ui.enum-badge :case="$target->period" />
                                <span class="text-sm font-semibold tabular-nums">{{ number_format((float) $target->target, 2) }}</span>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'files')
        <x-ui.panel :title="__('Project files')" :count="$this->project->media_count" :subtitle="__('File records only. The files themselves are not stored in this system yet.')">
            @if ($this->project->media->isEmpty())
                <x-ui.empty-state icon="paper-clip" :heading="__('No files recorded')" :body="__('File records for this project will appear here.')" />
            @else
                <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->project->media as $media)
                        <li class="flex items-center gap-3 p-3.5" wire:key="project-file-{{ $media->id }}">
                            <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-md border border-secondary-light text-[11px] font-semibold text-secondary-medium uppercase dark:text-secondary-soft">
                                {{ \Illuminate\Support\Str::upper($media->type->value) }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ $media->original_name }}</p>
                                <p class="flex flex-wrap items-center gap-x-2 text-xs text-secondary-medium dark:text-secondary-soft">
                                    <span>{{ \Illuminate\Support\Str::title($media->collection->value) }}</span>
                                    <span aria-hidden="true">·</span>
                                    <time datetime="{{ $media->uploaded_at?->toDateString() }}">{{ $media->uploaded_at?->format('M j, Y') ?? '—' }}</time>
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>
    @else
        <x-ui.panel :title="__('Linked proposals')" :count="$this->attachedProposals->count()" :subtitle="__('A project may be funded by several proposals, and a proposal may cover several projects.')">
            <x-slot:toolbar>
                <div class="flex w-full flex-col items-stretch gap-2 sm:w-auto sm:flex-row sm:items-end">
                    <x-ui.select :label="__('Link a proposal')" wire:model="proposalToAttach" class="min-w-56">
                        <flux:select.option value="">{{ __('Select a proposal') }}</flux:select.option>
                        @foreach ($this->attachableProposals as $proposalOption)
                            <flux:select.option :value="$proposalOption->id" :wire:key="'attachable-'.$proposalOption->id">{{ $proposalOption->name }}</flux:select.option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.button tone="primary" size="sm" icon="link" class="min-h-10" wire:click="attachProposal">{{ __('Link') }}</x-ui.button>
                </div>
            </x-slot:toolbar>

            @if ($this->attachedProposals->isEmpty())
                <x-ui.empty-state icon="document-text" :heading="__('No proposals linked')" :body="__('Link a proposal to record which funding covers this project.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->attachedProposals as $proposal)
                        <article class="flex flex-wrap items-center gap-3 p-3.5" wire:key="project-proposal-{{ $proposal->id }}">
                            <div class="min-w-0 flex-1">
                                @if (Route::has('proposals.show'))
                                    <a href="{{ route('proposals.show', $proposal) }}" wire:navigate class="truncate text-sm font-semibold hover:underline">{{ $proposal->name }}</a>
                                @else
                                    <p class="truncate text-sm font-semibold">{{ $proposal->name }}</p>
                                @endif
                                <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                    {{ $proposal->planned_date_begin?->format('M j, Y') }} – {{ $proposal->planned_date_end?->format('M j, Y') }}
                                </p>
                            </div>
                            <x-ui.enum-badge :case="$proposal->status" />
                            <x-ui.button tone="ghost" size="sm" icon="x-mark" class="size-9!" wire:click="detachProposal({{ $proposal->id }})" :aria-label="__('Unlink :name', ['name' => $proposal->name])" />
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @endif
</section>
