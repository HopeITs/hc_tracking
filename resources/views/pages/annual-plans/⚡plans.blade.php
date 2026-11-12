<?php

use App\Actions\AnnualPlans\CreateActivityPlan;
use App\Enums\ActivityPlanStatus;
use App\Enums\ProposalStatus;
use App\Models\ActivityPlan;
use App\Models\Program;
use App\Models\Proposal;
use App\Policies\ActivityPlanPolicy;
use App\Support\ProposalFramework;
use App\Enums\PlanUpdateStatus;
use App\Models\Task;
use App\Policies\PlanUpdatePolicy;
use App\Support\MealCoverage;
use App\Support\PlanCalendar;
use App\Support\PlanExecution;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
     * Every approved plan, read once for the grid, the CEO summary and the
     * next step.
     *
     * @return list<PlanExecution>
     */
    #[Computed]
    public function approvedExecutions(): array
    {
        return array_values(ActivityPlan::query()->where('status', ActivityPlanStatus::Approved->value)->with('proposal')->get()
            ->map(fn (ActivityPlan $plan): PlanExecution => PlanExecution::for($plan))->all());
    }

    /**
     * The tasks MEAL's follow-ups created, newest first.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function followUpTasks(): Collection
    {
        $ids = DB::table('activity_plan_follow_up_tasks')->latest('id')->limit(30)->pluck('task_id');

        return Task::query()->withoutGlobalScopes()->whereKey($ids)->latest('id')->get();
    }

    /**
     * The signed-in person's one next step (2026-11-11 mockup): a plan to
     * review or fix, then a monthly update to fix, review, mark seen or send.
     *
     * @return array{tone: string, title: string, body: string, url: string, label: string}|null
     */
    #[Computed]
    public function nextStep(): ?array
    {
        $user = auth()->user();
        $isMeal = ActivityPlanPolicy::isMealManager($user);

        if ($isMeal) {
            $toReview = ActivityPlan::query()->with('proposal:id,slug,name')->where('status', ActivityPlanStatus::InReview->value)->oldest('submitted_at')->first();

            if ($toReview !== null) {
                return ['tone' => 'warn', 'title' => __('annual_plans.wf.next_review_title'), 'body' => __('annual_plans.wf.next_review_body', ['plan' => $toReview->proposal->name]), 'url' => route('activity-plans.show', $toReview), 'label' => __('annual_plans.wf.review_now')];
            }
        }

        $mine = ActivityPlan::query()->with('proposal:id,slug,name')->where('status', ActivityPlanStatus::Draft->value)->get()
            ->filter(fn (ActivityPlan $plan): bool => $user->can('update', $plan))
            ->sortByDesc(fn (ActivityPlan $plan): int => $plan->sent_back_reason ? 1 : 0)
            ->first();

        if ($mine?->sent_back_reason) {
            return ['tone' => 'bad', 'title' => __('annual_plans.wf.next_fix_title'), 'body' => __('annual_plans.wf.next_fix_body', ['reason' => $mine->sent_back_reason]), 'url' => route('activity-plans.show', $mine), 'label' => __('annual_plans.wf.fix_plan')];
        }

        $found = $this->updateSteps($user, $isMeal);

        if ($found !== []) {
            $first = $found[0];

            if (count($found) > 1) {
                $first['body'] .= ' · '.trans_choice('annual_plans.next.more', count($found) - 1, ['count' => count($found) - 1]);
            }

            return $first;
        }

        if ($mine !== null) {
            return ['tone' => 'warn', 'title' => __('annual_plans.wf.next_finish_title'), 'body' => __('annual_plans.wf.next_finish_body', ['plan' => $mine->proposal->name]), 'url' => route('activity-plans.show', $mine), 'label' => __('annual_plans.wf.open_plan')];
        }

        return null;
    }

    /**
     * The monthly updates waiting for this person, most urgent first: one to
     * fix, to review (officer, MEAL manager), to mark seen, then to send.
     *
     * @return list<array{tone: string, title: string, body: string, url: string, label: string}>
     */
    private function updateSteps(\App\Models\User $user, bool $isMeal): array
    {
        $today = CarbonImmutable::today();
        $found = [];

        foreach ($this->approvedExecutions as $execution) {
            $isProgramManager = PlanUpdatePolicy::isProgramManager($user, $execution->plan) && ! $user->hasRole('Super Admin');

            foreach ($execution->projects() as $centre) {
                $isProjectManager = PlanUpdatePolicy::isProjectManager($user, $centre) && ! $user->hasRole('Super Admin');
                $isOfficer = $user->employee_id !== null && MealCoverage::responsibleFor($centre) === (int) $user->employee_id;

                foreach ($execution->startedMonths($today) as $month) {
                    $update = $execution->update($centre, $month);
                    $status = $update?->status;
                    $what = $centre->name.' · '.$month->translatedFormat('F Y');
                    $monthName = $month->translatedFormat('F');
                    $step = match (true) {
                        $status === PlanUpdateStatus::Returned && $isProjectManager => [0, 'bad', __('annual_plans.next.fix_title', ['month' => $monthName]), __('annual_plans.next.fix_body', ['what' => $what, 'reason' => (string) $update->return_reason]), __('annual_plans.next.fix_button')],
                        $status === PlanUpdateStatus::Sent && $isOfficer => [1, $update->returned_to === 'officer' ? 'bad' : 'warn', __('annual_plans.next.officer_title', ['month' => $monthName]), $what, __('annual_plans.wf.review_now')],
                        $status === PlanUpdateStatus::OfficerApproved && $isMeal => [1, 'warn', __('annual_plans.next.meal_title', ['month' => $monthName]), $what, __('annual_plans.wf.review_now')],
                        $status === PlanUpdateStatus::Reviewed && $isProgramManager => [2, 'warn', __('annual_plans.next.seen_title', ['month' => $monthName]), $what, __('annual_plans.next.open_update')],
                        ($status === null || $status === PlanUpdateStatus::Draft) && $isProjectManager && $today->gte($month->addMonth()) && $execution->sendBlockers($centre, $month, $today) === [] => [3, $today->gt(PlanCalendar::dueDateOf($month)) ? 'bad' : 'warn', __('annual_plans.next.send_title', ['month' => $monthName]), __('annual_plans.next.send_body', ['what' => $what, 'date' => PlanCalendar::dueDateOf($month)->translatedFormat(__('formats.date'))]), __('annual_plans.next.fill_button')],
                        default => null,
                    };

                    if ($step !== null) {
                        $found[] = ['rank' => $step[0], 'tone' => $step[1], 'title' => $step[2], 'body' => $step[3], 'url' => route('plan-updates.show', [$execution->plan, $centre, $month->format('Y-m')]), 'label' => $step[4]];
                    }
                }
            }
        }

        usort($found, fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return array_map(function (array $step): array {
            unset($step['rank']);

            return $step;
        }, $found);
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
    $ownsCentre = $user->employee_id !== null && \App\Models\Project::query()->where('user_id', $user->employee_id)->exists();
    $guideKey = match (true) {
        $user->hasRole('General Manager') && ! $user->hasRole('Super Admin') => 'ceo',
        \App\Policies\ActivityPlanPolicy::isMealManager($user) => 'meal',
        $user->hasRole('MEAL') => 'officer',
        $ownsCentre && ! \App\Models\Program::query()->where('manager_id', $user->employee_id)->exists() => 'centre',
        $user->can('create', \App\Models\ActivityPlan::class) => 'pm',
        default => 'viewer',
    };
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
                <a class="dk-btn dk-primary" href="{{ $next['url'] }}" wire:navigate data-test="next-step-button">{{ $next['label'] }}</a>
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

        @include('pages.annual-plans._plans-overview')
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
