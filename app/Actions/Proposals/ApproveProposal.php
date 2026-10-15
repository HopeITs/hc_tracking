<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Actions\Projects\ApproveProjectFromProposal;
use App\Enums\MediaCollection;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Events\ProposalStatusChanged;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Support\ApprovalResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Approve a proposal on the donor's decision (contracts.md §2, §6; D-04, D-26).
 *
 * One transaction: the proposal row is locked and compared with the status the
 * caller saw, then the proposal, its one audit note and every linked project
 * (locked in ascending id) are written together. Approval is not activation:
 * a project becomes `active` only when MEAL confirms its report template.
 * ProposalStatusChanged fires after the commit.
 */
final class ApproveProposal
{
    public const OutcomeFunded = 'funded';

    public const OutcomeWaiting = 'waiting';

    public const OutcomeExtended = 'extended';

    public const OutcomeSkipped = 'skipped';

    public function __construct(private ApproveProjectFromProposal $approveProject) {}

    /**
     * @param  array{actual_date_begin: string, actual_date_end: string, decision_date: string, note?: ?string, approval_note?: ?string}  $input
     */
    public function handle(Proposal $proposal, ProposalStatus $expectedFrom, array $input): ApprovalResult
    {
        $result = DB::transaction(function () use ($proposal, $expectedFrom, $input): ApprovalResult {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('approve', $locked);

            $this->assertApprovable($locked, $expectedFrom);

            $projects = $this->lockLinkedProjects($locked);

            if ($projects === []) {
                throw ValidationException::withMessages(['linked_project' => __('proposals.link_project_first')]);
            }

            $validated = $this->validate($locked, $input);
            $approvalNote = trim((string) ($validated['approval_note'] ?? ''));
            $hadAgreement = $locked->donorProposals()->exists();

            $locked->status = ProposalStatus::Approved;
            $locked->actual_date_begin = CarbonImmutable::parse($validated['actual_date_begin']);
            $locked->actual_date_end = CarbonImmutable::parse($validated['actual_date_end']);
            $locked->approval_note = $approvalNote === '' ? null : $approvalNote;
            $locked->status_remind_on = null;
            $locked->save();

            $context = trim((string) ($validated['note'] ?? ''));

            ProposalStatusNote::query()->create([
                'proposal_id' => $locked->id,
                'status' => ProposalStatus::Approved,
                'context' => $context !== '' ? $context : ($approvalNote !== '' ? $approvalNote : __('proposals.approval_proposal_note')),
                'date' => $validated['decision_date'],
                'changed_by' => Auth::id(),
                'is_correction' => false,
            ]);

            ProposalStatusChanged::dispatch($locked->id, $expectedFrom, ProposalStatus::Approved, Auth::user()?->id);

            // T07: the proposal's reviewers are told about the approval here.

            $moved = $this->approveProjects($projects);

            if (! $hadAgreement) {
                $locked->recordActivity('proposal.approved_without_agreement');
            }

            return new ApprovalResult(
                $moved['approved'],
                $moved['waiting'],
                $moved['skipped'],
                $hadAgreement ? [] : ['no_agreement'],
            );
        });

        $proposal->refresh();

        return $result;
    }

    /**
     * What approving would do to each linked project, for the modal and the
     * result message: project id => funded | waiting | extended | skipped.
     *
     * @return array<int, string>
     */
    public function preview(Proposal $proposal): array
    {
        $outcomes = [];

        foreach ($proposal->projects()->with('ownerAssignment')->orderBy('projects.id')->get() as $project) {
            $outcomes[$project->id] = $this->outcomeFor($project);
        }

        return $outcomes;
    }

    private function outcomeFor(Project $project): string
    {
        if ($project->isOwnerRejected()) {
            return self::OutcomeSkipped;
        }

        return match ($project->status) {
            ProjectStatus::Draft => $project->isAssignmentAcknowledged('user_id')
                ? self::OutcomeFunded
                : self::OutcomeWaiting,
            ProjectStatus::Approved, ProjectStatus::Active => self::OutcomeExtended,
            ProjectStatus::Submitted, ProjectStatus::Closed, ProjectStatus::Archived => self::OutcomeSkipped,
        };
    }

    /**
     * Source must be submitted, or on hold entered from submitted.
     */
    private function assertApprovable(Proposal $proposal, ProposalStatus $expectedFrom): void
    {
        $from = $proposal->status;

        if ($from === ProposalStatus::Approved) {
            throw ValidationException::withMessages(['status' => __('proposals.already_approved')]);
        }

        if ($from !== $expectedFrom) {
            throw ValidationException::withMessages(['status' => __('proposals.stale_status')]);
        }

        $isApprovable = $from === ProposalStatus::Submitted
            || ($from === ProposalStatus::OnHold && $proposal->statusBefore(ProposalStatus::OnHold) === ProposalStatus::Submitted);

        if (! $isApprovable) {
            throw ValidationException::withMessages([
                'status' => __('proposals.transition_not_allowed', ['from' => $from->label(), 'to' => ProposalStatus::Approved->label()]),
            ]);
        }
    }

    /**
     * @return list<Project>
     */
    private function lockLinkedProjects(Proposal $proposal): array
    {
        $ids = $proposal->projects()->orderBy('projects.id')->pluck('projects.id')->all();

        if ($ids === []) {
            return [];
        }

        return array_values(Project::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->load('ownerAssignment')->all());
    }

    /**
     * @param  list<Project>  $projects
     * @return array{approved: list<int>, waiting: list<int>, skipped: list<int>}
     */
    private function approveProjects(array $projects): array
    {
        $result = ['approved' => [], 'waiting' => [], 'skipped' => []];

        foreach ($projects as $project) {
            $outcome = $this->outcomeFor($project);

            if ($outcome === self::OutcomeSkipped) {
                $result['skipped'][] = $project->id;

                continue;
            }

            $this->approveProject->handle($project, 'proposal_approval');

            if ($outcome === self::OutcomeWaiting) {
                $result['waiting'][] = $project->id;
            } else {
                $result['approved'][] = $project->id;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{actual_date_begin: string, actual_date_end: string, decision_date: string, note?: ?string, approval_note?: ?string}
     */
    private function validate(Proposal $proposal, array $input): array
    {
        $validator = Validator::make($input, [
            'actual_date_begin' => ['required', 'date_format:Y-m-d'],
            'actual_date_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:actual_date_begin'],
            'decision_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
            'approval_note' => ['nullable', 'string', 'max:5000'],
        ], [
            'actual_date_begin.required' => __('proposals.grant_start_required'),
            'actual_date_begin.date_format' => __('proposals.grant_start_required'),
            'actual_date_end.required' => __('proposals.grant_end_before_start'),
            'actual_date_end.date_format' => __('proposals.grant_end_before_start'),
            'actual_date_end.after_or_equal' => __('proposals.grant_end_before_start'),
            'decision_date.required' => __('proposals.decision_not_future'),
            'decision_date.date_format' => __('proposals.decision_not_future'),
            'decision_date.before_or_equal' => __('proposals.decision_not_future'),
        ]);

        $validator->after(function (ValidatorInstance $validator) use ($proposal, $input): void {
            $errors = $validator->errors();

            $begin = (string) ($input['actual_date_begin'] ?? '');
            $decision = (string) ($input['decision_date'] ?? '');

            if (! $errors->has('actual_date_begin') && ! $errors->has('decision_date')
                && $begin < $decision && trim((string) ($input['note'] ?? '')) === '') {
                $errors->add('actual_date_begin', __('proposals.grant_start_late_note_required'));
            }

            $note = trim((string) ($input['approval_note'] ?? ''));
            $hasEvidence = ($note !== '' && mb_strlen($note) >= 10)
                || ($note === '' && $proposal->media()->where('collection', MediaCollection::DonorApproval)->exists());

            if (! $hasEvidence) {
                $errors->add('approval_note', __('proposals.approval_evidence_required'));
            }
        });

        /** @var array{actual_date_begin: string, actual_date_end: string, decision_date: string, note?: ?string, approval_note?: ?string} */
        return $validator->validate();
    }
}
