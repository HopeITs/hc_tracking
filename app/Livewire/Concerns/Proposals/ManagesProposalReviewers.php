<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\AddProposalReviewer;
use App\Actions\Proposals\MarkProposalReviewed;
use App\Actions\Proposals\RemoveProposalReviewer;
use App\Actions\Proposals\SaveProposalFile;
use App\Enums\FileType;
use App\Enums\MediaCollection;
use App\Enums\ReviewerSlot;
use App\Enums\ReviewStage;
use App\Models\HrEmployee;
use App\Models\Media;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Rules\SecureUpload;
use App\Support\MealCoverage;
use App\Support\Slots;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The Reviewers panel on proposals/show (T07): who reviews each stage, Mark
 * as Seen with a note for one's own row, adding and removing extra reviewers,
 * and the need assessment. The actions authorize and lock; this trait only
 * validates the inputs and shows the result.
 *
 * Used by proposals/show only. The page provides proposalId, the #[Computed]
 * proposal() and forgetReadings(); proposalFiles comes from
 * ManagesProposalFiles, and the page uses WithFileUploads for newFile.
 *
 * @property int $proposalId
 * @property-read Proposal $proposal  #[Computed] proposal()
 * @property-read Collection<int, Media> $proposalFiles  #[Computed] proposalFiles()
 * @property-read list<array{stage: ReviewStage, isOpen: bool, rows: list<array{id: int, employee: HrEmployee|null, employeeId: int, slot: ReviewerSlot, seenAt: CarbonImmutable|null, note: string|null, noteAt: CarbonImmutable|null, isActive: bool, isMine: bool, isRemovable: bool}>, vacant: list<ReviewerSlot>}> $reviewStages  #[Computed] reviewStages()
 */
trait ManagesProposalReviewers
{
    /** @var array<int|string, string|null> the note being written on each of the viewer's own rows */
    public array $reviewerNotes = [];

    public string $reviewerEmployeeId = '';

    public bool $showRemoveReviewerModal = false;

    #[Locked]
    public ?int $removingReviewerId = null;

    public bool $showNeedAssessmentModal = false;

    /**
     * Drop every memoised reading after a write. Provided by the page.
     */
    abstract private function forgetReadings(): void;

    public function mountManagesProposalReviewers(): void
    {
        $this->fillReviewerNotes();
    }

    /**
     * Each stage that has reviewers or takes them now, with its rows and the
     * default slots that are vacant and have no row. One HR query for names,
     * one for active flags.
     *
     * @return list<array{stage: ReviewStage, isOpen: bool, rows: list<array{id: int, employee: HrEmployee|null, employeeId: int, slot: ReviewerSlot, seenAt: CarbonImmutable|null, note: string|null, noteAt: CarbonImmutable|null, isActive: bool, isMine: bool, isRemovable: bool}>, vacant: list<ReviewerSlot>}>
     */
    #[Computed]
    public function reviewStages(): array
    {
        $status = $this->proposal->status;
        $rows = ProposalReviewer::query()
            ->select(['id', 'proposal_id', 'stage', 'employee_id', 'slot', 'seen_at', 'note', 'updated_at'])
            ->where('proposal_id', $this->proposalId)
            ->orderBy('id')
            ->get();

        $employeeIds = array_values(array_unique($rows->map(fn (ProposalReviewer $row): int => $row->employee_id)->all()));
        $employees = [];

        if ($employeeIds !== []) {
            foreach (HrEmployee::query()->whereKey($employeeIds)->get(HrEmployee::NameColumns) as $employee) {
                $employees[$employee->id] = $employee;
            }
        }

        $active = array_flip(MealCoverage::activeEmployeeIds($employeeIds));
        $myEmployeeId = auth()->user()?->employee_id;
        $stages = [];

        foreach (ReviewStage::cases() as $stage) {
            $stageRows = $rows->filter(fn (ProposalReviewer $row): bool => $row->stage === $stage)
                ->sortBy(fn (ProposalReviewer $row): array => [$row->slot->priority(), $row->id]);

            if ($stageRows->isEmpty() && ReviewStage::forAddingIn($status) !== $stage) {
                continue;
            }

            $taken = $stageRows->map(fn (ProposalReviewer $row): ReviewerSlot => $row->slot)->all();

            $stages[] = [
                'stage' => $stage,
                'isOpen' => $status === $stage->reviewStatus(),
                'rows' => array_values($stageRows->map(fn (ProposalReviewer $row): array => [
                    'id' => $row->id,
                    'employee' => $employees[$row->employee_id] ?? null,
                    'employeeId' => $row->employee_id,
                    'slot' => $row->slot,
                    'seenAt' => $row->seen_at,
                    'note' => $row->note,
                    'noteAt' => $row->note === null ? null : $row->updated_at,
                    'isActive' => isset($active[$row->employee_id]),
                    'isMine' => $myEmployeeId !== null && (int) $myEmployeeId === $row->employee_id,
                    'isRemovable' => $row->isRemovable(),
                ])->all()),
                'vacant' => $stageRows->isEmpty() ? [] : array_values(array_filter(
                    ReviewerSlot::defaultSlots(),
                    fn (ReviewerSlot $slot): bool => ! in_array($slot, $taken, true) && Slots::holderOf($slot->value) === null,
                )),
            ];
        }

        return $stages;
    }

    /**
     * The need assessments filed on the proposal, newest first.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function needAssessments(): Collection
    {
        return $this->proposalFiles->filter(fn (Media $media): bool => $media->collection === MediaCollection::NeedAssessment)->values();
    }

    public function markReviewed(int $reviewerId): void
    {
        $this->saveOwnReview($reviewerId, __('proposals.marked_as_seen'));
    }

    public function saveReviewerNote(int $reviewerId): void
    {
        $this->saveOwnReview($reviewerId, __('proposals.note_saved'));
    }

    public function addReviewer(): void
    {
        Gate::authorize('manageReviewers', $this->proposal);

        $this->validate([
            'reviewerEmployeeId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
        ], [
            'reviewerEmployeeId.required' => __('proposals.choose_a_reviewer'),
            'reviewerEmployeeId.integer' => __('proposals.choose_a_reviewer'),
            'reviewerEmployeeId.exists' => __('proposals.choose_a_reviewer'),
        ]);

        try {
            app(AddProposalReviewer::class)->handle($this->proposal, (int) $this->reviewerEmployeeId);
        } catch (ValidationException $exception) {
            $this->addErrorsFrom($exception, ['reviewerEmployeeId' => 'reviewerEmployeeId']);

            return;
        }

        $this->reviewerEmployeeId = '';
        $this->afterReviewersChanged();

        Flux::toast(variant: 'success', text: __('proposals.reviewer_added'));
    }

    public function confirmRemoveReviewer(int $reviewerId): void
    {
        Gate::authorize('manageReviewers', $this->proposal);

        $this->resetValidation();
        $this->removingReviewerId = $this->proposal->reviewers()->whereKey($reviewerId)->firstOrFail()->id;
        $this->showRemoveReviewerModal = true;
    }

    public function removeReviewer(): void
    {
        Gate::authorize('manageReviewers', $this->proposal);

        if ($this->removingReviewerId === null) {
            return;
        }

        try {
            app(RemoveProposalReviewer::class)->handle($this->proposal, $this->removingReviewerId);
        } catch (ValidationException $exception) {
            $this->addErrorsFrom($exception, ['removeReviewer' => 'removeReviewer']);

            return;
        }

        $this->closeRemoveReviewerModal();
        $this->afterReviewersChanged();

        Flux::toast(variant: 'success', text: __('proposals.reviewer_removed'));
    }

    public function closeRemoveReviewerModal(): void
    {
        $this->showRemoveReviewerModal = false;
        $this->removingReviewerId = null;
    }

    public function openNeedAssessmentModal(): void
    {
        Gate::authorize('uploadNeedAssessment', $this->proposal);

        $this->resetValidation();
        $this->newFile = null;
        $this->showNeedAssessmentModal = true;
    }

    /**
     * Attach a need assessment through SaveProposalFile, with the same upload
     * rules as every proposal file.
     */
    public function saveNeedAssessment(): void
    {
        Gate::authorize('uploadNeedAssessment', $this->proposal);

        $allowedTypes = implode(',', FileType::values());

        $this->validate([
            'newFile' => ['required', 'bail', 'file', 'max:5120', 'mimes:'.$allowedTypes, 'extensions:'.$allowedTypes, new SecureUpload],
        ], [
            'newFile.required' => __('proposals.select_a_file_to_upload'),
        ]);

        app(SaveProposalFile::class)->handle($this->proposal, null, $this->newFile, [
            'fileCategory' => MediaCollection::NeedAssessment->value,
            'fileDisplayName' => '',
        ]);

        $this->closeNeedAssessmentModal();
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.need_assessment_added'));
    }

    public function closeNeedAssessmentModal(): void
    {
        $this->showNeedAssessmentModal = false;
        $this->newFile = null;
        $this->resetValidation();
    }

    /**
     * Mark one's own row as seen and/or store its note. The action decides
     * what changes; a second mark changes nothing.
     */
    private function saveOwnReview(int $reviewerId, string $success): void
    {
        $field = "reviewerNotes.{$reviewerId}";

        $this->validate([$field => ['nullable', 'string', 'max:2000']]);

        try {
            app(MarkProposalReviewed::class)->handle($this->proposal, $reviewerId, $this->reviewerNotes[$reviewerId] ?? null);
        } catch (ValidationException $exception) {
            $this->addErrorsFrom($exception, ['note' => $field]);

            return;
        }

        $this->afterReviewersChanged();

        Flux::toast(variant: 'success', text: $success);
    }

    private function afterReviewersChanged(): void
    {
        $this->forgetReadings();
        $this->fillReviewerNotes();
    }

    /**
     * Pre-fill the note box of each of the viewer's own rows with its note.
     */
    private function fillReviewerNotes(): void
    {
        $employeeId = auth()->user()?->employee_id;

        $this->reviewerNotes = $employeeId === null ? [] : ProposalReviewer::query()
            ->where('proposal_id', $this->proposalId)
            ->where('employee_id', $employeeId)
            ->pluck('note', 'id')
            ->all();
    }

    /**
     * Put an action's refusal on the panel fields.
     *
     * @param  array<string, string>  $fields  action error key => component field
     */
    private function addErrorsFrom(ValidationException $exception, array $fields): void
    {
        foreach ($exception->errors() as $key => $messages) {
            foreach ($messages as $message) {
                $this->addError($fields[$key] ?? $key, $message);
            }
        }
    }
}
