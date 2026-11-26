<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Actions\Locations\ApproveLocationFromProposal;
use App\Enums\MediaCollection;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Events\ProposalStatusChanged;
use App\Models\Proposal;
use App\Models\ProposalLocation;
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
 * W-34a), then the proposal, its one audit note and every row of it (locked
 * in ascending id) are written together. Y-17: no project is created,
 * linked or touched - a place IS a row (D-93), and
 * MaterialiseProposalLocations completes the rows. Approval is not
 * activation: a row becomes `active` only when MEAL confirms the report
 * template at its place.
 * ProposalStatusChanged fires after the commit. A payroll proposal (W-32)
 * is the exception: it has no place, so it goes on to `active` at once.
 */
final class ApproveProposal
{
    public const OutcomeFunded = 'funded';

    public const OutcomeWaiting = 'waiting';

    public const OutcomeExtended = 'extended';

    public const OutcomeSkipped = 'skipped';

    public function __construct(
        private ApproveLocationFromProposal $approveLocation,
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

            $rows = $this->lockRows($locked);

            if ($rows === []) {
                throw ValidationException::withMessages(['locations' => __('proposals.locations_required')]);
            }

            $validated = $this->validate($locked, $input);
            $hadAgreement = $locked->donorProposals()->exists();

            // The grant's window has to be on the proposal before the rows
            // take their dates from it (ApproveLocationFromProposal).
            $this->recordApproval($locked, $expectedFrom, $validated);

            // T07: the proposal's reviewers are told about the approval here.

            $materialised = $this->materialiseLocations->handle($locked, (int) Auth::id());
            $moved = $this->approveRows($rows);

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

        $locked->status = ProposalStatus::Active;
        $locked->save();

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
     * What approving would do to each location row, for the modal and the
     * result message: row id => funded | waiting | extended | skipped.
     *
     * @return array<int, string>
     */
    public function preview(Proposal $proposal): array
    {
        $outcomes = [];

        foreach ($proposal->locations()->with('ownerAssignment')->orderBy('proposal_locations.id')->get() as $row) {
            $outcomes[$row->id] = $this->outcomeFor($row);
        }

        return $outcomes;
    }

    private function outcomeFor(ProposalLocation $row): string
    {
        if ($row->isOwnerRejected()) {
            return self::OutcomeSkipped;
        }

        return match ($row->status) {
            ProjectStatus::Draft => $row->isAssignmentAcknowledged('responsible_employee_id')
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
     * @return list<ProposalLocation>
     */
    private function lockRows(Proposal $proposal): array
    {
        $ids = $proposal->locations()->orderBy('proposal_locations.id')->pluck('proposal_locations.id')->all();

        if ($ids === []) {
            return [];
        }

        return array_values(ProposalLocation::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->load('ownerAssignment')->all());
    }

    /**
     * @param  list<ProposalLocation>  $rows
     * @return array{approved: list<int>, waiting: list<int>, skipped: list<int>}
     */
    private function approveRows(array $rows): array
    {
        $result = ['approved' => [], 'waiting' => [], 'skipped' => []];

        foreach ($rows as $row) {
            $outcome = $this->outcomeFor($row);

            if ($outcome === self::OutcomeSkipped) {
                $result['skipped'][] = $row->id;

                continue;
            }

            $this->approveLocation->handle($row, 'proposal_approval');

            if ($outcome === self::OutcomeWaiting) {
                $result['waiting'][] = $row->id;
            } else {
                $result['approved'][] = $row->id;
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
