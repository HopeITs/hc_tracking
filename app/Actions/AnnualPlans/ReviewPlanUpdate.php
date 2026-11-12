<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\PlanUpdateStatus;
use App\Models\PlanUpdate;
use App\Models\PlanUpdateQuestion;
use App\Models\User;
use App\Support\PlanNotifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The review chain of a monthly update (2026-11-11, Rami's notes):
 *
 * 1. MEAL officer (3 days): writes Accountability & Feedback (needed to
 *    approve), then approves, returns it to the project manager with a
 *    reason, or asks a question.
 * 2. MEAL manager (3 days after the officer): marks it reviewed, returns it
 *    with a reason to the project manager or to the officer, or asks.
 * 3. Program manager: marks it seen, with an optional note to the project
 *    manager; the update stays locked.
 *
 * A return never deletes anything: the project manager (or the officer)
 * changes only what is needed and sends again.
 */
final class ReviewPlanUpdate
{
    public function officerApprove(User $user, PlanUpdate $update, string $accountability): PlanUpdate
    {
        Gate::forUser($user)->authorize('decideAsOfficer', $update);
        $accountability = trim($accountability);

        if ($accountability === '') {
            throw ValidationException::withMessages(['accountability' => __('annual_plans.up.accountability_required')]);
        }

        $moved = $this->move($update, PlanUpdateStatus::Sent, [
            'status' => PlanUpdateStatus::OfficerApproved,
            'accountability' => $accountability,
            'officer_approved_at' => now(),
            'officer_approved_by' => $user->id,
            'returned_to' => null,
            'return_reason' => null,
        ]);
        PlanNotifier::update($moved, 'annual_plan.update_officer_approved', ['project_manager', 'meal', 'program_manager'], [], $user);

        return $moved;
    }

    public function officerReturn(User $user, PlanUpdate $update, string $reason, ?string $accountability = null): PlanUpdate
    {
        Gate::forUser($user)->authorize('decideAsOfficer', $update);

        $moved = $this->move($update, PlanUpdateStatus::Sent, [
            'status' => PlanUpdateStatus::Returned,
            'accountability' => filled($accountability) ? trim((string) $accountability) : $update->accountability,
            'returned_at' => now(),
            'returned_by' => $user->id,
            'returned_to' => 'project_manager',
            'return_reason' => $this->reason($reason),
        ]);
        PlanNotifier::update($moved, 'annual_plan.update_returned', ['project_manager'], ['reason' => (string) $moved->return_reason], $user, true);

        return $moved;
    }

    public function mealReviewed(User $user, PlanUpdate $update): PlanUpdate
    {
        Gate::forUser($user)->authorize('decideAsMealManager', $update);

        $moved = $this->move($update, PlanUpdateStatus::OfficerApproved, [
            'status' => PlanUpdateStatus::Reviewed,
            'reviewed_at' => now(),
            'reviewed_by' => $user->id,
        ]);
        PlanNotifier::update($moved, 'annual_plan.update_reviewed', ['program_manager', 'project_manager', 'officer'], [], $user);

        return $moved;
    }

    /**
     * @param  'project_manager'|'officer'  $to
     */
    public function mealReturn(User $user, PlanUpdate $update, string $reason, string $to): PlanUpdate
    {
        Gate::forUser($user)->authorize('decideAsMealManager', $update);
        $reason = $this->reason($reason);

        $moved = $this->move($update, PlanUpdateStatus::OfficerApproved, $to === 'officer'
            ? ['status' => PlanUpdateStatus::Sent, 'officer_approved_at' => null, 'officer_approved_by' => null, 'returned_at' => now(), 'returned_by' => $user->id, 'returned_to' => 'officer', 'return_reason' => $reason]
            : ['status' => PlanUpdateStatus::Returned, 'officer_approved_at' => null, 'officer_approved_by' => null, 'returned_at' => now(), 'returned_by' => $user->id, 'returned_to' => 'project_manager', 'return_reason' => $reason]);
        PlanNotifier::update($moved, 'annual_plan.update_returned', [$to === 'officer' ? 'officer' : 'project_manager'], ['reason' => $reason], $user, true);

        return $moved;
    }

    public function markSeen(User $user, PlanUpdate $update, ?string $note): PlanUpdate
    {
        Gate::forUser($user)->authorize('markSeen', $update);
        $note = trim((string) $note);

        $moved = $this->move($update, PlanUpdateStatus::Reviewed, [
            'status' => PlanUpdateStatus::Seen,
            'seen_at' => now(),
            'seen_by' => $user->id,
            'program_manager_note' => $note === '' ? null : $note,
        ]);
        PlanNotifier::update($moved, $note === '' ? 'annual_plan.update_seen' : 'annual_plan.update_seen_note', ['project_manager', 'meal', 'officer'], ['note' => $note], $user);

        return $moved;
    }

    /**
     * A question from the officer (while it is with them) or the MEAL
     * manager (while it is with them); the update stays where it is.
     */
    public function ask(User $user, PlanUpdate $update, string $question): PlanUpdateQuestion
    {
        $mayAsk = Gate::forUser($user)->allows('decideAsOfficer', $update) || Gate::forUser($user)->allows('decideAsMealManager', $update);

        if (! $mayAsk) {
            throw new AuthorizationException;
        }

        $posted = $this->post($update, $user, $question, 'question');
        PlanNotifier::update($update, 'annual_plan.update_question', ['project_manager'], ['question' => $posted->body], $user);

        return $posted;
    }

    public function answer(User $user, PlanUpdate $update, string $answer): PlanUpdateQuestion
    {
        Gate::forUser($user)->authorize('answer', $update);

        $posted = $this->post($update, $user, $answer, 'answer');
        PlanNotifier::update($update, 'annual_plan.update_answered', ['officer', 'meal'], ['answer' => $posted->body], $user);

        return $posted;
    }

    private function post(PlanUpdate $update, User $user, string $body, string $field): PlanUpdateQuestion
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages([$field => __('annual_plans.up.text_required')]);
        }

        return $update->questions()->create(['user_id' => $user->id, 'body' => mb_substr($body, 0, 2000)]);
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('annual_plans.up.reason_required')]);
        }

        return $reason;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function move(PlanUpdate $update, PlanUpdateStatus $expected, array $changes): PlanUpdate
    {
        return DB::transaction(function () use ($update, $expected, $changes): PlanUpdate {
            $locked = PlanUpdate::query()->lockForUpdate()->findOrFail($update->id);

            if ($locked->status !== $expected) {
                throw ValidationException::withMessages(['status' => __('annual_plans.up.moved_on')]);
            }

            $locked->forceFill($changes)->save();

            return $locked;
        });
    }
}
