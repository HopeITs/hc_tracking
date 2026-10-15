<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\DeleteProposalFile;
use App\Actions\Proposals\SaveProposalFile;
use App\Enums\FileType;
use App\Enums\MediaCollection;
use App\Enums\ProposalStatus;
use App\Models\Media;
use App\Models\Project;
use App\Models\Proposal;
use App\Rules\SecureUpload;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The Approve Proposal dialog of proposals/show (T08).
 *
 * The donor's decision, the grant dates and the evidence (a file or a note)
 * go to ApproveProposal, which authorizes, locks and validates again. The
 * preview of what each linked project will do comes from the same action
 * (ApproveProposal::preview), so the dialog and the result agree. Ids that
 * decide the outcome are #[Locked]; the page provides forgetReadings().
 *
 * @property-read Proposal|null $approvalSubject  #[Computed] approvalSubject()
 * @property-read array<int, array{name: string, outcome: string}> $approvalPreview  #[Computed] approvalPreview()
 */
trait ManagesProposalApproval
{
    public bool $showApproveModal = false;

    #[Locked]
    public ?int $approveProposalId = null;

    #[Locked]
    public string $approveExpectedFrom = '';

    public string $approveBegin = '';

    public string $approveEnd = '';

    public string $approveDecisionDate = '';

    public string $approveNote = '';

    /** `file` or `note`: which kind of donor-approval evidence is being given. */
    public string $approveEvidenceMode = 'file';

    public string $approveEvidenceNote = '';

    /** @var TemporaryUploadedFile|null */
    public $approveFile = null;

    /**
     * Drop every memoised reading after a write. Provided by the page.
     */
    abstract private function forgetReadings(): void;

    public function openApproveModal(int $proposalId): void
    {
        $proposal = Proposal::query()->select(['id', 'status', 'planned_date_begin', 'planned_date_end'])->findOrFail($proposalId);

        Gate::authorize('approve', $proposal);

        if (! $proposal->canBeApproved()) {
            return;
        }

        $this->resetApprovalForm();
        $this->approveProposalId = $proposal->id;
        $this->approveExpectedFrom = $proposal->status->value;

        $today = CarbonImmutable::today();
        $plannedBegin = CarbonImmutable::parse($proposal->planned_date_begin->toDateString());
        $plannedEnd = CarbonImmutable::parse($proposal->planned_date_end->toDateString());
        $begin = $plannedBegin->lessThan($today) ? $today : $plannedBegin;

        // Keep the planned length when the start moves to the decision date.
        $end = $plannedEnd->addDays((int) $plannedBegin->diffInDays($begin));

        $this->approveDecisionDate = $today->toDateString();
        $this->approveBegin = $begin->toDateString();
        $this->approveEnd = $end->toDateString();
        $this->showApproveModal = true;
    }

    /**
     * The proposal the open dialog is about.
     */
    #[Computed]
    public function approvalSubject(): ?Proposal
    {
        return $this->approveProposalId === null
            ? null
            : Proposal::query()->select(['id', 'status', 'planned_date_begin', 'planned_date_end'])->find($this->approveProposalId);
    }

    /**
     * What approving would do to each linked project, as text badges:
     * funded, waits for the owner, extended or skipped.
     *
     * @return array<int, array{name: string, outcome: string}>
     */
    #[Computed]
    public function approvalPreview(): array
    {
        $subject = $this->approvalSubject;

        if ($subject === null) {
            return [];
        }

        $outcomes = app(ApproveProposal::class)->preview($subject);
        $names = Project::query()->whereIn('id', array_keys($outcomes))->pluck('name', 'id');
        $preview = [];

        foreach ($outcomes as $projectId => $outcome) {
            $preview[$projectId] = ['name' => (string) $names->get($projectId), 'outcome' => $outcome];
        }

        return $preview;
    }

    public function approveProposal(): void
    {
        $proposal = Proposal::query()->findOrFail($this->approveProposalId);

        Gate::authorize('approve', $proposal);

        $this->resetValidation();

        $isFile = $this->approveEvidenceMode === 'file';

        if ($isFile && $this->approveFile !== null) {
            $allowedTypes = implode(',', FileType::values());

            $this->validate([
                'approveFile' => ['bail', 'file', 'max:5120', 'mimes:'.$allowedTypes, 'extensions:'.$allowedTypes, new SecureUpload],
            ]);
        }

        $preview = $this->approvalPreview;
        $savedFile = $isFile && $this->approveFile !== null ? $this->saveEvidenceFile($proposal) : null;

        try {
            app(ApproveProposal::class)->handle($proposal, ProposalStatus::from($this->approveExpectedFrom), [
                'actual_date_begin' => $this->approveBegin,
                'actual_date_end' => $this->approveEnd,
                'decision_date' => $this->approveDecisionDate,
                'note' => trim($this->approveNote) === '' ? null : trim($this->approveNote),
                'approval_note' => $isFile ? null : trim($this->approveEvidenceNote),
            ]);
        } catch (ValidationException $exception) {
            if ($savedFile !== null) {
                app(DeleteProposalFile::class)->handle($proposal, $savedFile);
            }

            $this->showApprovalErrors($exception, $isFile);

            return;
        }

        $this->closeApproveModal();
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.approval_done'));

        $summaries = [
            'funded' => fn (string $names): string => __('proposals.approval_result_funded', ['names' => $names]),
            'waiting' => fn (string $names): string => __('proposals.approval_result_waiting', ['names' => $names]),
            'extended' => fn (string $names): string => __('proposals.approval_result_extended', ['names' => $names]),
            'skipped' => fn (string $names): string => __('proposals.approval_result_skipped', ['names' => $names]),
        ];

        foreach ($summaries as $outcome => $summary) {
            $names = collect($preview)->where('outcome', $outcome)->pluck('name')->implode(', ');

            if ($names !== '') {
                Flux::toast(variant: $outcome === 'skipped' ? 'warning' : 'success', text: $summary($names));
            }
        }

        if (! $proposal->donorProposals()->exists()) {
            Flux::toast(variant: 'warning', text: __('proposals.approval_warning_no_agreement'));
        }
    }

    public function closeApproveModal(): void
    {
        $this->showApproveModal = false;
        $this->resetApprovalForm();
    }

    /**
     * Reload the dialog after a "changed by someone else" refusal.
     */
    public function reloadApproveModal(): void
    {
        $id = $this->approveProposalId;

        if ($id !== null) {
            $this->forgetReadings();
            $this->openApproveModal($id);
        }
    }

    private function saveEvidenceFile(Proposal $proposal): Media
    {
        return app(SaveProposalFile::class)->handle($proposal, null, $this->approveFile, [
            'fileDisplayName' => '',
            'fileCategory' => MediaCollection::DonorApproval->value,
        ]);
    }

    /**
     * Put the action's refusal on the dialog fields; keys with no field go to the gate alert.
     */
    private function showApprovalErrors(ValidationException $exception, bool $isFile): void
    {
        $fields = [
            'actual_date_begin' => 'approveBegin',
            'actual_date_end' => 'approveEnd',
            'decision_date' => 'approveDecisionDate',
            'note' => 'approveNote',
            'approval_note' => $isFile ? 'approveFile' : 'approveEvidenceNote',
        ];

        foreach ($exception->errors() as $key => $messages) {
            foreach ($messages as $message) {
                $this->addError($fields[$key] ?? 'approveGate', $message);
            }
        }
    }

    private function resetApprovalForm(): void
    {
        $this->resetValidation();
        $this->approveProposalId = null;
        $this->approveExpectedFrom = '';
        $this->approveBegin = '';
        $this->approveEnd = '';
        $this->approveDecisionDate = '';
        $this->approveNote = '';
        $this->approveEvidenceMode = 'file';
        $this->approveEvidenceNote = '';
        $this->approveFile = null;
        unset($this->approvalSubject, $this->approvalPreview);
    }
}
