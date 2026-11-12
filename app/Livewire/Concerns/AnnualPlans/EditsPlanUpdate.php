<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\AnnualPlans;

use App\Actions\AnnualPlans\ManagePlanUpdateFiles;
use App\Actions\AnnualPlans\ReviewPlanUpdate;
use App\Actions\AnnualPlans\SavePlanUpdate;
use App\Actions\AnnualPlans\SendPlanUpdate;
use App\Enums\PlanWeekStatus;
use App\Models\ActivityPlan;
use App\Models\ActivityPlanBatch;
use App\Models\ActivityPlanItem;
use App\Models\PlanUpdate;
use App\Models\PlanUpdateFile;
use App\Models\Project;
use App\Models\User;
use App\Policies\PlanUpdatePolicy;
use App\Support\PlanCalendar;
use App\Support\PlanExecution;
use Carbon\CarbonImmutable;
use Closure;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The monthly update page (2026-11-11 workflow): the project manager's four
 * steps in two parts (activity tracking: weeks, numbers and files; monthly
 * report: narrative and beneficiaries; then check and send), and the review
 * chain for the MEAL officer, the MEAL manager and the program manager.
 * Writes go through App\Actions\AnnualPlans; nothing entered is ever lost on
 * a return.
 *
 * @property-read ActivityPlan $plan
 * @property-read Project $project
 * @property-read CarbonImmutable $monthStart
 * @property-read PlanExecution $execution
 * @property-read PlanUpdate|null $record
 */
trait EditsPlanUpdate
{
    #[Locked]
    public int $planId;

    #[Locked]
    public int $projectId;

    #[Locked]
    public string $month;

    public int $step = 0;

    /** @var array<string, mixed> */
    public array $draft = [];

    /** @var list<string> keys SendPlanUpdate::missing() reported */
    public array $missing = [];

    /** @var array<int, mixed> */
    public array $newFiles = [];

    public string $accountability = '';

    public string $reason = '';

    public string $question = '';

    public string $returnTo = 'project_manager';

    public string $pmNote = '';

    public string $answer = '';

    public ?int $previewFileId = null;

    #[Computed]
    public function plan(): ActivityPlan
    {
        return ActivityPlan::query()->with(['proposal', 'items.activity:id,statement', 'items.batches', 'items.cities'])->findOrFail($this->planId);
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->findOrFail($this->projectId);
    }

    #[Computed]
    public function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month)->startOfMonth();
    }

    #[Computed]
    public function execution(): PlanExecution
    {
        return PlanExecution::for($this->plan);
    }

    #[Computed]
    public function record(): ?PlanUpdate
    {
        return PlanUpdate::query()
            ->where('activity_plan_id', $this->planId)->where('project_id', $this->projectId)->where('month', $this->monthStart->toDateString())
            ->with(['weeks', 'numbers', 'files', 'questions.user', 'sender', 'officer', 'reviewer', 'seer', 'returner'])
            ->first();
    }

    /**
     * fill | officer | meal | program | answer | read
     */
    #[Computed]
    public function mode(): string
    {
        $user = $this->user();
        $update = $this->record;

        return match (true) {
            PlanUpdatePolicy::canFill($user, $this->plan, $this->project, $update) => 'fill',
            $update !== null && $user->can('decideAsOfficer', $update) => 'officer',
            $update !== null && $user->can('decideAsMealManager', $update) => 'meal',
            $update !== null && $user->can('markSeen', $update) => 'program',
            $update !== null && $user->can('answer', $update) && $update->questions->isNotEmpty() => 'answer',
            default => 'read',
        };
    }

    /**
     * @return list<array{item: ActivityPlanItem, batch: ActivityPlanBatch, index: int}>
     */
    #[Computed]
    public function rows(): array
    {
        return $this->execution->batchesFor($this->project, $this->monthStart);
    }

    /**
     * @return list<CarbonImmutable>
     */
    #[Computed]
    public function mondays(): array
    {
        return $this->execution->calendar->mondaysOf($this->monthStart);
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function blockers(): array
    {
        return $this->execution->sendBlockers($this->project, $this->monthStart);
    }

    public function mountEditsPlanUpdate(): void
    {
        $this->loadDraft();
        $this->accountability = (string) ($this->record?->accountability ?? '');
    }

    // ---- project manager --------------------------------------------------------

    public function goToStep(int $step): void
    {
        if ($this->mode !== 'fill' || $step < 0 || $step > 3) {
            return;
        }

        if ($this->saveDraft(false)) {
            $this->step = $step;
        }
    }

    public function fillFromPlan(): void
    {
        foreach ($this->rows as $row) {
            $batch = $row['batch'];

            foreach ($this->mondays as $monday) {
                $key = $batch->id.'|'.$monday->toDateString();

                if (filled($this->draft['weeks'][$key]['status'] ?? null) || ! $monday->betweenIncluded($batch->start_week, $batch->end_week)) {
                    continue;
                }

                $status = $row['item']->is_single_week ? PlanWeekStatus::Conducted
                    : ($monday->equalTo($batch->start_week) ? PlanWeekStatus::Started : ($monday->equalTo($batch->end_week) ? PlanWeekStatus::Completed : PlanWeekStatus::Ongoing));
                $this->draft['weeks'][$key] = ['status' => $status->value, 'reason' => '', 'new_week' => ''];
            }
        }

        Flux::toast(text: __('annual_plans.up.filled_from_plan'));
    }

    public function saveDraft(bool $toast = true): bool
    {
        $saved = $this->run(function (): void {
            app(SavePlanUpdate::class)->handle($this->user(), $this->plan, $this->project, $this->monthStart, $this->draft);
        });

        if ($saved) {
            unset($this->record, $this->execution, $this->mode);

            if ($toast) {
                Flux::toast(variant: 'success', text: __('annual_plans.up.draft_saved'));
            }
        }

        return $saved;
    }

    public function updatedNewFiles(): void
    {
        $this->run(function (): void {
            foreach ($this->newFiles as $file) {
                app(ManagePlanUpdateFiles::class)->add($this->user(), $this->plan, $this->project, $this->monthStart, $file);
            }
        });
        $this->newFiles = [];
        unset($this->record, $this->mode);
    }

    public function removeFile(int $fileId): void
    {
        $file = PlanUpdateFile::query()->where('plan_update_id', $this->record?->id)->find($fileId);

        if ($file !== null) {
            $this->run(fn () => app(ManagePlanUpdateFiles::class)->remove($this->user(), $file));
            unset($this->record);
        }
    }

    public function sendUpdate(): void
    {
        if (! $this->saveDraft(false)) {
            return;
        }

        $update = $this->record;
        $this->missing = $update === null ? [] : SendPlanUpdate::missing($this->execution, $this->project, $update);

        $this->run(function () use ($update): void {
            if ($update === null) {
                throw ValidationException::withMessages(['send' => __('annual_plans.up.incomplete', ['count' => 1])]);
            }

            app(SendPlanUpdate::class)->handle($this->user(), $update);
            $this->missing = [];
            $this->refreshUpdate();
            Flux::toast(variant: 'success', text: __('annual_plans.up.sent_toast'));
        });
    }

    public function sendAnswer(): void
    {
        $this->run(function (): void {
            app(ReviewPlanUpdate::class)->answer($this->user(), $this->record, $this->answer);
            $this->answer = '';
            $this->refreshUpdate();
        });
    }

    // ---- reviewers ---------------------------------------------------------------------

    public function officerApprove(): void
    {
        $this->decide(fn () => app(ReviewPlanUpdate::class)->officerApprove($this->user(), $this->record, $this->accountability), __('annual_plans.up.officer_approved_toast'));
    }

    public function officerReturn(): void
    {
        $this->decide(fn () => app(ReviewPlanUpdate::class)->officerReturn($this->user(), $this->record, $this->reason, $this->accountability), __('annual_plans.up.returned_toast'));
    }

    public function mealReviewed(): void
    {
        $this->decide(fn () => app(ReviewPlanUpdate::class)->mealReviewed($this->user(), $this->record), __('annual_plans.up.reviewed_toast'));
    }

    public function mealReturn(): void
    {
        $this->decide(fn () => app(ReviewPlanUpdate::class)->mealReturn($this->user(), $this->record, $this->reason, $this->returnTo === 'officer' ? 'officer' : 'project_manager'), __('annual_plans.up.returned_toast'));
    }

    public function askQuestion(): void
    {
        $this->decide(fn () => app(ReviewPlanUpdate::class)->ask($this->user(), $this->record, $this->question), __('annual_plans.up.question_toast'));
        $this->question = '';
    }

    public function markSeen(): void
    {
        $this->decide(fn () => app(ReviewPlanUpdate::class)->markSeen($this->user(), $this->record, $this->pmNote), __('annual_plans.up.seen_toast'));
    }

    public function togglePreview(int $fileId): void
    {
        $this->previewFileId = $this->previewFileId === $fileId ? null : $fileId;
    }

    // ---- view helpers --------------------------------------------------------------------------

    public function isMissing(string $key): bool
    {
        return in_array($key, $this->missing, true);
    }

    public function dueDate(): CarbonImmutable
    {
        return PlanCalendar::dueDateOf($this->monthStart);
    }

    public function isQuarter(): bool
    {
        return $this->execution->calendar->isQuarterEnd($this->monthStart);
    }

    /**
     * Plan Monday => "Week of 4 Jan", after a given week, for Rescheduled.
     *
     * @return array<string, string>
     */
    public function laterWeeks(string $after): array
    {
        $options = [];

        foreach ($this->execution->calendar->mondays() as $monday) {
            if ($monday->toDateString() > $after && count($options) < 12) {
                $options[$monday->toDateString()] = __('annual_plans.wf.week_of', ['date' => $monday->translatedFormat('j M')]);
            }
        }

        return $options;
    }

    // ---- internals ---------------------------------------------------------------------------------

    private function decide(Closure $action, string $success): void
    {
        $this->run(function () use ($action, $success): void {
            $action();
            $this->reason = '';
            $this->refreshUpdate();
            Flux::toast(variant: 'success', text: $success);
        });
    }

    private function loadDraft(): void
    {
        $update = $this->record;
        $weeks = [];
        $numbers = [];

        foreach ($update?->weeks ?? [] as $week) {
            $weeks[$week->activity_plan_batch_id.'|'.$week->week->toDateString()] = [
                'status' => $week->status->value,
                'reason' => (string) $week->reason,
                'new_week' => (string) $week->new_week?->toDateString(),
            ];
        }

        foreach ($update?->numbers ?? [] as $number) {
            $numbers[$number->activity_plan_batch_id] = (string) $number->done_count;
        }

        $this->draft = [
            'weeks' => $weeks,
            'numbers' => $numbers,
            'activities_implemented' => (string) ($update?->activities_implemented ?? ''),
            'challenges' => (string) ($update?->challenges ?? ''),
            'next_month' => (string) ($update?->next_month ?? ''),
            'males' => $update?->males === null ? '' : (string) $update->males,
            'females' => $update?->females === null ? '' : (string) $update->females,
            'with_disability' => $update?->with_disability === null ? '' : (string) $update->with_disability,
        ];
    }

    private function refreshUpdate(): void
    {
        unset($this->record, $this->execution, $this->mode, $this->blockers);
    }

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
            $this->refreshUpdate();
            Flux::toast(variant: 'danger', text: __('annual_plans.wf.not_allowed'));
        }

        return false;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
