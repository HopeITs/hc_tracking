<?php

use App\Actions\StrategicPlans\ReopenStrategicPlan;
use App\Actions\StrategicPlans\ReviewStrategicPlan;
use App\Actions\StrategicPlans\SaveStrategicPlan;
use App\Actions\StrategicPlans\SubmitStrategicPlan;
use App\Enums\StrategicPlanStatus;
use App\Models\Indicator;
use App\Models\Program;
use App\Models\StrategicPlan;
use App\Models\Strategy;
use App\Support\StrategicPlanIndicators;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * A program's strategic plan inside one strategy (MEAL manager request,
 * 2026-11-13). Not a program or project plan: for each year of the strategy,
 * what must be achieved and which program indicators (with a figure per
 * year) measure it. The MEAL manager writes it and sends it to the program
 * manager, who approves it or sends it back with a reason (StrategicPlanPolicy).
 * A record page of its own (records are pages, 2026-11-04).
 */
new #[Layout('layouts::desk'), Title('titles.strategic_plan')] class extends Component {
    #[Locked]
    public int $strategyId;

    #[Locked]
    public int $programId;

    /** @var array<int, string> year => what must be achieved */
    public array $goals = [];

    /** @var list<int> the linked indicators, in the order they were added */
    public array $linkedIndicators = [];

    /** @var array<int, array<int, string>> indicator => year => figure */
    public array $targets = [];

    public string $indicatorToAdd = '';

    public bool $showSendBackModal = false;

    public string $sendBackReason = '';

    public function mount(Strategy $strategy, Program $program): void
    {
        abort_unless(auth()->user()->can('strategies.view'), 403);
        abort_unless($program->strategy_id === $strategy->id, 404);

        $this->strategyId = $strategy->id;
        $this->programId = $program->id;
        $this->fillFromPlan();
    }

    #[Computed]
    public function strategy(): Strategy
    {
        return Strategy::query()->select(['id', 'slug', 'name', 'start_year', 'end_year'])->findOrFail($this->strategyId);
    }

    #[Computed]
    public function program(): Program
    {
        return Program::query()->with('manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar')->findOrFail($this->programId);
    }

    #[Computed]
    public function plan(): ?StrategicPlan
    {
        return StrategicPlan::query()
            ->where('strategy_id', $this->strategyId)
            ->where('program_id', $this->programId)
            ->with(['years.targets', 'approver', 'sender'])
            ->first();
    }

    /** @return list<int> */
    #[Computed]
    public function years(): array
    {
        return range($this->strategy->start_year, $this->strategy->end_year);
    }

    /** @return Collection<int, Indicator> */
    #[Computed]
    public function programIndicators(): Collection
    {
        return StrategicPlanIndicators::for($this->programId);
    }

    #[Computed]
    public function canWrite(): bool
    {
        return Gate::allows('write', [StrategicPlan::class, $this->plan]);
    }

    #[Computed]
    public function canReview(): bool
    {
        return $this->plan !== null && Gate::allows('review', $this->plan);
    }

    #[Computed]
    public function canReopen(): bool
    {
        return $this->plan !== null && Gate::allows('reopen', $this->plan);
    }

    public function addIndicator(): void
    {
        abort_unless($this->canWrite, 403);

        $indicatorId = (int) $this->indicatorToAdd;

        if ($indicatorId > 0 && $this->programIndicators->contains('id', $indicatorId) && ! in_array($indicatorId, $this->linkedIndicators, true)) {
            $this->linkedIndicators[] = $indicatorId;
            $this->targets[$indicatorId] = array_fill_keys($this->years, '');
        }

        $this->indicatorToAdd = '';
    }

    public function removeIndicator(int $indicatorId): void
    {
        abort_unless($this->canWrite, 403);

        $this->linkedIndicators = array_values(array_filter($this->linkedIndicators, fn (int $id): bool => $id !== $indicatorId));
        unset($this->targets[$indicatorId]);
    }

    public function save(): void
    {
        $this->persist();

        Flux::toast(variant: 'success', text: __('strategic_plans.saved'));
    }

    public function submit(): void
    {
        $plan = $this->persist();

        app(SubmitStrategicPlan::class)->handle(auth()->user(), $plan);

        $this->forgetPlan();
        Flux::toast(variant: 'success', text: __('strategic_plans.sent'));
    }

    public function approve(): void
    {
        abort_if($this->plan === null, 404);

        app(ReviewStrategicPlan::class)->approve(auth()->user(), $this->plan);

        $this->forgetPlan();
        Flux::toast(variant: 'success', text: __('strategic_plans.approved_toast'));
    }

    public function openSendBack(): void
    {
        abort_unless($this->canReview, 403);

        $this->resetValidation();
        $this->sendBackReason = '';
        $this->showSendBackModal = true;
    }

    public function closeSendBack(): void
    {
        $this->showSendBackModal = false;
        $this->sendBackReason = '';
    }

    public function sendBack(): void
    {
        abort_if($this->plan === null, 404);

        $this->resetValidation();
        app(ReviewStrategicPlan::class)->sendBack(auth()->user(), $this->plan, $this->sendBackReason);

        $this->showSendBackModal = false;
        $this->sendBackReason = '';
        $this->forgetPlan();
        Flux::toast(variant: 'success', text: __('strategic_plans.sent_back_toast'));
    }

    public function reopen(): void
    {
        abort_if($this->plan === null, 404);

        app(ReopenStrategicPlan::class)->handle(auth()->user(), $this->plan);

        $this->forgetPlan();
        Flux::toast(variant: 'success', text: __('strategic_plans.reopened_toast'));
    }

    private function persist(): StrategicPlan
    {
        $plan = app(SaveStrategicPlan::class)->handle(auth()->user(), $this->strategy, $this->program, [
            'goals' => $this->goals,
            'indicators' => $this->linkedIndicators,
            'targets' => $this->targets,
        ]);

        $this->forgetPlan();

        return $plan;
    }

    private function forgetPlan(): void
    {
        unset($this->plan, $this->canWrite, $this->canReview, $this->canReopen);
        $this->fillFromPlan();
    }

    /**
     * The form mirrors the stored plan; a plan not written yet starts empty.
     */
    private function fillFromPlan(): void
    {
        $this->goals = array_fill_keys($this->years, '');
        $this->linkedIndicators = [];
        $this->targets = [];

        foreach ($this->plan?->years ?? [] as $planYear) {
            if (array_key_exists($planYear->year, $this->goals)) {
                $this->goals[$planYear->year] = (string) $planYear->goals;
            }

            foreach ($planYear->targets as $target) {
                if (! in_array($target->indicator_id, $this->linkedIndicators, true)) {
                    $this->linkedIndicators[] = $target->indicator_id;
                    $this->targets[$target->indicator_id] = array_fill_keys($this->years, '');
                }

                if (array_key_exists($planYear->year, $this->targets[$target->indicator_id])) {
                    $this->targets[$target->indicator_id][$planYear->year] = $target->target === null ? '' : rtrim(rtrim((string) $target->target, '0'), '.');
                }
            }
        }
    }
};
?>

@php
    $strategy = $this->strategy;
    $program = $this->program;
    $plan = $this->plan;
    $status = $plan?->status;
    $canWrite = $this->canWrite;
    $indicatorsById = $this->programIndicators->keyBy('id');
    $unlinked = $this->programIndicators->reject(fn ($indicator): bool => in_array($indicator->id, $linkedIndicators, true));
    $formatDate = fn (\Carbon\CarbonInterface $moment): string => $moment->translatedFormat(__('formats.date'));
    $wasSentBack = $status === \App\Enums\StrategicPlanStatus::Draft && filled($plan?->sent_back_reason);
@endphp

<section class="@container/detail flex w-full flex-1 flex-col gap-6" data-strategic-plan="{{ $plan?->id ?? 'new' }}" data-plan-status="{{ $status?->value ?? 'none' }}">
    <x-ui.detail-header
        :back="\App\Support\BackUrl::for(route('strategies.show', $strategy))"
        :breadcrumbs="[
            ['label' => __('common.strategies'), 'url' => route('strategies.index')],
            ['label' => $strategy->name, 'url' => route('strategies.show', $strategy)],
            ['label' => __('strategic_plans.title')],
        ]"
        :title="__('strategic_plans.title_for', ['program' => $program->name])"
        :subtitle="__('strategic_plans.span', ['strategy' => $strategy->name, 'start' => $strategy->start_year, 'end' => $strategy->end_year])"
    >
        <x-slot:meta>
            @if ($status === null)
                <span class="dk-pill dk-mute">{{ __('strategic_plans.not_started') }}</span>
            @else
                <span class="dk-pill {{ match (true) { $status === \App\Enums\StrategicPlanStatus::Approved => 'dk-good', $status === \App\Enums\StrategicPlanStatus::Submitted => 'dk-act', $wasSentBack => 'dk-bad', default => '' } }}" data-test="strategic-plan-status">{{ $wasSentBack ? __('strategic_plans.sent_back') : $status->label() }}</span>
            @endif
            <span>{{ __('strategic_plans.who', ['manager' => $program->manager?->name ?? __('strategic_plans.general_manager')]) }}</span>
            @if ($plan?->approved_at !== null)
                <span class="tabular-nums">{{ __('strategic_plans.approved_by_on', ['name' => $plan->approver?->name ?? '—', 'date' => $formatDate($plan->approved_at)]) }}</span>
            @endif
        </x-slot:meta>
    </x-ui.detail-header>

    @error('plan')
        <p class="rounded-lg border border-primary/30 bg-primary/5 px-4 py-3 text-sm font-semibold text-primary dark:text-brand-text" role="alert" data-test="strategic-plan-error">{{ $message }}</p>
    @enderror

    @if ($wasSentBack)
        <div class="rounded-lg border border-status-warn/40 bg-status-warn-soft px-4 py-3 text-sm text-secondary-dark dark:text-ink" role="status" data-test="strategic-plan-sent-back">
            <p class="font-semibold">{{ __('strategic_plans.sent_back_by', ['name' => $plan->sender?->name ?? '—', 'date' => $formatDate($plan->sent_back_at)]) }}</p>
            <p class="mt-1" dir="auto">“{{ $plan->sent_back_reason }}”</p>
        </div>
    @endif

    @if ($status === \App\Enums\StrategicPlanStatus::Submitted)
        <div class="rounded-lg border border-secondary-soft bg-white px-4 py-3 text-sm text-secondary-dark shadow-card dark:border-line dark:bg-surface-1 dark:text-ink" role="status" data-test="strategic-plan-waiting">
            {{ $this->canReview ? __('strategic_plans.waiting_for_you') : __('strategic_plans.waiting_for_manager', ['manager' => $program->manager?->name ?? __('strategic_plans.general_manager')]) }}
        </div>
    @endif

    {{-- 1. What must be achieved, year by year. --}}
    <section aria-labelledby="strategic-plan-goals" class="flex flex-col gap-3">
        <div>
            <h2 id="strategic-plan-goals" class="text-base font-bold text-secondary-dark dark:text-ink">{{ __('strategic_plans.goals_heading') }}</h2>
            <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('strategic_plans.goals_intro') }}</p>
        </div>

        <div class="grid gap-4 @3xl/detail:grid-cols-2">
            @foreach ($this->years as $year)
                <x-ui.panel wire:key="plan-year-{{ $year }}" class="p-4" data-test="plan-year-{{ $year }}">
                    <p class="mb-2 text-sm font-bold tabular-nums text-secondary-dark dark:text-ink">{{ $year }}</p>
                    @if ($canWrite)
                        <x-ui.form-textarea wire:model="goals.{{ $year }}" :label="__('strategic_plans.goals_for_year', ['year' => $year])" rows="4" />
                    @elseif (filled($goals[$year] ?? null))
                        <p dir="auto" class="whitespace-pre-line text-sm text-secondary-dark dark:text-ink">{{ $goals[$year] }}</p>
                    @else
                        <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('strategic_plans.no_goals_yet') }}</p>
                    @endif
                </x-ui.panel>
            @endforeach
        </div>
    </section>

    {{-- 2. The program indicators that measure it, with a figure for each year. --}}
    <section aria-labelledby="strategic-plan-indicators" class="flex flex-col gap-3">
        <div>
            <h2 id="strategic-plan-indicators" class="text-base font-bold text-secondary-dark dark:text-ink">{{ __('strategic_plans.indicators_heading') }}</h2>
            <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('strategic_plans.indicators_intro') }}</p>
        </div>

        <x-ui.panel>
            @if ($linkedIndicators === [])
                <p class="px-4 py-5 text-sm text-secondary-medium dark:text-ink-muted" data-test="no-linked-indicators">
                    {{ $this->programIndicators->isEmpty() ? __('strategic_plans.program_has_no_indicators') : __('strategic_plans.no_linked_indicators') }}
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-sm">
                        <thead class="bg-board-head text-start type-label text-secondary-medium dark:text-ink-muted">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 text-start">{{ __('common.indicators') }}</th>
                                @foreach ($this->years as $year)
                                    <th scope="col" class="w-28 px-2 py-2.5 text-end tabular-nums">{{ $year }}</th>
                                @endforeach
                                @if ($canWrite)
                                    <th scope="col" class="w-12 px-2 py-2.5"><span class="sr-only">{{ __('strategic_plans.remove') }}</span></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-secondary-soft dark:divide-line">
                            @foreach ($linkedIndicators as $indicatorId)
                                @php $indicator = $indicatorsById->get($indicatorId); @endphp
                                @continue($indicator === null)
                                <tr wire:key="plan-indicator-{{ $indicatorId }}" data-test="plan-indicator-{{ $indicatorId }}">
                                    <td class="px-4 py-2.5 align-top">
                                        <p class="flex flex-wrap items-center gap-2">
                                            <x-ui.enum-badge :case="$indicator->result->type" />
                                            <bdi class="font-semibold text-secondary-dark dark:text-ink">{{ $indicator->name }}</bdi>
                                        </p>
                                        <p class="mt-0.5 type-meta text-secondary-medium dark:text-ink-muted" dir="auto">{{ Str::limit($indicator->result->statement, 90) }}@if (filled($indicator->unit)) · {{ $indicator->unit }}@endif @if ($indicator->measure === \App\Enums\IndicatorMeasure::Percentage) · %@endif</p>
                                    </td>
                                    @foreach ($this->years as $year)
                                        <td class="px-2 py-2 text-end align-top tabular-nums">
                                            @if ($canWrite)
                                                <label class="sr-only" for="target-{{ $indicatorId }}-{{ $year }}">{{ __('strategic_plans.figure_for', ['indicator' => $indicator->name, 'year' => $year]) }}</label>
                                                <div class="form-box ms-auto w-24" @error("targets.{$indicatorId}.{$year}") data-invalid @enderror>
                                                    <input id="target-{{ $indicatorId }}-{{ $year }}" type="number" step="0.01" min="0" @if ($indicator->measure === \App\Enums\IndicatorMeasure::Percentage) max="100" @endif wire:model="targets.{{ $indicatorId }}.{{ $year }}" class="form-box-input text-end tabular-nums" data-test="target-{{ $indicatorId }}-{{ $year }}" />
                                                </div>
                                                @error("targets.{$indicatorId}.{$year}")<p class="mt-1 text-xs text-primary dark:text-brand-text">{{ $message }}</p>@enderror
                                            @else
                                                <span class="font-semibold text-secondary-dark dark:text-ink">{{ filled($targets[$indicatorId][$year] ?? null) ? $targets[$indicatorId][$year] : '—' }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    @if ($canWrite)
                                        <td class="px-2 py-2 align-top">
                                            <x-ui.button tone="ghost" size="sm" icon="x-mark" class="size-8!" wire:click="removeIndicator({{ $indicatorId }})" :aria-label="__('strategic_plans.remove_named', ['name' => $indicator->name])" data-test="remove-plan-indicator-{{ $indicatorId }}" />
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($canWrite && $unlinked->isNotEmpty())
                <div class="flex flex-wrap items-end gap-3 border-t border-secondary-soft px-4 py-3 dark:border-line">
                    <x-ui.form-select wire:model="indicatorToAdd" :label="__('strategic_plans.add_indicator')" :placeholder="__('strategic_plans.choose_indicator')" class="min-w-64 flex-1">
                        @foreach ($unlinked as $indicator)
                            <option value="{{ $indicator->id }}">{{ $indicator->result->type->label() }} · {{ Str::limit($indicator->name, 80) }}</option>
                        @endforeach
                    </x-ui.form-select>
                    <x-ui.button icon="plus" wire:click="addIndicator" data-test="add-plan-indicator">{{ __('strategic_plans.link') }}</x-ui.button>
                </div>
            @endif
        </x-ui.panel>
    </section>

    {{-- What the viewer can do next. --}}
    @if ($canWrite || $this->canReview || $this->canReopen)
        <div class="flex flex-wrap items-center justify-end gap-2" data-test="strategic-plan-actions">
            @if ($canWrite)
                <x-ui.button wire:click="save" wire:loading.attr="disabled" data-test="save-strategic-plan">{{ __('strategic_plans.save_draft') }}</x-ui.button>
                <x-ui.button tone="primary" icon="paper-airplane" wire:click="submit" wire:loading.attr="disabled" data-test="submit-strategic-plan">{{ __('strategic_plans.send_to_manager') }}</x-ui.button>
            @endif
            @if ($this->canReview)
                <x-ui.button wire:click="openSendBack" data-test="send-back-strategic-plan">{{ __('strategic_plans.send_back_action') }}</x-ui.button>
                <x-ui.button tone="primary" icon="check" wire:click="approve" wire:loading.attr="disabled" data-test="approve-strategic-plan">{{ __('strategic_plans.approve') }}</x-ui.button>
            @endif
            @if ($this->canReopen)
                <x-ui.button icon="pencil-square" wire:click="reopen" data-test="reopen-strategic-plan">{{ __('strategic_plans.reopen') }}</x-ui.button>
            @endif
        </div>
    @endif

    <x-ui.modal wire:model="showSendBackModal" :title="__('strategic_plans.send_back_title')" :description="__('strategic_plans.send_back_description')" submit="sendBack" close="closeSendBack">
        <x-ui.form-textarea wire:model="sendBackReason" :label="__('strategic_plans.send_back_reason')" required rows="4" />

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="confirm-send-back">{{ __('strategic_plans.send_back_action') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
</section>
