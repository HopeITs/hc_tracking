<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\AnnualPlans;

use App\Actions\AnnualPlans\ChooseActivityPlanActivities;
use App\Actions\AnnualPlans\CopyActivityPlanItemSettings;
use App\Actions\AnnualPlans\ManageActivityPlanFollowUps;
use App\Actions\AnnualPlans\ReviewActivityPlan;
use App\Actions\AnnualPlans\SaveActivityPlanItem;
use App\Actions\AnnualPlans\SubmitActivityPlan;
use App\Enums\ActivityPlanStatus;
use App\Enums\CountUnit;
use App\Enums\FollowUpTrigger;
use App\Models\ActivityPlan;
use App\Models\ActivityPlanBatch;
use App\Models\ActivityPlanItem;
use App\Models\Result;
use App\Models\User;
use App\Support\ActivityPlanScope;
use App\Support\PlanCalendar;
use Carbon\CarbonImmutable;
use Closure;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The plan page of annual-plans (2026-11-11 workflow): the program manager's
 * four steps (proposal, activities, dates and places, check and send), the
 * MEAL manager's read-only review with follow-up tasks, and the approved
 * plan. Every write goes through an App\Actions\AnnualPlans action, which
 * authorizes; this trait only keeps the form of the open activity.
 *
 * @property-read ActivityPlan $plan
 * @property-read PlanCalendar $calendar
 */
trait EditsActivityPlan
{
    #[Locked]
    public int $planId;

    /** @var list<int|string> the activities ticked on step 2 */
    public array $picked = [];

    #[Locked]
    public ?int $openItemId = null;

    /**
     * The open activity's form: single_week, unit, unit_other, cities,
     * expected_people, note, batches[{start, end, count, hours}].
     *
     * @var array<string, mixed>
     */
    public array $form = [];

    /** @var list<int|string> */
    public array $copyTargets = [];

    public string $followUpItem = '';

    public string $followUpTrigger = 'finish';

    public string $followUpText = '';

    public string $sendBackReason = '';

    #[Computed]
    public function plan(): ActivityPlan
    {
        return ActivityPlan::query()
            ->with(['proposal', 'items.activity:id,statement,activity_kind', 'items.batches', 'items.cities', 'followUps', 'submitter', 'approver', 'sentBackBy'])
            ->findOrFail($this->planId);
    }

    #[Computed]
    public function calendar(): PlanCalendar
    {
        return PlanCalendar::forProposal($this->plan->proposal);
    }

    /**
     * Plan Monday => "Week of 4 Jan".
     *
     * @return array<string, string>
     */
    #[Computed]
    public function weekOptions(): array
    {
        $options = [];

        foreach ($this->calendar->mondays() as $monday) {
            $options[$monday->toDateString()] = __('annual_plans.wf.week_of', ['date' => $monday->translatedFormat('j M')]);
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function cityNames(): array
    {
        return ActivityPlanScope::cityNames(ActivityPlanScope::cityIds($this->plan));
    }

    /**
     * @return list<array{outcome: string, activities: list<Result>}>
     */
    #[Computed]
    public function activityGroups(): array
    {
        return ActivityPlanScope::activityGroups($this->plan);
    }

    /**
     * The other programs' plans of the same proposal, for the shared calendar.
     *
     * @return Collection<int, ActivityPlan>
     */
    #[Computed]
    public function siblingPlans(): Collection
    {
        return ActivityPlan::query()
            ->where('proposal_id', $this->plan->proposal_id)
            ->whereKeyNot($this->plan->id)
            ->with(['items.activity:id,statement', 'items.batches', 'items.cities'])
            ->get();
    }

    /**
     * What the signed-in user does on this plan: plan (edit a draft), wait
     * (their plan is with MEAL), review, or read.
     */
    #[Computed]
    public function mode(): string
    {
        $user = $this->user();
        $plan = $this->plan;

        return match (true) {
            $user->can('update', $plan) => 'plan',
            $user->can('review', $plan) => 'review',
            $plan->status === ActivityPlanStatus::InReview && $user->id === $plan->submitted_by => 'wait',
            default => 'read',
        };
    }

    public function mountEditsActivityPlan(): void
    {
        $this->picked = array_values($this->plan->items->pluck('result_id')->map(fn (int $id): string => (string) $id)->all());

        if ($this->mode === 'plan' && $this->plan->step === 2) {
            $this->openFirstUnready();
        }
    }

    // ---- navigation ---------------------------------------------------------

    public function goToStep(int $step): void
    {
        $plan = $this->plan;

        if (! $this->user()->can('update', $plan) || $step < 0 || $step > 3) {
            return;
        }

        if ($step >= 2 && $plan->items->isEmpty()) {
            return;
        }

        if ($step === 3 && $plan->items->contains('is_ready', false)) {
            Flux::toast(variant: 'warning', text: __('annual_plans.not_all_ready'));

            return;
        }

        if ($this->openItemId !== null && ! $this->saveOpenItem(false)) {
            return;
        }

        $plan->forceFill(['step' => $step])->save();
        $this->openItemId = null;
        $this->refreshPlan();

        if ($step === 2) {
            $this->openFirstUnready();
        }
    }

    // ---- step 2: activities -------------------------------------------------

    public function selectAllActivities(): void
    {
        $this->picked = array_map('strval', ActivityPlanScope::activityIds($this->plan));
    }

    public function saveActivities(): void
    {
        $this->run(function (): void {
            app(ChooseActivityPlanActivities::class)->handle($this->user(), $this->plan, array_map('intval', $this->picked));
            $this->refreshPlan();
            $this->openFirstUnready();
        });
    }

    // ---- step 3: dates & places ----------------------------------------------

    public function toggleItem(int $itemId): void
    {
        if ($this->openItemId === $itemId) {
            if ($this->saveOpenItem(false)) {
                $this->openItemId = null;
            }

            return;
        }

        if ($this->openItemId !== null && ! $this->saveOpenItem(false)) {
            return;
        }

        $this->openItem($itemId);
    }

    public function setRunsOnSingleWeek(bool $single): void
    {
        $this->form['single_week'] = $single;
        $lastIndex = $this->calendar->weekCount() - 1;
        $mondays = $this->calendar->mondays();

        foreach ($this->form['batches'] as $index => $batch) {
            if ($single) {
                $this->form['batches'][$index]['end'] = $batch['start'];

                continue;
            }

            $start = CarbonImmutable::parse($batch['start']);
            $end = CarbonImmutable::parse($batch['end'] ?: $batch['start']);
            $minimum = $mondays[min($lastIndex, $this->calendar->indexOf($start) + 3)];
            $this->form['batches'][$index]['end'] = ($end->lt($minimum) ? $minimum : $end)->toDateString();
        }
    }

    public function addBatch(): void
    {
        $mondays = $this->calendar->mondays();
        $lastIndex = count($mondays) - 1;
        $last = end($this->form['batches']) ?: ['start' => $mondays[0]->toDateString(), 'end' => $mondays[0]->toDateString(), 'count' => null, 'hours' => null];
        $single = (bool) $this->form['single_week'];
        $base = $this->calendar->indexOf(CarbonImmutable::parse($single ? $last['start'] : $last['end']));
        $start = $single ? min($lastIndex, $base + 4) : max(0, min($lastIndex, $base - 1));
        $end = $single ? $start : min($lastIndex, $start + 7);

        $this->form['batches'][] = [
            'start' => $mondays[$start]->toDateString(),
            'end' => $mondays[$end]->toDateString(),
            'count' => $last['count'] ?? null,
            'hours' => $last['hours'] ?? null,
        ];
    }

    public function removeBatch(int $index): void
    {
        if (count($this->form['batches']) <= 1) {
            return;
        }

        unset($this->form['batches'][$index]);
        $this->form['batches'] = array_values($this->form['batches']);
    }

    /**
     * "Done · next activity": save and mark Ready, then open the next one
     * that still needs details.
     */
    public function finishItem(): void
    {
        if (! $this->saveOpenItem(true)) {
            return;
        }

        $order = $this->plan->items->pluck('id')->all();
        $position = array_search($this->openItemId, $order, true);
        $next = $this->plan->items->slice(((int) $position) + 1)->firstWhere('is_ready', false)
            ?? $this->plan->items->firstWhere('is_ready', false);
        $this->openItemId = null;

        if ($next !== null) {
            $this->openItem($next->id);

            return;
        }

        Flux::toast(variant: 'success', text: __('annual_plans.wf.all_ready'));
    }

    public function copySettings(): void
    {
        if ($this->openItemId === null || ! $this->saveOpenItem(false)) {
            return;
        }

        $this->run(function (): void {
            $source = $this->plan->items->firstWhere('id', $this->openItemId);
            $count = app(CopyActivityPlanItemSettings::class)->handle($this->user(), $source, array_map('intval', $this->copyTargets));
            $this->copyTargets = [];
            $this->refreshPlan();
            Flux::toast(variant: 'success', text: trans_choice('annual_plans.wf.copied', $count, ['count' => $count]));
        });
    }

    // ---- step 4: send ------------------------------------------------------------

    public function submitPlan(): void
    {
        $this->run(function (): void {
            app(SubmitActivityPlan::class)->handle($this->user(), $this->plan);
            $this->refreshPlan();
            Flux::toast(variant: 'success', text: __('annual_plans.wf.sent_to_meal'));
        });
    }

    // ---- MEAL review ----------------------------------------------------------------

    public function addFollowUp(): void
    {
        $this->run(function (): void {
            if ($this->followUpItem === '') {
                throw ValidationException::withMessages(['followUpItem' => __('annual_plans.wf.follow_up_pick_activity')]);
            }

            app(ManageActivityPlanFollowUps::class)->add($this->user(), $this->plan, (int) $this->followUpItem, $this->followUpTrigger, $this->followUpText);
            $this->followUpText = '';
            $this->refreshPlan();
        });
    }

    public function removeFollowUp(int $followUpId): void
    {
        $this->run(function () use ($followUpId): void {
            app(ManageActivityPlanFollowUps::class)->remove($this->user(), $this->plan, $followUpId);
            $this->refreshPlan();
        });
    }

    public function approvePlan(): void
    {
        $this->run(function (): void {
            app(ReviewActivityPlan::class)->approve($this->user(), $this->plan);
            $this->refreshPlan();
            Flux::toast(variant: 'success', text: __('annual_plans.wf.approved_toast'));
        });
    }

    public function sendBackPlan(): void
    {
        $this->run(function (): void {
            app(ReviewActivityPlan::class)->sendBack($this->user(), $this->plan, $this->sendBackReason);
            $this->sendBackReason = '';
            $this->refreshPlan();
            Flux::toast(variant: 'success', text: __('annual_plans.wf.sent_back_toast'));
        });
    }

    // ---- reading helpers used by the view ---------------------------------------------

    /**
     * "24 sessions · 48 hours in total" for an item, from the form when open.
     *
     * @param  list<array{count: mixed, hours: mixed}>  $batches
     */
    public function totalsLine(CountUnit $unit, ?string $otherName, array $batches): string
    {
        $count = 0;
        $hours = 0.0;

        foreach ($batches as $batch) {
            $count += (int) ($batch['count'] ?? 0);
            $hours += (int) ($batch['count'] ?? 0) * (float) ($batch['hours'] ?? 0);
        }

        $line = $unit->counted($count, $otherName);

        return $unit->needsHours() ? $line.' · '.trans_choice('annual_plans.wf.hours_total', (int) round($hours), ['hours' => rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.')]) : $line;
    }

    /**
     * An item's batches as form rows, for the read-only view and the gantt.
     *
     * @return list<array{start: string, end: string, count: int|null, hours: float|null}>
     */
    public function batchRows(ActivityPlanItem $item): array
    {
        return array_values($item->batches->map(fn (ActivityPlanBatch $batch): array => [
            'start' => $batch->start_week->toDateString(),
            'end' => $batch->end_week->toDateString(),
            'count' => $batch->planned_count,
            'hours' => $batch->hours_each === null ? null : (float) $batch->hours_each,
        ])->all());
    }

    /**
     * Month labels and batch bars for a timeline, positioned in % of the plan.
     *
     * @param  list<array{start: string, end: string}>  $batches
     * @return array{months: list<array{left: float, label: string}>, bars: list<array{left: float, width: float, single: bool, alt: bool, title: string}>}
     */
    public function timeline(array $batches, bool $single): array
    {
        $calendar = $this->calendar;
        $weeks = max(1, $calendar->weekCount());
        $months = [];
        $lastMonth = null;

        foreach ($calendar->mondays() as $index => $monday) {
            if ($monday->format('Y-m') !== $lastMonth) {
                $lastMonth = $monday->format('Y-m');
                $months[] = ['left' => $index / $weeks * 100, 'label' => $monday->translatedFormat('M')];
            }
        }

        $bars = [];

        foreach ($batches as $index => $batch) {
            if (blank($batch['start'] ?? null)) {
                continue;
            }

            $start = CarbonImmutable::parse($batch['start']);
            $end = $single || blank($batch['end'] ?? null) ? $start : CarbonImmutable::parse($batch['end']);
            $from = $calendar->indexOf($start);
            $to = max($from, $calendar->indexOf($end));
            $bars[] = [
                'left' => ($single ? $from + .5 : $from) / $weeks * 100,
                'width' => ($to - $from + 1) / $weeks * 100,
                'single' => $single,
                'alt' => $index % 2 === 1,
                'title' => $single ? __('annual_plans.wf.week_of', ['date' => $start->translatedFormat('j M')]) : $start->translatedFormat('j M').' – '.$end->translatedFormat('j M'),
            ];
        }

        return ['months' => $months, 'bars' => $bars];
    }

    /**
     * Whether an item is ready to send (for the "Ready" badge).
     */
    public function itemDone(ActivityPlanItem $item): bool
    {
        return $item->is_ready;
    }

    /**
     * @return list<FollowUpTrigger>
     */
    public function followUpTriggers(): array
    {
        return [FollowUpTrigger::Finish, FollowUpTrigger::Start, FollowUpTrigger::Middle];
    }

    // ---- internals -----------------------------------------------------------------------------

    private function openItem(int $itemId): void
    {
        $item = $this->plan->items->firstWhere('id', $itemId);

        if ($item === null) {
            return;
        }

        $this->openItemId = $item->id;
        $this->copyTargets = [];
        $this->resetErrorBag();
        $this->form = [
            'single_week' => $item->is_single_week,
            'unit' => $item->unit->value,
            'unit_other' => (string) ($item->unit_other ?? ''),
            'cities' => array_map('strval', $item->cityIds()),
            'expected_people' => $item->expected_people === null ? '' : (string) $item->expected_people,
            'note' => (string) ($item->note ?? ''),
            'batches' => $this->batchRows($item),
        ];
    }

    private function openFirstUnready(): void
    {
        $first = $this->plan->items->firstWhere('is_ready', false) ?? $this->plan->items->first();

        if ($first !== null && ! $first->is_ready) {
            $this->openItem($first->id);
        }
    }

    private function saveOpenItem(bool $ready): bool
    {
        $item = $this->plan->items->firstWhere('id', $this->openItemId);

        if ($item === null) {
            $this->openItemId = null;

            return true;
        }

        $saved = $this->run(function () use ($item, $ready): void {
            app(SaveActivityPlanItem::class)->handle($this->user(), $item, $this->form, $ready);
        });

        if ($saved) {
            $this->refreshPlan();
        }

        return $saved;
    }

    /**
     * Run an action; validation errors land in the error bag (and a toast),
     * a refused authorization is a toast.
     */
    private function run(Closure $action): bool
    {
        try {
            $this->resetErrorBag();
            $action();

            return true;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError($key, $messages[0]);
            }

            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());
        } catch (AuthorizationException) {
            $this->refreshPlan();
            Flux::toast(variant: 'danger', text: __('annual_plans.wf.not_allowed'));
        }

        return false;
    }

    private function refreshPlan(): void
    {
        unset($this->plan, $this->siblingPlans, $this->mode);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
