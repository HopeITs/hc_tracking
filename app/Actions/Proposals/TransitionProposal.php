<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\MediaCollection;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReviewStage;
use App\Events\ProposalStatusChanged;
use App\Models\Donor;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Support\ProposalTransitions;
use App\Support\TransitionResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Move a proposal along the contracts.md §2 transition table.
 *
 * The row is reloaded under a lock and compared with the status the caller
 * last saw, so two people moving the same proposal never both win. The
 * status, its one audit note and the reminder date commit together;
 * ProposalStatusChanged is dispatched only after that commit.
 *
 * Entering a review status also invites that stage's reviewers (T07) in the
 * same transaction; the optional review meeting is kept on the opening note.
 */
final class TransitionProposal
{
    /**
     * @param  array{reason?: string, effective_date?: string, remind_on?: string, meeting_at?: string}  $input
     */
    public function handle(Proposal $proposal, ProposalStatus $to, ProposalStatus $expectedFrom, array $input): TransitionResult
    {
        $result = DB::transaction(function () use ($proposal, $to, $expectedFrom, $input): TransitionResult {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('transition', [$locked, $to]);

            $from = $locked->status;

            if ($from !== $expectedFrom) {
                throw ValidationException::withMessages(['status' => __('proposals.stale_status')]);
            }

            $requirements = ProposalTransitions::requirements(
                $from,
                $to,
                $from === ProposalStatus::OnHold ? $locked->statusBefore(ProposalStatus::OnHold) : null,
            );

            if ($requirements === null) {
                throw ValidationException::withMessages([
                    'status' => __('proposals.transition_not_allowed', ['from' => $from->label(), 'to' => $to->label()]),
                ]);
            }

            $reviewStage = ReviewStage::forReviewStatus($to);
            $validated = $this->validate($locked, $requirements, $input, $reviewStage !== null);

            $locked->status = $to;
            $locked->status_remind_on = $to === ProposalStatus::OnHold ? $validated['remind_on'] : null;
            $locked->save();

            $reason = trim((string) ($validated['reason'] ?? ''));

            $note = ProposalStatusNote::query()->create([
                'proposal_id' => $locked->id,
                'status' => $to,
                'context' => $reason !== '' ? $reason : __('proposals.status_moved_standard_note', ['from' => $from->label(), 'to' => $to->label()]),
                'date' => $validated['effective_date'] ?? today()->toDateString(),
                'meeting_at' => filled($validated['meeting_at'] ?? null) ? CarbonImmutable::parse($validated['meeting_at']) : null,
                'changed_by' => Auth::id(),
                'is_correction' => false,
            ]);

            if ($reviewStage !== null) {
                app(InviteDefaultReviewers::class)->handle($locked, $reviewStage);
            }

            ProposalStatusChanged::dispatch($locked->id, $from, $to, Auth::id());

            return new TransitionResult($note, $this->warnings($locked, $to));
        });

        $proposal->refresh();

        return $result;
    }

    /**
     * Check the move's requirement keys against the input and the proposal.
     * Every failure is reported at once, each under its own key.
     *
     * @param  list<string>  $requirements
     * @param  array{reason?: string, effective_date?: string, remind_on?: string, meeting_at?: string}  $input
     * @param  bool  $opensReview  only a move into a review status takes a meeting time
     * @return array{reason?: string, effective_date?: string, remind_on?: string, meeting_at?: string|null}
     */
    private function validate(Proposal $proposal, array $requirements, array $input, bool $opensReview): array
    {
        $rules = ['reason' => ['nullable', 'string', 'max:5000']];
        $messages = [];

        if (in_array('reason', $requirements, true)) {
            $rules['reason'] = ['required', 'string', 'max:5000'];
            $messages['reason.required'] = __('proposals.reason_required');
        }

        if (in_array('reason_min_10', $requirements, true)) {
            $rules['reason'] = ['required', 'string', 'min:10', 'max:5000'];
            $messages['reason.required'] = __('proposals.rejection_reason');
            $messages['reason.min'] = __('proposals.rejection_reason');
        }

        if (in_array('effective_date', $requirements, true)) {
            $rules['effective_date'] = ['required', 'date_format:Y-m-d', 'before_or_equal:today'];
            $messages['effective_date.before_or_equal'] = __('proposals.date_not_future');
        }

        if (in_array('remind_on', $requirements, true)) {
            $rules['remind_on'] = ['required', 'date_format:Y-m-d', 'after:today'];
            $messages['remind_on.required'] = __('proposals.remind_on_future');
            $messages['remind_on.date_format'] = __('proposals.remind_on_future');
            $messages['remind_on.after'] = __('proposals.remind_on_future');
        }

        if ($opensReview) {
            $rules['meeting_at'] = ['nullable', 'date', 'after:now'];
            $messages['meeting_at.date'] = __('proposals.meeting_after_now');
            $messages['meeting_at.after'] = __('proposals.meeting_after_now');
        } else {
            unset($input['meeting_at']);
        }

        $validator = Validator::make($input, $rules, $messages);

        $validator->after(function (ValidatorInstance $validator) use ($proposal, $requirements): void {
            foreach ($requirements as $requirement) {
                $message = $this->unmetRequirement($proposal, $requirement);

                if ($message !== null) {
                    $validator->errors()->add($requirement, $message);
                }
            }
        });

        /** @var array{reason?: string, effective_date?: string, remind_on?: string, meeting_at?: string|null} */
        return $validator->validate();
    }

    /**
     * The message for a requirement the proposal itself does not meet, or
     * null when it is met or is an input rule. No message names an amount.
     */
    private function unmetRequirement(Proposal $proposal, string $requirement): ?string
    {
        $isMet = match ($requirement) {
            'linked_project' => $proposal->projects()->exists(),
            'donor' => $proposal->donor_id !== null,
            'planned_dates' => $proposal->planned_date_begin !== null && $proposal->planned_date_end !== null,
            'budget' => $proposal->requested_budget !== null && $proposal->requested_currency !== null,
            'target_beneficiaries' => ! $proposal->projects()
                ->where(fn ($query) => $query->whereNull('project_proposal.target_beneficiaries')->orWhere('project_proposal.target_beneficiaries', '<', 1))
                ->exists(),
            'final_report' => $this->hasFinalReport($proposal),
            'reason', 'reason_min_10', 'effective_date', 'remind_on' => true,
        };

        if ($isMet) {
            return null;
        }

        return match ($requirement) {
            'linked_project' => __('proposals.link_project_first'),
            'donor' => __('proposals.choose_the_donor'),
            'planned_dates' => __('proposals.planned_dates_required'),
            'budget' => __('proposals.budget_required'),
            'target_beneficiaries' => __('proposals.beneficiaries_required'),
            'final_report' => __('proposals.final_report_required'),
        };
    }

    /**
     * D-02c: the final-report milestone is reached, the final report file is
     * filed, and (unless the proposal is self-funded) the donor's acceptance
     * is recorded. The acceptance email is optional.
     */
    private function hasFinalReport(Proposal $proposal): bool
    {
        $milestoneReached = Milestone::query()
            ->where('proposal_id', $proposal->id)
            ->where('is_final_report', true)
            ->where('status', MilestoneStatus::Reached)
            ->exists();

        if (! $milestoneReached || ! $proposal->media()->where('collection', MediaCollection::FinalReport)->exists()) {
            return false;
        }

        if ((bool) Donor::query()->whereKey($proposal->donor_id)->value('is_internal')) {
            return true;
        }

        return $proposal->final_report_accepted_on !== null && $proposal->final_report_accepted_by !== null;
    }

    /**
     * Warning keys (contracts.md §6) this move raises. Neither blocks it:
     * all_projects_archived (T04) when a review starts while every linked
     * project is archived; reviewers_unseen (T07, D-15) when the proposal goes
     * to the donor while a reviewer of that stage has not seen it.
     *
     * @return list<string>
     */
    private function warnings(Proposal $proposal, ProposalStatus $to): array
    {
        $submittedStage = ReviewStage::forSubmission($to);

        if ($submittedStage !== null) {
            return $proposal->reviewers()->forStage($submittedStage)->unseen()->exists() ? ['reviewers_unseen'] : [];
        }

        if (ReviewStage::forReviewStatus($to) === null) {
            return [];
        }

        $allArchived = $proposal->projects()->exists()
            && ! $proposal->projects()->where('projects.status', '!=', ProjectStatus::Archived)->exists();

        return $allArchived ? ['all_projects_archived'] : [];
    }
}
