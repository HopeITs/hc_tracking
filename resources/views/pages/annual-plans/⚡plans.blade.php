<?php

use App\Actions\AnnualPlans\CreateActivityPlan;
use App\Enums\ActivityPlanStatus;
use App\Enums\ProposalStatus;
use App\Models\ActivityPlan;
use App\Models\Program;
use App\Models\Proposal;
use App\Policies\ActivityPlanPolicy;
use App\Support\ProposalFramework;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The Annual plans register (replaces the program × year targets matrix,
 * user decision 2026-11-12): every activity plan with its proposal, program,
 * status and next step, a "New plan" form (proposal, then program), and the
 * signed-in person's next step.
 */
new #[Layout('layouts::desk'), Title('titles.annual_plans')] class extends Component {
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public bool $showCreateModal = false;

    public string $newProposal = '';

    public string $newProgram = '';

    /**
     * @return Collection<int, ActivityPlan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return ActivityPlan::query()
            ->with(['proposal:id,slug,name', 'items:id,activity_plan_id,is_ready'])
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when(trim($this->search) !== '', fn ($query) => $query->whereHas('proposal', fn ($proposals) => $proposals->where('name', 'like', '%'.trim($this->search).'%')))
            ->orderByRaw("case status when 'in_review' then 0 when 'draft' then 1 else 2 end")
            ->latest('updated_at')
            ->get();
    }

    /**
     * Status => how many plans, ignoring the status filter.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        $counts = ActivityPlan::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total): int => (int) $total)->all();

        return ['' => array_sum($counts), ...array_map('intval', $counts)];
    }

    /**
     * The proposals a plan can be started for: not rejected, with centres.
     *
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function proposalOptions(): Collection
    {
        return Proposal::query()
            ->whereNot('status', ProposalStatus::Rejected->value)
            ->whereHas('projects', fn ($projects) => $projects->whereNotNull('projects.program_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'internal_project_number', 'status']);
    }

    /**
     * The chosen proposal's programs the user plans for, id => name.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function programOptions(): array
    {
        $proposal = $this->newProposal === '' ? null : Proposal::query()->find((int) $this->newProposal);

        if ($proposal === null) {
            return [];
        }

        $policy = app(ActivityPlanPolicy::class);

        return Program::query()->whereKey(ProposalFramework::programIds($proposal))->get(['id', 'manager_id'])
            ->filter(fn (Program $program): bool => $policy->plansFor(auth()->user(), $program))
            ->mapWithKeys(fn (Program $program): array => [$program->id => (string) $program->name])
            ->all();
    }

    /**
     * The signed-in person's next step on the plans: a plan sent back or in
     * progress for the program manager, a plan to review for MEAL.
     *
     * @return array{tone: string, title: string, body: string, plan: ActivityPlan|null, label: string}|null
     */
    #[Computed]
    public function nextStep(): ?array
    {
        $user = auth()->user();

        if (ActivityPlanPolicy::isMealManager($user)) {
            $toReview = ActivityPlan::query()->with('proposal:id,slug,name')->where('status', ActivityPlanStatus::InReview->value)->oldest('submitted_at')->first();

            if ($toReview !== null) {
                return ['tone' => 'warn', 'title' => __('annual_plans.wf.next_review_title'), 'body' => __('annual_plans.wf.next_review_body', ['plan' => $toReview->proposal->name]), 'plan' => $toReview, 'label' => __('annual_plans.wf.review_now')];
            }
        }

        $mine = ActivityPlan::query()->with('proposal:id,slug,name')->where('status', ActivityPlanStatus::Draft->value)->get()
            ->filter(fn (ActivityPlan $plan): bool => $user->can('update', $plan))
            ->sortByDesc(fn (ActivityPlan $plan): int => $plan->sent_back_reason ? 1 : 0)
            ->first();

        if ($mine !== null) {
            return $mine->sent_back_reason
                ? ['tone' => 'bad', 'title' => __('annual_plans.wf.next_fix_title'), 'body' => __('annual_plans.wf.next_fix_body', ['reason' => $mine->sent_back_reason]), 'plan' => $mine, 'label' => __('annual_plans.wf.fix_plan')]
                : ['tone' => 'warn', 'title' => __('annual_plans.wf.next_finish_title'), 'body' => __('annual_plans.wf.next_finish_body', ['plan' => $mine->proposal->name]), 'plan' => $mine, 'label' => __('annual_plans.wf.open_plan')];
        }

        return null;
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', ActivityPlan::class);
        $this->reset(['newProposal', 'newProgram']);
        $this->resetErrorBag();
        $this->showCreateModal = true;
    }

    public function updatedNewProposal(): void
    {
        $programs = $this->programOptions;
        $this->newProgram = count($programs) === 1 ? (string) array_key_first($programs) : '';
    }

    public function createPlan(): void
    {
        $this->resetErrorBag();

        if ($this->newProposal === '' || $this->newProgram === '') {
            $this->addError($this->newProposal === '' ? 'newProposal' : 'newProgram', __('annual_plans.wf.pick_proposal_program'));

            return;
        }

        try {
            $plan = app(CreateActivityPlan::class)->handle(auth()->user(), (int) $this->newProposal, (int) $this->newProgram);
        } catch (ValidationException $exception) {
            $this->addError('newProgram', (string) collect($exception->errors())->flatten()->first());

            return;
        } catch (AuthorizationException) {
            $this->addError('newProgram', __('annual_plans.wf.not_your_program'));

            return;
        }

        $this->showCreateModal = false;
        $this->redirectRoute('activity-plans.show', $plan, navigate: true);
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status']);
    }
};
?>

@php
    $plans = $this->plans;
    $counts = $this->counts;
    $next = $this->nextStep;
    $user = auth()->user();
    $guideKey = \App\Policies\ActivityPlanPolicy::isMealManager($user) ? 'meal' : ($user->can('create', \App\Models\ActivityPlan::class) ? 'pm' : 'viewer');
    $statusTabs = ['' => __('annual_plans.wf.all_plans'), ActivityPlanStatus::InReview->value => ActivityPlanStatus::InReview->label(), ActivityPlanStatus::Draft->value => ActivityPlanStatus::Draft->label(), ActivityPlanStatus::Approved->value => ActivityPlanStatus::Approved->label()];
@endphp

<x-desk.register heading-id="activity-plans-heading" :title="__('common.annual_plans')" :count="$counts['']" data-activity-plans>
    <x-slot:actions>
        @can('create', App\Models\ActivityPlan::class)
            <button type="button" class="dk-btn dk-primary" wire:click="openCreateModal" data-test="new-activity-plan" data-quick-create><x-desk.icon name="plus" />{{ __('annual_plans.wf.new_plan') }}</button>
        @endcan
    </x-slot:actions>
    <x-slot:search>
        <x-desk.register-search :label="__('annual_plans.wf.search_label')" :placeholder="__('annual_plans.wf.search_placeholder')" wire:model.live.debounce.300ms="search" />
    </x-slot:search>

    <div class="ap-page">
        @if ($next !== null)
            <section class="ap-next" data-next-step>
                <span class="ap-ic is-{{ $next['tone'] }}" aria-hidden="true">!</span>
                <div style="flex: 1; min-width: 220px"><h2>{{ $next['title'] }}</h2><p class="ap-sm ap-muted" dir="auto">{{ $next['body'] }}</p></div>
                <a class="dk-btn dk-primary" href="{{ route('activity-plans.show', $next['plan']) }}" wire:navigate>{{ $next['label'] }}</a>
            </section>
        @endif

        <details class="ap-guide" data-guide>
            <summary>{{ __('annual_plans.wf.guide_title') }}</summary>
            <ol>
                @foreach (__('annual_plans.wf.guide.'.$guideKey) as $line)
                    <li>{!! $line !!}</li>
                @endforeach
            </ol>
        </details>

        <div class="dk-chips" role="group" aria-label="{{ __('common.state') }}">
            @foreach ($statusTabs as $value => $label)
                <button type="button" class="dk-chip" wire:click="$set('status', '{{ $value }}')" aria-pressed="{{ $status === $value ? 'true' : 'false' }}" data-status-tab="{{ $value === '' ? 'all' : $value }}">{{ $label }} <span class="dk-num">{{ $counts[$value] ?? 0 }}</span></button>
            @endforeach
        </div>

        @if ($plans->isEmpty())
            <x-ui.empty-state icon="calendar-days" :heading="$counts[''] === 0 ? __('annual_plans.wf.empty_heading') : __('desk.register.nothing_matches')" :body="__('annual_plans.wf.empty_body')">
                @if ($counts[''] > 0)
                    <x-slot:action><x-ui.button tone="ghost" size="sm" wire:click="clearFilters">{{ __('common.reset_filters') }}</x-ui.button></x-slot:action>
                @endif
            </x-ui.empty-state>
        @else
            <x-ui.data-table :caption="__('common.annual_plans')">
                <x-slot:head>
                    <th>{{ __('annual_plans.wf.col_plan') }}</th>
                    <th class="w-40!">{{ __('annual_plans.wf.col_activities') }}</th>
                    <th class="w-48!">{{ __('common.state') }}</th>
                    <th class="w-40!">{{ __('annual_plans.wf.col_updated') }}</th>
                    <th class="w-16!"><span class="sr-only">{{ __('common.actions') }}</span></th>
                </x-slot:head>
                @foreach ($plans as $row)
                    @php $ready = $row->items->where('is_ready', true)->count(); @endphp
                    <tr wire:key="activity-plan-{{ $row->id }}" data-plan-row="{{ $row->id }}">
                        <x-ui.data-table.cell :label="__('annual_plans.wf.col_plan')">
                            <span class="block font-semibold" dir="auto">{{ $row->proposal->name }}</span>
                            <span class="block text-xs text-secondary-medium dark:text-ink-muted">{{ $row->program?->name }}</span>
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('annual_plans.wf.col_activities')" numeric>
                            <bdi dir="ltr">{{ $row->status === ActivityPlanStatus::Draft ? $ready.' / '.$row->items->count() : $row->items->count() }}</bdi>
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('common.state')">
                            <span class="dk-pill {{ match ($row->status) { ActivityPlanStatus::Approved => 'dk-good', ActivityPlanStatus::InReview => 'dk-act', ActivityPlanStatus::Draft => $row->sent_back_reason ? 'dk-bad' : '' } }}">{{ $row->status === ActivityPlanStatus::Draft && $row->sent_back_reason ? __('annual_plans.wf.status_sent_back') : $row->status->label() }}</span>
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('annual_plans.wf.col_updated')">
                            <span class="tabular-nums text-sm">{{ $row->updated_at?->translatedFormat(__('formats.date')) }}</span>
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell>
                            <x-ui.button tone="ghost" size="sm" icon="eye" :href="route('activity-plans.show', $row)" wire:navigate :aria-label="__('common.view_name', ['name' => $row->proposal->name])" data-test="open-plan-{{ $row->id }}" />
                        </x-ui.data-table.cell>
                    </tr>
                @endforeach
            </x-ui.data-table>
        @endif
    </div>

    <flux:modal wire:model="showCreateModal" class="w-full max-w-lg" data-test="new-plan-modal">
        <form wire:submit="createPlan" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('annual_plans.wf.new_plan') }}</flux:heading>
                <flux:text class="mt-1">{{ __('annual_plans.wf.new_plan_help') }}</flux:text>
            </div>
            <flux:select wire:model.live="newProposal" :label="__('annual_plans.wf.fact_proposal')" data-test="new-plan-proposal">
                <option value="">{{ __('annual_plans.wf.pick_proposal') }}</option>
                @foreach ($this->proposalOptions as $proposal)
                    <option value="{{ $proposal->id }}">{{ $proposal->name }}{{ $proposal->internal_project_number ? ' · '.$proposal->internal_project_number : '' }}</option>
                @endforeach
            </flux:select>
            @error('newProposal') <p class="ap-err">{{ $message }}</p> @enderror
            <flux:select wire:model="newProgram" :label="__('annual_plans.wf.program')" :disabled="$newProposal === ''" data-test="new-plan-program">
                <option value="">{{ __('annual_plans.wf.pick_program') }}</option>
                @foreach ($this->programOptions as $programId => $programName)
                    <option value="{{ $programId }}">{{ $programName }}</option>
                @endforeach
            </flux:select>
            @if ($newProposal !== '' && $this->programOptions === [])
                <p class="ap-sm ap-muted">{{ __('annual_plans.wf.no_program_to_plan') }}</p>
            @endif
            @error('newProgram') <p class="ap-err">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('common.cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="create-plan">{{ __('annual_plans.wf.start_plan') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</x-desk.register>
