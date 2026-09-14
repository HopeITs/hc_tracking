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

new #[Title('Proposal')] class extends Component {
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
            ->with(['city:id,name', 'program:id,name'])
            ->orderBy('projects.name')
            ->get();
    }

    /** @return Collection<int, Task> */
    #[Computed]
    public function tasks(): Collection
    {
        return Task::query()
            ->select(['id', 'proposal_id', 'milestone_id', 'title', 'status', 'priority', 'due_date', 'assigned_to', 'progress_percentage'])
            ->where('proposal_id', $this->proposalId)
            ->with('assignee:id,name,email,role')
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
            ['key' => 'status', 'label' => __('Status history'), 'count' => $this->proposal->status_notes_count],
            ['key' => 'funding', 'label' => __('Funding'), 'count' => $this->proposal->donor_proposals_count],
            ['key' => 'projects', 'label' => __('Projects'), 'count' => $this->proposal->projects_count],
            ['key' => 'tasks', 'label' => __('Tasks'), 'count' => $this->proposal->tasks_count],
            ['key' => 'targets', 'label' => __('Targets'), 'count' => $this->proposal->plan_indicators_count],
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
            'newStatus.required' => __('Choose the new status.'),
            'statusContext.required' => __('Record why the status changed.'),
            'statusDate.required' => __('Set the date this change took effect.'),
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

        Flux::toast(variant: 'success', text: __('Status changed and recorded.'));
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('Proposals'), 'url' => route('proposals.index')],
            ['label' => $this->proposal->name],
        ]"
        :title="$this->proposal->name"
    >
        <x-slot:meta>
            <span class="tabular-nums">{{ $this->proposal->planned_date_begin?->format('M j, Y') }} – {{ $this->proposal->planned_date_end?->format('M j, Y') }}</span>
            @if ($this->proposal->actual_date_begin !== null)
                <span class="tabular-nums">{{ __('Actual: :begin', ['begin' => $this->proposal->actual_date_begin->format('M j, Y')]) }}</span>
            @endif
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.enum-badge :case="$this->proposal->status" />
                <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">
                    {{ Str::title($this->proposal->phase->value) }}
                </span>
                <x-ui.button tone="primary" size="sm" icon="arrow-path" wire:click="openStatusModal" data-test="open-status-modal">{{ __('Change status') }}</x-ui.button>
            </div>
        </x-slot:actions>
    </x-ui.detail-header>

    @if ($this->budgetByCurrency !== [])
        <div class="grid gap-3 @2xl/detail:grid-cols-3">
            @foreach ($this->budgetByCurrency as $currencyCode => $total)
                <x-ui.stat-tile
                    :label="__('Committed budget')"
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
        <x-ui.panel :title="__('Status history')" :count="$this->statusNotes->count()" :subtitle="__('The audit trail for this proposal\'s status, newest first.')">
            @if ($this->statusNotes->isEmpty())
                <x-ui.empty-state icon="clock" :heading="__('No status history yet')" :body="__('Use Change status to record the first movement and why it happened.')" />
            @else
                <ol class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->statusNotes as $note)
                        <li class="flex flex-col gap-1.5 p-3.5" wire:key="status-note-{{ $note->id }}">
                            <div class="flex items-center justify-between gap-3">
                                <x-ui.enum-badge :case="$note->status" />
                                <time datetime="{{ $note->date?->toDateString() }}" class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $note->date?->format('M j, Y') }}</time>
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
        <x-ui.panel :title="__('Funding agreements')" :count="$this->agreements->count()" :subtitle="__('Budgets are shown per currency. They are never added together.')">
            @if ($this->agreements->isEmpty())
                <x-ui.empty-state icon="banknotes" :heading="__('No funding agreements')" :body="__('Agreements signed against this proposal will appear here.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->agreements as $agreement)
                        <article class="flex flex-col gap-2 p-3.5 @3xl/detail:flex-row @3xl/detail:items-center" wire:key="proposal-agreement-{{ $agreement->id }}">
                            <div class="min-w-0 @3xl/detail:me-auto">
                                <a href="{{ route('funding-agreements.show', $agreement) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $agreement->donor->name }}</a>
                                <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                    {{ $agreement->project_num ?? __('No donor reference') }}
                                    · {{ trans_choice(':count tranche|:count tranches', $agreement->transfers_count, ['count' => $agreement->transfers_count]) }}
                                </p>
                            </div>
                            <x-ui.money :amount="$agreement->budget" :currency="$agreement->currency" />
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'projects')
        <x-ui.panel :title="__('Projects covered')" :count="$this->projects->count()" :subtitle="__('Manage these links from the project page.')">
            @if ($this->projects->isEmpty())
                <x-ui.empty-state icon="rectangle-stack" :heading="__('No projects linked')" :body="__('Projects this proposal funds will appear here.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->projects as $project)
                        <article class="flex items-center gap-3 p-3.5" wire:key="proposal-project-{{ $project->id }}">
                            <div class="min-w-0 me-auto">
                                <a href="{{ route('projects.show', $project) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $project->name }}</a>
                                <p class="text-xs text-secondary-medium dark:text-secondary-soft">{{ $project->city->name }} · {{ $project->program->name }}</p>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'tasks')
        <x-ui.panel :title="__('Tasks')" :count="$this->tasks->count()">
            @if ($this->tasks->isEmpty())
                <x-ui.empty-state icon="clipboard-document-check" :heading="__('No tasks yet')" :body="__('Tasks delivering against this proposal will appear here.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->tasks as $task)
                        <article class="flex flex-wrap items-center gap-3 p-3.5" wire:key="proposal-task-{{ $task->id }}">
                            <div class="min-w-0 me-auto">
                                <a href="{{ route('tasks.show', $task) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $task->title }}</a>
                                <p class="text-xs text-secondary-medium dark:text-secondary-soft">{{ $task->assignee->name }}</p>
                            </div>
                            <x-ui.enum-badge :case="$task->status" />
                            <span class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $task->progress_percentage }}%</span>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @else
        <x-ui.panel :title="__('Targets committed here')" :count="$this->targets->count()">
            @if ($this->targets->isEmpty())
                <x-ui.empty-state icon="chart-bar" :heading="__('No targets yet')" :body="__('Indicator targets committed in this proposal will appear here.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->targets as $target)
                        <article class="flex flex-wrap items-center gap-3 p-3.5" wire:key="proposal-target-{{ $target->id }}">
                            <div class="min-w-0 me-auto">
                                <p class="text-sm font-semibold">{{ $target->indicator->name }}</p>
                                <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                    {{ $target->project?->name ?? __('Organisation-wide') }}
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
                <flux:heading size="lg">{{ __('Change status') }}</flux:heading>
                <flux:subheading>{{ __('The new status and your reason are recorded together, as one entry in the audit trail.') }}</flux:subheading>
            </div>

            <x-ui.select wire:model="newStatus" :label="__('New status')" required>
                @foreach (ProposalStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'change-status-'.$statusCase->value">
                        {{ Str::title($statusCase->value) }} — {{ Str::title($statusCase->phase()->value) }}
                    </flux:select.option>
                @endforeach
            </x-ui.select>

            <flux:textarea wire:model="statusContext" :label="__('Why did it change?')" rows="3" required class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <x-ui.input wire:model="statusDate" :label="__('Effective date')" type="date" required />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="$set('showStatusModal', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-status-button">{{ __('Record change') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>
</section>
