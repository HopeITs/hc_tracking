<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\DeleteProposalFile;
use App\Actions\Proposals\RecordFinalReportAcceptance;
use App\Actions\Proposals\SaveProposalFile;
use App\Enums\FileType;
use App\Enums\MediaCollection;
use App\Enums\MilestoneStatus;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\Media;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\User;
use App\Rules\SecureUpload;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The "Record final report acceptance" dialog of proposals/show (W-05, D-02c).
 *
 * The acceptance date and the optional acceptance email go to
 * RecordFinalReportAcceptance, which authorizes, locks and validates again;
 * the file is filed through SaveProposalFile first and removed when the
 * action refuses. The page provides proposalId, the #[Computed] proposal()
 * (with donor_id and the two acceptance columns) and forgetReadings().
 *
 * @property int $proposalId
 * @property-read Proposal $proposal  #[Computed] proposal()
 * @property-read bool $canRecordFinalReportAcceptance  #[Computed] canRecordFinalReportAcceptance()
 * @property-read array{date: string, name: string}|null $finalReportAcceptance  #[Computed] finalReportAcceptance()
 */
trait ManagesFinalReportAcceptance
{
    public bool $showAcceptanceModal = false;

    public string $finalReportAcceptedOn = '';

    /** @var TemporaryUploadedFile|null */
    public $finalReportAcceptanceFile = null;

    /**
     * Drop every memoised reading after a write. Provided by the page.
     */
    abstract private function forgetReadings(): void;

    /**
     * Whether the button applies: the viewer may edit the proposal, it is
     * active and donor-funded, its final-report milestone is reached, the
     * final report is filed and the acceptance is not recorded yet.
     */
    #[Computed]
    public function canRecordFinalReportAcceptance(): bool
    {
        $proposal = $this->proposal;

        if (auth()->user()?->can('update', $proposal) !== true
            || $proposal->status !== ProposalStatus::Active
            || $proposal->final_report_accepted_on !== null
            || (bool) Donor::query()->whereKey($proposal->donor_id)->value('is_internal')) {
            return false;
        }

        $milestoneReached = Milestone::query()
            ->where('proposal_id', $proposal->id)
            ->where('is_final_report', true)
            ->where('status', MilestoneStatus::Reached)
            ->exists();

        return $milestoneReached && $proposal->media()->where('collection', MediaCollection::FinalReport)->exists();
    }

    /**
     * The recorded acceptance, ready to show: the date and the acceptor's name.
     *
     * @return array{date: string, name: string}|null
     */
    #[Computed]
    public function finalReportAcceptance(): ?array
    {
        $proposal = $this->proposal;

        if ($proposal->final_report_accepted_on === null) {
            return null;
        }

        $acceptor = $proposal->final_report_accepted_by === null ? null : User::query()->find($proposal->final_report_accepted_by);

        return [
            'date' => $proposal->final_report_accepted_on->translatedFormat(__('formats.date')),
            'name' => $acceptor?->name ?? '',
        ];
    }

    public function openAcceptanceModal(): void
    {
        Gate::authorize('update', $this->proposal);

        if (! $this->canRecordFinalReportAcceptance) {
            return;
        }

        $this->resetFinalReportForm();
        $this->finalReportAcceptedOn = CarbonImmutable::today()->toDateString();
        $this->showAcceptanceModal = true;
    }

    public function recordFinalReportAcceptance(): void
    {
        $proposal = Proposal::query()->findOrFail($this->proposalId);

        Gate::authorize('update', $proposal);

        $allowedTypes = implode(',', FileType::values());

        $this->validate([
            'finalReportAcceptedOn' => ['required', 'date'],
            'finalReportAcceptanceFile' => ['nullable', 'bail', 'file', 'max:5120', 'mimes:'.$allowedTypes, 'extensions:'.$allowedTypes, new SecureUpload],
        ]);

        $savedFile = $this->finalReportAcceptanceFile === null ? null : $this->saveAcceptanceFile($proposal);

        try {
            app(RecordFinalReportAcceptance::class)->handle($proposal, CarbonImmutable::parse($this->finalReportAcceptedOn));
        } catch (ValidationException $exception) {
            if ($savedFile !== null) {
                app(DeleteProposalFile::class)->handle($proposal, $savedFile);
            }

            $this->showFinalReportErrors($exception);

            return;
        }

        $this->closeAcceptanceModal();
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.final_report_acceptance_recorded'));
    }

    public function closeAcceptanceModal(): void
    {
        $this->showAcceptanceModal = false;
        $this->resetFinalReportForm();
    }

    private function saveAcceptanceFile(Proposal $proposal): Media
    {
        return app(SaveProposalFile::class)->handle($proposal, null, $this->finalReportAcceptanceFile, [
            'fileDisplayName' => '',
            'fileCategory' => MediaCollection::FinalReportAcceptance->value,
        ]);
    }

    /**
     * Put the action's refusal on the dialog fields; the missing final
     * report has no field, so it goes to the gate alert.
     */
    private function showFinalReportErrors(ValidationException $exception): void
    {
        $fields = ['accepted_on' => 'finalReportAcceptedOn'];

        foreach ($exception->errors() as $key => $messages) {
            foreach ($messages as $message) {
                $this->addError($fields[$key] ?? 'finalReportGate', $message);
            }
        }
    }

    private function resetFinalReportForm(): void
    {
        $this->resetValidation();
        $this->finalReportAcceptedOn = '';
        $this->finalReportAcceptanceFile = null;
        unset($this->canRecordFinalReportAcceptance, $this->finalReportAcceptance);
    }
}
