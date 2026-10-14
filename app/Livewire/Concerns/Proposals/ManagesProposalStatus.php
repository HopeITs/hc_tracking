<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\CorrectProposalStatus;
use App\Actions\Proposals\TransitionProposal;
use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Support\ProposalTransitions;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The Change Status and Correct Status modals of the proposal pages.
 *
 * The modal offers only the targets of ProposalTransitions for the status the
 * proposal held when it opened, and only the fields of the chosen target. The
 * status the caller saw travels as expectedFrom, so a proposal moved by
 * someone else is refused with a reload prompt. The actions authorize and
 * validate again: hiding an option is never the protection.
 *
 * Used by proposals/index and proposals/show. The page provides
 * forgetReadings() (a no-op where nothing is memoised across requests).
 *
 * @property-read Proposal|null $statusSubject  #[Computed] statusSubject()
 * @property-read list<ProposalStatus> $statusTargets  #[Computed] statusTargets()
 * @property-read list<string> $statusFields  #[Computed] statusFields()
 * @property-read list<ProposalStatus> $correctionTargets  #[Computed] correctionTargets()
 */
trait ManagesProposalStatus
{
    public bool $showStatusModal = false;

    #[Locked]
    public ?int $statusProposalId = null;

    #[Locked]
    public string $statusExpectedFrom = '';

    public string $newStatus = '';

    public string $statusContext = '';

    public string $statusDate = '';

    public string $statusRemindOn = '';

    public bool $statusStale = false;

    public bool $showCorrectModal = false;

    public string $correctTarget = '';

    public string $correctReason = '';

    /**
     * Drop every memoised reading after a write. Provided by the page.
     */
    abstract private function forgetReadings(): void;

    public function openStatusModal(int $proposalId): void
    {
        $proposal = Proposal::query()->select(['id', 'status'])->findOrFail($proposalId);

        Gate::authorize('update', $proposal);

        $this->resetStatusForm();
        $this->statusProposalId = $proposal->id;
        $this->statusExpectedFrom = $proposal->status->value;
        $this->statusDate = now()->toDateString();

        $targets = $this->statusTargets;
        $next = $proposal->status->next();
        $this->newStatus = count($targets) === 1
            ? $targets[0]->value
            : ($next !== null && in_array($next, $targets, true) ? $next->value : '');

        $this->showStatusModal = true;
    }

    /**
     * The proposal the open modal is about, with the status it has right now.
     */
    #[Computed]
    public function statusSubject(): ?Proposal
    {
        return $this->statusProposalId === null
            ? null
            : Proposal::query()->select(['id', 'status'])->find($this->statusProposalId);
    }

    /**
     * The only statuses the modal offers: those of the status the modal opened at.
     *
     * @return list<ProposalStatus>
     */
    #[Computed]
    public function statusTargets(): array
    {
        $subject = $this->statusSubject;

        return $subject === null || $subject->status->value !== $this->statusExpectedFrom ? [] : $subject->transitionTargets();
    }

    /**
     * The inputs the chosen target needs: reason, effective_date, remind_on.
     *
     * @return list<string>
     */
    #[Computed]
    public function statusFields(): array
    {
        $to = ProposalStatus::tryFrom($this->newStatus);
        $subject = $this->statusSubject;

        if ($to === null || $subject === null || ! in_array($to, $this->statusTargets, true)) {
            return [];
        }

        $preHold = $subject->status === ProposalStatus::OnHold ? $subject->statusBefore(ProposalStatus::OnHold) : null;
        $requirements = ProposalTransitions::requirements($subject->status, $to, $preHold) ?? [];
        $fields = [];

        if (array_intersect($requirements, ['reason', 'reason_min_10']) !== []) {
            $fields[] = 'reason';
        }

        foreach (['effective_date', 'remind_on'] as $field) {
            if (in_array($field, $requirements, true)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Move the proposal; the action writes the status and its one audit note.
     */
    public function changeStatus(): void
    {
        $proposal = Proposal::query()->findOrFail($this->statusProposalId);

        Gate::authorize('update', $proposal);

        $this->resetValidation();
        $this->statusStale = false;

        $to = ProposalStatus::tryFrom($this->newStatus);

        if ($to === null) {
            $this->addError('newStatus', __('proposals.choose_the_new_status'));

            return;
        }

        $fields = $this->statusFields;
        $input = array_filter([
            'reason' => in_array('reason', $fields, true) ? trim($this->statusContext) : null,
            'effective_date' => in_array('effective_date', $fields, true) ? $this->statusDate : null,
            'remind_on' => in_array('remind_on', $fields, true) ? $this->statusRemindOn : null,
        ], fn (?string $value): bool => $value !== null);

        try {
            $result = app(TransitionProposal::class)->handle($proposal, $to, ProposalStatus::from($this->statusExpectedFrom), $input);
        } catch (ValidationException $exception) {
            $this->showStatusErrors($exception, ['status' => 'newStatus', 'reason' => 'statusContext', 'effective_date' => 'statusDate', 'remind_on' => 'statusRemindOn'], 'statusGate');

            return;
        }

        $this->closeStatusModal();
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.status_changed_and_recorded'));

        if (in_array('all_projects_archived', $result->warnings, true)) {
            Flux::toast(variant: 'warning', text: __('proposals.warning_all_projects_archived'));
        }
    }

    /**
     * Re-read the proposal after a "changed by someone else" refusal.
     */
    public function reloadStatusModal(): void
    {
        $id = $this->statusProposalId;

        if ($id !== null) {
            $this->forgetReadings();
            $this->openStatusModal($id);
        }
    }

    public function closeStatusModal(): void
    {
        $this->showStatusModal = false;
        $this->resetStatusForm();
    }

    public function openCorrectModal(int $proposalId): void
    {
        $proposal = Proposal::query()->select(['id', 'status'])->findOrFail($proposalId);

        Gate::authorize('correctStatus', $proposal);

        $this->resetStatusForm();

        if (! $proposal->status->isTerminal()) {
            return;
        }

        $this->statusProposalId = $proposal->id;
        $this->statusExpectedFrom = $proposal->status->value;
        $this->showCorrectModal = true;
    }

    /**
     * @return list<ProposalStatus>
     */
    #[Computed]
    public function correctionTargets(): array
    {
        $from = ProposalStatus::tryFrom($this->statusExpectedFrom);

        return $from === null ? [] : ProposalTransitions::correctionTargets($from);
    }

    /**
     * Correct a rejected or completed status; the reason is mandatory.
     */
    public function correctStatus(): void
    {
        $proposal = Proposal::query()->findOrFail($this->statusProposalId);

        Gate::authorize('correctStatus', $proposal);

        $this->resetValidation();
        $this->statusStale = false;

        $to = ProposalStatus::tryFrom($this->correctTarget);

        if ($to === null) {
            $this->addError('correctTarget', __('proposals.choose_the_new_status'));

            return;
        }

        try {
            app(CorrectProposalStatus::class)->handle($proposal, $to, ProposalStatus::from($this->statusExpectedFrom), $this->correctReason);
        } catch (ValidationException $exception) {
            $this->showStatusErrors($exception, ['status' => 'correctTarget', 'reason' => 'correctReason'], 'correctTarget');

            return;
        }

        $this->closeCorrectModal();
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.status_corrected'));
    }

    public function closeCorrectModal(): void
    {
        $this->showCorrectModal = false;
        $this->resetStatusForm();
    }

    /**
     * Put an action's refusal on the modal fields; keys with no field go to $fallback.
     * A refusal because the status moved meanwhile also raises the reload prompt.
     *
     * @param  array<string, string>  $fields  action error key => component property
     */
    private function showStatusErrors(ValidationException $exception, array $fields, string $fallback): void
    {
        $errors = $exception->errors();
        $this->statusStale = in_array(__('proposals.stale_status'), $errors['status'] ?? [], true);

        foreach ($errors as $key => $messages) {
            foreach ($messages as $message) {
                $this->addError($fields[$key] ?? $fallback, $message);
            }
        }
    }

    private function resetStatusForm(): void
    {
        $this->resetValidation();
        $this->statusProposalId = null;
        $this->statusExpectedFrom = '';
        $this->newStatus = '';
        $this->statusContext = '';
        $this->statusDate = '';
        $this->statusRemindOn = '';
        $this->statusStale = false;
        $this->correctTarget = '';
        $this->correctReason = '';
        unset($this->statusSubject, $this->statusTargets, $this->statusFields, $this->correctionTargets);
    }
}
