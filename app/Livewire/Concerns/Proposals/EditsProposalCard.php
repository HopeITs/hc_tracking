<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\ChangeProposalStatusDate;
use App\Actions\Proposals\SaveProposal;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\Proposal;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The proposal card of proposals/show: Title, Start, End, Sent, Approved and
 * Donor edited in place. One field is open at a time; Enter saves, Esc
 * cancels and a refused value stays open with its message under it.
 *
 * Title, dates and donor save through SaveProposal (same rules as the edit
 * dialog, and refused once the donor approved: that goes through a change
 * request). Sent and Approved save through ChangeProposalStatusDate.
 * Used by proposals/show only.
 *
 * @property-read Proposal $proposal  #[Computed] proposal()
 * @property-read array{sent: CarbonImmutable|null, approved: CarbonImmutable|null} $cardDates  #[Computed] cardDates()
 * @property-read list<array{id: int, name: string}> $donorChoices  #[Computed] donorChoices()
 */
trait EditsProposalCard
{
    /** The fields the card edits in place. */
    public const CARD_FIELDS = ['title', 'start', 'end', 'sent', 'approved', 'donor'];

    #[Locked]
    public ?string $editingField = null;

    public string $fieldValue = '';

    public bool $cardMore = false;

    /**
     * Open one field. A field that may not be edited stays closed.
     */
    public function editField(string $field): void
    {
        if (! in_array($field, self::CARD_FIELDS, true) || ! $this->canEditCardField($field)) {
            return;
        }

        $this->resetValidation();
        $this->editingField = $field;
        $this->fieldValue = match ($field) {
            'title' => $this->proposal->name,
            'start' => (string) $this->proposal->planned_date_begin?->toDateString(),
            'end' => (string) $this->proposal->planned_date_end?->toDateString(),
            'sent' => (string) $this->cardDates['sent']?->toDateString(),
            'approved' => (string) $this->cardDates['approved']?->toDateString(),
            'donor' => (string) $this->proposal->donor_id,
        };
    }

    public function cancelField(): void
    {
        $this->resetValidation();
        $this->editingField = null;
        $this->fieldValue = '';
    }

    /**
     * Save the open field. Nothing is written when the value did not change.
     */
    public function saveField(): void
    {
        $field = $this->editingField;

        if ($field === null || ! $this->canEditCardField($field)) {
            $this->cancelField();

            return;
        }

        $value = trim($this->fieldValue);

        $this->validate(['fieldValue' => match ($field) {
            'title' => ['required', 'string', 'max:255'],
            'donor' => ['required', 'integer'],
            default => ['required', 'date_format:Y-m-d'],
        }], [
            'fieldValue.required' => __('proposals.page.field_required'),
            'fieldValue.max' => __('proposals.page.title_too_long'),
            'fieldValue.date_format' => __('proposals.page.date_invalid'),
            'fieldValue.integer' => __('proposals.page.field_required'),
        ]);

        try {
            $changed = in_array($field, ['sent', 'approved'], true)
                ? $this->saveStatusDate($field, $value)
                : $this->saveDetail($field, $value);
        } catch (ValidationException $exception) {
            $this->addError('fieldValue', collect($exception->errors())->flatten()->first() ?? __('proposals.page.field_required'));

            return;
        }

        $this->cancelField();
        $this->forgetReadings();

        if ($changed) {
            Flux::toast(variant: 'success', text: __('proposals.page.field_saved', ['field' => __('proposals.page.field_'.$field)]));
        }
    }

    /**
     * Whether this person may edit the field now, and why not when not.
     */
    public function cardFieldLock(string $field): ?string
    {
        $proposal = $this->proposal;

        if (auth()->user()?->can('update', $proposal) !== true) {
            return __('proposals.page.lock_no_permission');
        }

        return match ($field) {
            'title', 'start', 'end' => $proposal->status->isApprovedByDonor() ? __('proposals.page.lock_use_change_request') : null,
            // SaveProposal's rule: the donor changes only while the concept note is in the house.
            'donor' => in_array($proposal->status, [ProposalStatus::ConceptNote, ProposalStatus::ConceptNoteReview], true)
                ? null
                : __('proposals.the_donor_cannot_change_after_the_concept_note'),
            'sent' => $this->cardDates['sent'] === null ? __('proposals.page.not_sent_yet') : null,
            'approved' => $this->cardDates['approved'] === null ? __('proposals.page.not_approved_yet') : null,
            default => __('proposals.page.lock_no_permission'),
        };
    }

    public function canEditCardField(string $field): bool
    {
        return $this->cardFieldLock($field) === null;
    }

    /**
     * Sent = the date it reached Submitted; Approved = the approval date.
     *
     * @return array{sent: CarbonImmutable|null, approved: CarbonImmutable|null}
     */
    #[Computed]
    public function cardDates(): array
    {
        return [
            'sent' => ChangeProposalStatusDate::latestNote($this->proposalId, ProposalStatus::Submitted)?->date,
            'approved' => ChangeProposalStatusDate::latestNote($this->proposalId, ProposalStatus::Approved)?->date,
        ];
    }

    /**
     * The donors the Donor field offers: approved donors, plus the current one.
     *
     * @return list<array{id: int, name: string}>
     */
    #[Computed]
    public function donorChoices(): array
    {
        return array_values(Donor::query()
            ->select(['id', 'name'])
            ->where(fn ($query) => $query->whereNot('approval_status', 'pending')->orWhereNull('approval_status')->orWhere('id', $this->proposal->donor_id))
            ->orderBy('name')
            ->get()
            ->map(fn (Donor $donor): array => ['id' => $donor->id, 'name' => $donor->name])
            ->all());
    }

    private function saveStatusDate(string $field, string $value): bool
    {
        $status = $field === 'sent' ? ProposalStatus::Submitted : ProposalStatus::Approved;

        if ($this->cardDates[$field]?->toDateString() === $value) {
            return false;
        }

        app(ChangeProposalStatusDate::class)->handle($this->proposal, $status, CarbonImmutable::parse($value));

        return true;
    }

    /**
     * Title, Start, End or Donor through SaveProposal, the rest as it is.
     */
    private function saveDetail(string $field, string $value): bool
    {
        $proposal = Proposal::query()->findOrFail($this->proposalId);

        Gate::authorize('update', $proposal);

        $data = [
            'name' => $proposal->name,
            'donorId' => $proposal->donor_id,
            'plannedDateBegin' => $proposal->planned_date_begin?->toDateString(),
            'plannedDateEnd' => $proposal->planned_date_end?->toDateString(),
            'notes' => $proposal->notes,
            'narrative' => $proposal->narrative,
        ];

        $key = ['title' => 'name', 'start' => 'plannedDateBegin', 'end' => 'plannedDateEnd', 'donor' => 'donorId'][$field];

        if ((string) $data[$key] === $value) {
            return false;
        }

        $data[$key] = $field === 'donor' ? (int) $value : $value;

        if ($data['plannedDateBegin'] !== null && $data['plannedDateEnd'] !== null && $data['plannedDateEnd'] < $data['plannedDateBegin']) {
            throw ValidationException::withMessages(['fieldValue' => __('proposals.page.end_before_start')]);
        }

        app(SaveProposal::class)->handle($proposal, $data, auth()->user());

        return true;
    }
}
