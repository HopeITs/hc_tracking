<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\ApproveProposalChange;
use App\Actions\Proposals\RejectProposalChange;
use App\Actions\Proposals\ReviewProposalChange;
use App\Actions\Proposals\WithdrawProposalChange;
use App\Models\Proposal;
use App\Models\ProposalChangeRequest;
use App\Models\ProposalChangeRequestReview;
use App\Models\User;
use App\Support\ProposalChangeLines;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * The change request modal and chapter of proposals/show (Y-09 slice 2,
 * D-89a). One modal for everyone: the two slot holders review in their own
 * slot, the PR Manager (`proposals.approve`) approves or rejects, the
 * requester withdraws, anyone else reads. Every request is loaded through the
 * proposal's own `changeRequests()`, so an id of another proposal is a 404.
 * All "from → to" lists come from ProposalChangeLines.
 *
 * `?change-request=1` opens the pending request once (the notification
 * action), then clears itself. Used by a page that provides `proposalId`,
 * forgetReadings() and the #[Computed] proposal().
 *
 * @property-read Proposal $proposal
 * @property-read int|null $pendingChangeRequestId
 * @property-read Collection<int, ProposalChangeRequest> $changeRequestHistory
 * @property-read array<int, list<array{field: string, label: string, place: string|null, from: string, to: string}>> $changeRequestHistoryLines
 * @property-read ProposalChangeRequest|null $decidingChangeRequest
 * @property-read list<array{field: string, label: string, place: string|null, from: string, to: string}> $decidingChangeLines
 * @property-read string|null $changeReviewSlot
 * @property-read bool $canDecideChange
 */
trait DecidesProposalChange
{
    /**
     * Deep link: opens the pending change request once, then clears itself.
     */
    #[Url(as: 'change-request', except: false)]
    public bool $changeRequestLink = false;

    public bool $showChangeRequestModal = false;

    #[Locked]
    public ?int $decidingChangeRequestId = null;

    public string $reviewVerdict = '';

    public string $reviewNote = '';

    public string $rejectNote = '';

    /** Approve was refused because the proposal changed since the request: only Reject is left. */
    #[Locked]
    public bool $changeIsStale = false;

    public bool $showApproveChangeModal = false;

    public bool $showWithdrawChangeModal = false;

    public function openChangeRequestFromLink(): void
    {
        if ($this->changeRequestLink && $this->pendingChangeRequestId !== null) {
            $this->openChangeRequestModal();
        }

        $this->changeRequestLink = false;
    }

    /**
     * Open a request of this proposal, the pending one by default. For a
     * decider, a waiting review whose slot has no holder is skipped first,
     * under the request's lock, so the screen never waits on nobody.
     */
    public function openChangeRequestModal(?int $changeRequestId = null): void
    {
        $proposal = Proposal::query()->findOrFail($this->proposalId);

        Gate::authorize('view', $proposal);

        $changeRequestId ??= $proposal->changeRequests()->pending()->value('id');

        if ($changeRequestId === null) {
            return;
        }

        $changeRequest = $proposal->changeRequests()->whereKey($changeRequestId)->firstOrFail();

        if ($changeRequest->isPending() && Gate::allows('decideChange', $proposal)) {
            DB::transaction(function () use ($changeRequest): void {
                $locked = ProposalChangeRequest::query()->whereKey($changeRequest->id)->lockForUpdate()->firstOrFail();

                if ($locked->isPending()) {
                    $locked->skipVacantReviews();
                }
            });
        }

        $this->resetChangeDecisionForm();
        $this->decidingChangeRequestId = $changeRequest->id;
        $this->forgetChangeReadings();

        $ownReview = $this->changeReviewSlot === null ? null : $this->decidingChangeRequest?->reviews->firstWhere('slot', $this->changeReviewSlot);

        if ($ownReview instanceof ProposalChangeRequestReview && $ownReview->verdict !== null) {
            $this->reviewVerdict = $ownReview->verdict->value;
            $this->reviewNote = (string) $ownReview->note;
        }

        $this->showChangeRequestModal = true;
    }

    public function closeChangeRequestModal(): void
    {
        $this->showChangeRequestModal = false;
        $this->showApproveChangeModal = false;
        $this->showWithdrawChangeModal = false;
        $this->decidingChangeRequestId = null;
        $this->resetChangeDecisionForm();
        $this->forgetChangeReadings();
    }

    public function submitChangeReview(string $slot): void
    {
        $changeRequest = $this->decidingChangeRequestOrFail();

        $this->resetValidation();

        try {
            app(ReviewProposalChange::class)->handle($changeRequest, $slot, $this->reviewVerdict, $this->reviewNote === '' ? null : $this->reviewNote);
        } catch (ValidationException $exception) {
            $this->putChangeErrors($exception, ['verdict' => 'reviewVerdict', 'note' => 'reviewNote']);

            return;
        }

        $this->closeChangeRequestModal();
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.change.review_saved'));
    }

    public function confirmApproveChange(): void
    {
        $this->decidingChangeRequestOrFail();
        $this->showApproveChangeModal = true;
    }

    public function approveChange(): void
    {
        $changeRequest = $this->decidingChangeRequestOrFail();

        $this->resetValidation();

        try {
            app(ApproveProposalChange::class)->handle($changeRequest);
        } catch (ValidationException $exception) {
            $this->showApproveChangeModal = false;
            $this->changeIsStale = $this->isStaleRefusal($exception);
            $this->putChangeErrors($exception, []);
            $this->forgetChangeReadings();

            return;
        }

        $this->closeChangeRequestModal();
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.change.approved'));
    }

    public function rejectChange(): void
    {
        $changeRequest = $this->decidingChangeRequestOrFail();

        $this->resetValidation();

        try {
            app(RejectProposalChange::class)->handle($changeRequest, $this->rejectNote);
        } catch (ValidationException $exception) {
            $this->putChangeErrors($exception, ['note' => 'rejectNote']);

            return;
        }

        $this->closeChangeRequestModal();
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.change.rejected'));
    }

    public function confirmWithdrawChange(): void
    {
        $this->decidingChangeRequestOrFail();
        $this->showWithdrawChangeModal = true;
    }

    public function withdrawChange(): void
    {
        $changeRequest = $this->decidingChangeRequestOrFail();

        $this->resetValidation();

        try {
            app(WithdrawProposalChange::class)->handle($changeRequest);
        } catch (ValidationException $exception) {
            $this->showWithdrawChangeModal = false;
            $this->putChangeErrors($exception, []);

            return;
        }

        $this->closeChangeRequestModal();
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.change.withdrawn'));
    }

    /**
     * The id of the request still waiting for its decision, if any.
     */
    #[Computed]
    public function pendingChangeRequestId(): ?int
    {
        $id = ProposalChangeRequest::query()->where('proposal_id', $this->proposalId)->pending()->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Every request of this proposal, newest first, with what the chapter
     * shows of each: the requester, the decider and both reviews.
     *
     * @return Collection<int, ProposalChangeRequest>
     */
    #[Computed]
    public function changeRequestHistory(): Collection
    {
        return Proposal::query()->whereKey($this->proposalId)->firstOrFail()
            ->changeRequests()
            ->with(['reviews.reviewer', 'requester', 'decider'])
            ->get();
    }

    /**
     * The chapter's "from → to" lists, keyed by request id: one presenter
     * call for all of them.
     *
     * @return array<int, list<array{field: string, label: string, place: string|null, from: string, to: string}>>
     */
    #[Computed]
    public function changeRequestHistoryLines(): array
    {
        /** @var array<int, list<array<string, mixed>>> $lists */
        $lists = $this->changeRequestHistory
            ->mapWithKeys(fn (ProposalChangeRequest $changeRequest): array => [$changeRequest->id => $changeRequest->changes])
            ->all();

        /** @var User $viewer */
        $viewer = auth()->user();

        /** @var array<int, list<array{field: string, label: string, place: string|null, from: string, to: string}>> $lines */
        $lines = ProposalChangeLines::forMany($lists, $viewer);

        return $lines;
    }

    #[Computed]
    public function decidingChangeRequest(): ?ProposalChangeRequest
    {
        if ($this->decidingChangeRequestId === null) {
            return null;
        }

        return ProposalChangeRequest::query()
            ->where('proposal_id', $this->proposalId)
            ->with(['reviews.reviewer', 'requester', 'decider'])
            ->find($this->decidingChangeRequestId);
    }

    /**
     * @return list<array{field: string, label: string, place: string|null, from: string, to: string}>
     */
    #[Computed]
    public function decidingChangeLines(): array
    {
        $changeRequest = $this->decidingChangeRequest;

        if ($changeRequest === null) {
            return [];
        }

        /** @var User $viewer */
        $viewer = auth()->user();

        return ProposalChangeLines::for($changeRequest->changes, $viewer);
    }

    /**
     * The review slot the viewer holds, if any: the first whose review is
     * still waiting, else the first held.
     */
    #[Computed]
    public function changeReviewSlot(): ?string
    {
        $proposal = $this->proposal;
        $held = array_values(array_filter(
            ProposalChangeRequest::ReviewSlots,
            fn (string $slot): bool => Gate::allows('reviewChange', [$proposal, $slot]),
        ));

        if ($held === []) {
            return null;
        }

        $waiting = $this->decidingChangeRequest?->reviews
            ->filter(fn (ProposalChangeRequestReview $review): bool => $review->isWaiting() && in_array($review->slot, $held, true))
            ->first();

        return $waiting instanceof ProposalChangeRequestReview ? $waiting->slot : $held[0];
    }

    #[Computed]
    public function canDecideChange(): bool
    {
        return Gate::allows('decideChange', $this->proposal);
    }

    /**
     * The words of the reviews still waiting ("Waiting for Finance", …).
     *
     * @return list<string>
     */
    public function waitingChangeReviews(ProposalChangeRequest $changeRequest): array
    {
        $waitingSlots = $changeRequest->reviews
            ->filter(fn (ProposalChangeRequestReview $review): bool => $review->isWaiting())
            ->pluck('slot')
            ->all();

        return array_map(
            fn (string $slot): string => $slot === 'finance_manager'
                ? __('proposals.change.waiting_for_finance')
                : __('proposals.change.waiting_for_meal'),
            array_values(array_intersect(ProposalChangeRequest::ReviewSlots, $waitingSlots)),
        );
    }

    private function decidingChangeRequestOrFail(): ProposalChangeRequest
    {
        $proposal = Proposal::query()->findOrFail($this->proposalId);

        return $proposal->changeRequests()->whereKey($this->decidingChangeRequestId ?? 0)->firstOrFail();
    }

    /**
     * Whether a refused approval is the stale refusal (`change_request_stale`,
     * which names the fields that changed meanwhile).
     */
    private function isStaleRefusal(ValidationException $exception): bool
    {
        $message = (string) ($exception->errors()['changeRequest'][0] ?? '');
        $template = __('proposals.change_request_stale');

        return $message !== ''
            && Str::startsWith($message, Str::before($template, ':fields'))
            && Str::endsWith($message, Str::after($template, ':fields'));
    }

    /**
     * @param  array<string, string>  $fieldMap  the action's error key => this form's property
     */
    private function putChangeErrors(ValidationException $exception, array $fieldMap): void
    {
        foreach ($exception->errors() as $key => $messages) {
            $this->addError($fieldMap[$key] ?? 'changeRequest', $messages[0]);
        }
    }

    private function resetChangeDecisionForm(): void
    {
        $this->reviewVerdict = '';
        $this->reviewNote = '';
        $this->rejectNote = '';
        $this->changeIsStale = false;
        $this->resetValidation();
    }

    private function forgetChangeReadings(): void
    {
        unset($this->decidingChangeRequest, $this->decidingChangeLines, $this->changeReviewSlot);
    }
}
