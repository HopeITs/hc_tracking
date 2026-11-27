<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Actions\Projects\ApproveProjectFromProposal;
use App\Enums\MediaCollection;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Events\ProposalStatusChanged;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Support\ApprovalResult;
use App\Support\ProposalLocationRows;
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
 * caller saw, its location rows must be complete (`locations_complete`,
 * W-34a), the projects those rows still lack are materialised
 * (MaterialiseProposalLocations: drafts created and linked, open projects
 * reused), then the proposal, its one audit note and every linked project
 * (locked in ascending id) are written together. Approval is not activation:
 * a project becomes `active` only when MEAL confirms its report template.
 * ProposalStatusChanged fires after the commit. A payroll proposal (W-32)
 * is the exception: it has no project, so it goes on to `active` at once.
 */
final class ApproveProposal
{
    public const OutcomeFunded = 'funded';

    public const OutcomeWaiting = 'waiting';

    public const OutcomeExtended = 'extended';

    public const OutcomeSkipped = 'skipped';

    public function __construct(
        private ApproveProjectFromProposal $approveProject,
        private MaterialiseProposalLocations $materialiseLocations,
    ) {}

    /**
     * @param  array{actual_date_begin: string, actual_date_end: string, decision_date: string, note?: ?string, approval_note?: ?string}  $input
     */
    public function handle(Proposal $proposal, ProposalStatus $expectedFrom, array $input): ApprovalResult
    {
        $result = DB::transaction(function () use ($proposal, $expectedFrom, $input): ApprovalResult {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('approve', $locked);

            $this->assertApprovable($locked, $expectedFrom);

            if ($locked->isPayroll()) {
                return $this->approvePayroll($locked, $expectedFrom, $input);
            }

            if (ProposalLocationRows::incompleteRowIds($locked) !== []) {
                throw ValidationException::withMessages(['locations_complete' => __('proposals.locations_incomplete')]);
            }

            $materialised = $this->materialiseLocations->handle($locked, (int) Auth::id());

            $projects = $this->lockLinkedProjects($locked);

            if ($projects === []) {
                throw ValidationException::withMessages(['linked_project' => __('proposals.link_project_first')]);
            }

            $validated = $this->validate($locked, $input);
            $hadAgreement = $locked->donorProposals()->exists();

            $this->recordApproval($locked, $expectedFrom, $validated);

            // T07: the proposal's reviewers and owners hear of the approval
            // through its proposal.approved activity (ProposalRecipients).

            $moved = $this->approveProjects($projects);

            if (! $hadAgreement) {
                $locked->recordActivity('proposal.approved_without_agreement');
            }

            return new ApprovalResult(
                $moved['approved'],
                $moved['waiting'],
                $moved['skipped'],
                [...$materialised->warnings, ...($hadAgreement ? [] : ['no_agreement'])],
            );
        });

        $proposal->refresh();

        return $result;
    }

    /**
     * Write `approved` with the grant dates and evidence, its one note and its
     * event (dispatched after the caller's commit).
     *
     * @param  array{actual_date_begin: string, actual_date_end: string, decision_date: string, note?: ?string, approval_note?: ?string}  $validated
     */
    private function recordApproval(Proposal $locked, ProposalStatus $expectedFrom, array $validated): void
    {
        $approvalNote = trim((string) ($validated['approval_note'] ?? ''));

        $locked->status = ProposalStatus::Approved;
        $locked->actual_date_begin = CarbonImmutable::parse($validated['actual_date_begin']);
        $locked->actual_date_end = CarbonImmutable::parse($validated['actual_date_end']);
        $locked->approval_note = $approvalNote === '' ? null : $approvalNote;
        $locked->status_remind_on = null;
        // Approval ends any hold with it (D-58, W-06).
        $locked->on_hold_since = null;
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
    }

    /**
     * A payroll proposal (W-32) has nothing to wait for: it is approved and
     * activated in the caller's transaction, one note and one event per move.
     * The grant dates default to the planned ones; no project is touched.
     *
     * @param  array<string, mixed>  $input
     */
    private function approvePayroll(Proposal $locked, ProposalStatus $expectedFrom, array $input): ApprovalResult
    {
        $validated = $this->validate($locked, [
            ...$input,
            'actual_date_begin' => filled($input['actual_date_begin'] ?? null) ? $input['actual_date_begin'] : $locked->planned_date_begin->toDateString(),
            'actual_date_end' => filled($input['actual_date_end'] ?? null) ? $input['actual_date_end'] : $locked->planned_date_end->toDateString(),
        ]);
        $hadAgreement = $locked->donorProposals()->exists();

        $this->recordApproval($locked, $expectedFrom, $validated);

        // The watchers hear of the approval once; the activation that comes
        // with it in the same request is kept in its note, not a second message.
        $locked->status = ProposalStatus::Active;
        Activity::withoutRecording(fn (): bool => $locked->save());

        ProposalStatusNote::query()->create([
            'proposal_id' => $locked->id,
            'status' => ProposalStatus::Active,
            'context' => __('proposals.payroll_active_on_approval'),
            'date' => $validated['decision_date'],
            'changed_by' => Auth::id(),
            'is_correction' => false,
        ]);

        ProposalStatusChanged::dispatch($locked->id, ProposalStatus::Approved, ProposalStatus::Active, Auth::user()?->id);

        if (! $hadAgreement) {
            $locked->recordActivity('proposal.approved_without_agreement');
        }

        return new ApprovalResult([], [], [], $hadAgreement ? [] : ['no_agreement']);
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

        // A proposal on hold is still `submitted` since W-06 (D-58), so it
        // needs no arm of its own; approval clears the flag below.
        if ($from !== ProposalStatus::Submitted) {
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
