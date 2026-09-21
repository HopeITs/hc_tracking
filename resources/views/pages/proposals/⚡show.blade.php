<?php

use App\Enums\ProposalStatus;
use App\Models\DonorProposal;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.proposal')] class extends Component {
    public int $proposalId;

    #[Url(except: 'status')]
    public string $tab = 'status';

    public bool $showStatusModal = false;
    public string $newStatus = '';
    public string $statusContext = '';
    public string $statusDate = '';

    public function mount(Proposal $proposal): void
    {
        $this->proposalId = $proposal->id;
    }

    #[Computed]
    public function proposal(): Proposal
    {
        return Proposal::query()
            ->select(['id', 'name', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'status', 'notes', 'updated_at'])
            ->withCount(['donorProposals', 'projects', 'tasks', 'statusNotes', 'planIndicators'])
            ->findOrFail($this->proposalId);
    }

    /** @return Collection<int, ProposalStatusNote> */
    #[Computed]
    public function statusNotes(): Collection
    {
        return ProposalStatusNote::query()
            ->select(['id', 'proposal_id', 'status', 'context', 'date'])
            ->where('proposal_id', $this->proposalId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The funding agreements on this proposal.
     *
     * Read through donorProposals(), never through donors()->pivot: the pivot
     * built by belongsToMany carries no casts, so its currency arrives as a raw
     * string while this relation's arrives as a Currency case.
     *
     * @return Collection<int, DonorProposal>
     */
    #[Computed]
    public function agreements(): Collection
    {
        return DonorProposal::query()
            ->select(['id', 'donor_id', 'budget', 'currency', 'project_num', 'proposal_id'])
            ->where('proposal_id', $this->proposalId)
            ->with('donor:id,name')
            ->withCount('transfers')
            ->orderBy('id')
            ->get();
    }

    /**
     * Committed budget per currency.
     *
     * Grouped, never summed: USD, EUR and SYP have no conversion rate in this
     * system, so a single total would be a fabricated number.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function budgetByCurrency(): array
    {
        return $this->agreements
            ->groupBy(fn (DonorProposal $agreement): string => $agreement->currency->value)
            ->map(fn (Collection $group): float => (float) $group->sum(fn (DonorProposal $agreement): float => (float) $agreement->budget))
            ->all();
    }

    /** @return Collection<int, \App\Models\Project> */
    #[Computed]
    public function projects(): Collection
    {
        return $this->proposal->projects()
            ->select(['projects.id', 'projects.name', 'projects.city_id', 'projects.program_id'])
            ->with(['city:id,name_en,name_ar', 'program:id,name'])
            ->orderBy('projects.name')
            ->get();
    }

    /** @return Collection<int, Task> */
    #[Computed]
    public function tasks(): Collection
    {
        return Task::query()
            ->select(['id', 'proposal_id', 'milestone_id', 'title', 'status', 'priority', 'due_date', 'progress_percentage'])
            ->where('proposal_id', $this->proposalId)
            ->with('taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
            ->orderByRaw('due_date is null, due_date')
            ->get();
    }

    /**
     * Targets committed in this proposal.
     *
     * Filtered on the morph map key 'proposal', never a class name.
     *
     * @return Collection<int, PlanIndicator>
     */
    #[Computed]
    public function targets(): Collection
    {
        return PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period'])
            ->where('source_type', 'proposal')
            ->where('source_id', $this->proposalId)
            ->with(['indicator:id,name,result_id', 'indicator.result:id,statement', 'project:id,name'])
            ->get();
    }

    /** @return array<int, array{key: string, label: string, count: int}> */
    #[Computed]
    public function tabs(): array
    {
        return [
            ['key' => 'status', 'label' => __('proposals.status_history'), 'count' => $this->proposal->status_notes_count],
            ['key' => 'funding', 'label' => __('common.funding'), 'count' => $this->proposal->donor_proposals_count],
            ['key' => 'projects', 'label' => __('common.projects'), 'count' => $this->proposal->projects_count],
            ['key' => 'tasks', 'label' => __('common.tasks'), 'count' => $this->proposal->tasks_count],
            ['key' => 'targets', 'label' => __('common.targets'), 'count' => $this->proposal->plan_indicators_count],
        ];
    }

    public function openStatusModal(): void
    {
        $this->resetValidation();
        $this->newStatus = $this->proposal->status->value;
        $this->statusContext = '';
        $this->statusDate = now()->toDateString();
        $this->showStatusModal = true;
    }

    /**
     * Move the proposal to a new status and record why.
     *
     * Both writes happen in one transaction. The live status and its audit
     * trail are the same vocabulary — proposal_status_notes.status casts to the
     * same ProposalStatus enum — so they must never be allowed to disagree.
     */
    public function changeStatus(): void
    {
        $this->statusContext = trim($this->statusContext);

        $validated = $this->validate([
            'newStatus' => ['required', new Enum(ProposalStatus::class)],
            'statusContext' => ['required', 'string'],
            'statusDate' => ['required', 'date'],
        ], [
            'newStatus.required' => __('proposals.choose_the_new_status'),
            'statusContext.required' => __('proposals.record_why_the_status_changed'),
            'statusDate.required' => __('proposals.set_the_date_this_change_took_effect'),
        ]);

        DB::transaction(function () use ($validated): void {
            $proposal = Proposal::query()->findOrFail($this->proposalId);
            $proposal->fill(['status' => $validated['newStatus']])->save();

            ProposalStatusNote::query()->create([
                'proposal_id' => $this->proposalId,
                'status' => $validated['newStatus'],
                'context' => $validated['statusContext'],
                'date' => $validated['statusDate'],
            ]);
        });

        $this->showStatusModal = false;
        $this->statusContext = '';
        unset($this->proposal, $this->statusNotes);

        Flux::toast(variant: 'success', text: __('proposals.status_changed_and_recorded'));
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('common.proposals'), 'url' => route('proposals.index')],
            ['label' => $this->proposal->name],
        ]"
        :title="$this->proposal->name"
    >
        <x-slot:meta>
            <span class="tabular-nums">{{ $this->proposal->planned_date_begin?->translatedFormat(__('formats.date')) }} – {{ $this->proposal->planned_date_end?->translatedFormat(__('formats.date')) }}</span>
            @if ($this->proposal->actual_date_begin !== null)
                <span class="tabular-nums">{{ __('proposals.actual_begin', ['begin' => $this->proposal->actual_date_begin->translatedFormat(__('formats.date'))]) }}</span>
            @endif
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.enum-badge :case="$this->proposal->status" />
                <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">
                    {{ $this->proposal->phase->label() }}
                </span>
                <x-ui.button tone="primary" size="sm" icon="arrow-path" wire:click="openStatusModal" data-test="open-status-modal">{{ __('proposals.change_status') }}</x-ui.button>
            </div>
        </x-slot:actions>
    </x-ui.detail-header>

    @if ($this->budgetByCurrency !== [])
        <div class="grid gap-3 @2xl/detail:grid-cols-3">
            @foreach ($this->budgetByCurrency as $currencyCode => $total)
                <x-ui.stat-tile
                    :label="__('proposals.committed_budget')"
                    :value="number_format($total, 2)"
                    :unit="$currencyCode"
                    :scope="$currencyCode"
                    wire:key="budget-{{ $currencyCode }}"
                />
            @endforeach
        </div>
    @endif

    <x-ui.relation-tabs :tabs="$this->tabs" :active="$tab" wire-target="tab" />

    @if ($tab === 'status')
        <x-ui.panel :title="__('proposals.status_history')" :count="$this->statusNotes->count()" :subtitle="__('proposals.the_audit_trail_for_this_proposal_s_status_newest_first')">
            @if ($this->statusNotes->isEmpty())
                <x-ui.empty-state icon="clock" :heading="__('proposals.no_status_history_yet')" :body="__('proposals.use_change_status_to_record_the_first_movement_and_why_it_ha')" />
            @else
                <ol class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->statusNotes as $note)
                        <li class="flex flex-col gap-1.5 p-3.5" wire:key="status-note-{{ $note->id }}">
                            <div class="flex items-center justify-between gap-3">
                                <x-ui.enum-badge :case="$note->status" />
                                <time datetime="{{ $note->date?->toDateString() }}" class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $note->date?->translatedFormat(__('formats.date')) }}</time>
                            </div>
                            @if ($note->context !== null)
                                <p class="text-sm text-secondary-dark dark:text-white">{{ $note->context }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'funding')
        <x-ui.panel :title="__('common.funding_agreements_2')" :count="$this->agreements->count()" :subtitle="__('proposals.budgets_are_shown_per_currency_they_are_never_added_together')">
            @if ($this->agreements->isEmpty())
                <x-ui.empty-state icon="banknotes" :heading="__('proposals.no_funding_agreements')" :body="__('proposals.agreements_signed_against_this_proposal_will_appear_here')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->agreements as $agreement)
                        <article class="flex flex-col gap-2 p-3.5 @3xl/detail:flex-row @3xl/detail:items-center" wire:key="proposal-agreement-{{ $agreement->id }}">
                            <div class="min-w-0 @3xl/detail:me-auto">
                                <a href="{{ route('funding-agreements.show', $agreement) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $agreement->donor->name }}</a>
                                <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                    {{ $agreement->project_num ?? __('common.no_donor_reference') }}
                                    · {{ trans_choice('common.count_tranche', $agreement->transfers_count, ['count' => $agreement->transfers_count]) }}
                                </p>
                            </div>
                            <x-ui.money :amount="$agreement->budget" :currency="$agreement->currency" />
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'projects')
        <x-ui.panel :title="__('proposals.projects_covered')" :count="$this->projects->count()" :subtitle="__('proposals.manage_these_links_from_the_project_page')">
            @if ($this->projects->isEmpty())
                <x-ui.empty-state icon="rectangle-stack" :heading="__('proposals.no_projects_linked')" :body="__('proposals.projects_this_proposal_funds_will_appear_here')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->projects as $project)
                        <article class="flex items-center gap-3 p-3.5" wire:key="proposal-project-{{ $project->id }}">
                            <div class="min-w-0 me-auto">
                                <a href="{{ route('projects.show', $project) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $project->name }}</a>
                                <p class="text-xs text-secondary-medium dark:text-secondary-soft">{{ $project->city?->name ?? __('common.unknown_city') }} · {{ $project->program->name }}</p>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'tasks')
        <x-ui.panel :title="__('common.tasks')" :count="$this->tasks->count()">
            @if ($this->tasks->isEmpty())
                <x-ui.empty-state icon="clipboard-document-check" :heading="__('common.no_tasks_yet')" :body="__('proposals.tasks_delivering_against_this_proposal_will_appear_here')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->tasks as $task)
                        <article class="flex flex-wrap items-center gap-3 p-3.5" wire:key="proposal-task-{{ $task->id }}">
                            <div class="min-w-0 me-auto">
                                <a href="{{ route('tasks.show', $task) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $task->title }}</a>
                                @php $proposalTaskAssignees = $task->taskAssignees->pluck('employee')->filter(); @endphp
                                <p class="text-xs text-secondary-medium dark:text-secondary-soft">{{ $proposalTaskAssignees->isEmpty() ? __('common.not_assigned') : $proposalTaskAssignees->map->name->implode(', ') }}</p>
                            </div>
                            <x-ui.enum-badge :case="$task->status" />
                            <span class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $task->progress_percentage }}%</span>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @else
        <x-ui.panel :title="__('proposals.targets_committed_here')" :count="$this->targets->count()">
            @if ($this->targets->isEmpty())
                <x-ui.empty-state icon="chart-bar" :heading="__('common.no_targets_yet')" :body="__('proposals.indicator_targets_committed_in_this_proposal_will_appear_her')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->targets as $target)
                        <article class="flex flex-wrap items-center gap-3 p-3.5" wire:key="proposal-target-{{ $target->id }}">
                            <div class="min-w-0 me-auto">
                                <p class="text-sm font-semibold">{{ $target->indicator->name }}</p>
                                <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                    {{ $target->project?->name ?? __('common.organisation_wide') }}
                                </p>
                            </div>
                            <x-ui.enum-badge :case="$target->period" />
                            <span class="text-sm font-semibold tabular-nums">{{ number_format((float) $target->target, 2) }}</span>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @endif

    <flux:modal wire:model="showStatusModal" class="w-full max-w-xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="changeStatus" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ __('proposals.change_status') }}</flux:heading>
                <flux:subheading>{{ __('proposals.the_new_status_and_your_reason_are_recorded_together_as_one') }}</flux:subheading>
            </div>

            <x-ui.select wire:model="newStatus" :label="__('proposals.new_status')" required>
                @foreach (ProposalStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'change-status-'.$statusCase->value">
                        {{ $statusCase->label() }} — {{ $statusCase->phase()->label() }}
                    </flux:select.option>
                @endforeach
            </x-ui.select>

            <flux:textarea wire:model="statusContext" :label="__('proposals.why_did_it_change')" rows="3" required class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <x-ui.input wire:model="statusDate" :label="__('proposals.effective_date')" type="date" required />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="$set('showStatusModal', false)">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-status-button">{{ __('proposals.record_change') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>
</section>
