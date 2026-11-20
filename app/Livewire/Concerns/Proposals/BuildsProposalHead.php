<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * The head of proposals/show: one primary button for the next step and a
 * More actions menu holding every other action this person may take.
 *
 * Each action opens the existing dialog of its concern, which authorises
 * and validates again; the menu only decides what to offer and, for an
 * action that does not apply yet, says why. Used by proposals/show only.
 *
 * @property-read Proposal $proposal  #[Computed] proposal()
 * @property-read bool $canRecordAgreement  #[Computed] canRecordAgreement()
 * @property-read bool $canRecordFinalReportAcceptance  #[Computed] canRecordFinalReportAcceptance()
 * @property-read int|null $pendingChangeRequestId  #[Computed] pendingChangeRequestId()
 * @property-read list<array{key: string, label: string, call: string, disabled: bool, why: string|null, primary: bool}> $headActions  #[Computed] headActions()
 */
trait BuildsProposalHead
{
    /**
     * Open the status dialog with Rejected already chosen.
     */
    public function openRejectModal(): void
    {
        $this->openStatusModal($this->proposalId);
        $this->newStatus = ProposalStatus::Rejected->value;
    }

    /**
     * Every action of the head, the primary one first.
     *
     * @return list<array{key: string, label: string, call: string, disabled: bool, why: string|null, primary: bool}>
     */
    #[Computed]
    public function headActions(): array
    {
        $proposal = $this->proposal;
        $user = auth()->user();
        $id = $proposal->id;
        $actions = [];

        $add = function (string $key, string $label, string $call, bool $disabled = false, ?string $why = null, bool $primary = false) use (&$actions): void {
            $actions[] = ['key' => $key, 'label' => $label, 'call' => $call, 'disabled' => $disabled, 'why' => $disabled ? $why : null, 'primary' => $primary];
        };

        $mayUpdate = $user !== null && Gate::forUser($user)->allows('update', $proposal);
        $targets = $proposal->transitionTargets();
        $next = $this->forwardTarget($proposal, $targets);

        if ($mayUpdate && $proposal->status === ProposalStatus::Submitted) {
            if (Gate::forUser($user)->allows('approve', $proposal)) {
                $add('approve', __('proposals.page.approve'), "openApproveModal({$id})", primary: true);
            }

            if (in_array(ProposalStatus::Rejected, $targets, true)) {
                $add('reject', __('proposals.page.reject'), 'openRejectModal', $proposal->isOnHold(), __('proposals.page.why_on_hold'));
            }
        } elseif ($mayUpdate && $next !== null) {
            // A move the transition table does not open (approved → active
            // happens through the agreement) is not offered at all.
            $why = match (true) {
                $proposal->isOnHold() => __('proposals.page.why_on_hold'),
                $next === ProposalStatus::Completed && $proposal->final_report_accepted_on === null => __('proposals.page.why_final_first'),
                default => null,
            };

            $add('move', __('proposals.page.move_to', ['status' => $next->label()]), "openStatusModal({$id})", $why !== null, $why, primary: true);
        }

        // Every other move the transition table opens (send back, reject early)
        // stays reachable through the full status dialog.
        $offered = array_filter([$next, collect($actions)->contains('key', 'reject') ? ProposalStatus::Rejected : null]);
        if ($mayUpdate && ! $proposal->isOnHold() && array_diff(array_map(fn (ProposalStatus $s): string => $s->value, $targets), array_map(fn (ProposalStatus $s): string => $s->value, $offered)) !== []) {
            $add('change-status', __('proposals.change_status'), "openStatusModal({$id})");
        }

        if ($mayUpdate) {
            if ($proposal->status->isApprovedByDonor()) {
                if ($this->pendingChangeRequestId !== null) {
                    $add('change-open', __('proposals.change.open_button'), 'openChangeRequestModal');
                } elseif (Gate::forUser($user)->allows('requestChange', $proposal)) {
                    $add('change-request', __('proposals.change.request_button'), 'openChangeRequest');
                }
            } else {
                $add('edit', __('proposals.edit_details'), "openEditModal({$id})");
            }

            if ($this->canRecordAgreement) {
                $add('agreement', __('funding_agreements.record_agreement'), 'openAgreementCreate');
            }

            if ($proposal->isOnHold()) {
                $add('off-hold', __('proposals.take_off_hold'), 'openOffHoldModal');
            } elseif (in_array($proposal->status, [ProposalStatus::Submitted, ProposalStatus::ConceptNoteSubmitted], true)) {
                $add('hold', __('proposals.put_on_hold'), 'openHoldModal');
            }

            if ($proposal->status === ProposalStatus::Active) {
                $add('extend', __('proposals.extend_proposal'), 'openExtensionModal');
                $add('final', __('proposals.record_final_report_acceptance'), 'openAcceptanceModal', ! $this->canRecordFinalReportAcceptance, __('proposals.page.why_final_recorded'));
            }

            $add('note', __('proposals.add_negotiation_note'), 'openNegotiationModal', $proposal->status !== ProposalStatus::Submitted, __('proposals.page.why_note_submitted'));
        }

        if ($user !== null && Gate::forUser($user)->allows('correctStatus', $proposal)) {
            $add('correct', __('proposals.correct_status'), "openCorrectModal({$id})", ! $proposal->status->isTerminal(), __('proposals.page.why_correct_terminal'));
        }

        return $actions;
    }

    /**
     * The next step forward: the lifecycle's next status when the
     * transition table opens it (a payroll draft skips the review and
     * goes straight to Submitted), otherwise the first forward target.
     *
     * @param  list<ProposalStatus>  $targets
     */
    private function forwardTarget(Proposal $proposal, array $targets): ?ProposalStatus
    {
        $lifecycle = ProposalStatus::lifecycle();
        $here = array_search($proposal->status, $lifecycle, true);
        $next = $proposal->status->next();

        if ($next !== null && ($proposal->isOnHold() || in_array($next, $targets, true))) {
            return $next;
        }

        foreach ($targets as $target) {
            $there = array_search($target, $lifecycle, true);

            if ($here !== false && $there !== false && $there > $here && $target !== ProposalStatus::Rejected) {
                return $target;
            }
        }

        return null;
    }
}
