<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MonthlyReports;

use App\Actions\MonthlyReports\DeleteMonthlyReportFile;
use App\Actions\MonthlyReports\SaveMonthlyReportFile;
use App\Enums\IndicatorCollector;
use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Typed evidence on monthly-reports/show (T17B slice 2; D-42, contracts §13,
 * §16): attach a file of an evidence type to a line or an activity entry
 * through SaveMonthlyReportFile, and delete one through
 * DeleteMonthlyReportFile. The preparer side does this on a draft; the
 * validator on `meal` lines while submitted. The line, entry or file is
 * found in this report before a dialog opens; the actions load them through
 * the report again and authorize.
 *
 * Requires ReadsMonthlyReport.
 *
 * @property-read MonthlyReport $report  #[Computed] report()
 */
trait ManagesMonthlyReportFiles
{
    use WithFileUploads;

    public bool $showFileModal = false;

    public bool $showDeleteFileModal = false;

    #[Locked]
    public ?int $fileLineId = null;

    #[Locked]
    public ?int $fileEntryId = null;

    #[Locked]
    public ?int $deletingFileId = null;

    public string $newFileTypeId = '';

    /** @var TemporaryUploadedFile|null */
    public $newFile = null;

    public function openFileModal(?int $lineId = null, ?int $entryId = null): void
    {
        $report = $this->report;

        if ($lineId !== null) {
            $line = $report->lines->firstWhere('id', $lineId) ?? abort(404);
            $this->authorizeFileSide($line->entered_by === IndicatorCollector::Meal);
        } else {
            abort_if($entryId === null || $report->activityEntries->firstWhere('id', $entryId) === null, 404);
            $this->authorizeFileSide(false);
        }

        $this->resetErrorBag();
        $this->fileLineId = $lineId;
        $this->fileEntryId = $lineId === null ? $entryId : null;
        $this->newFileTypeId = '';
        $this->newFile = null;
        $this->showFileModal = true;
    }

    public function saveFile(): void
    {
        $this->validate([
            'newFileTypeId' => ['required', 'integer'],
            'newFile' => ['required', 'file'],
        ], [
            'newFileTypeId.required' => __('monthly_reports.evidence_type_required'),
            'newFile.required' => __('monthly_reports.file_required'),
        ]);

        try {
            app(SaveMonthlyReportFile::class)->handle(
                $this->report,
                $this->renderedStatus(),
                (int) $this->newFileTypeId,
                $this->newFile,
                $this->fileLineId,
                $this->fileEntryId,
            );
        } catch (ValidationException $exception) {
            $this->showFileErrors($exception->errors());

            return;
        } catch (AuthorizationException|ModelNotFoundException) {
            $this->refuseFileChange();

            return;
        }

        $this->closeFileModal();
        Flux::toast(variant: 'success', text: __('monthly_reports.toast_file_uploaded'));
        $this->forgetMonthlyReport();
    }

    public function closeFileModal(): void
    {
        $this->showFileModal = false;
        $this->fileLineId = null;
        $this->fileEntryId = null;
        $this->newFileTypeId = '';
        $this->newFile = null;
    }

    public function confirmDeleteFile(int $fileId): void
    {
        $file = $this->report->files->firstWhere('id', $fileId) ?? abort(404);
        $line = $file->monthly_report_line_id === null ? null : $this->report->lines->firstWhere('id', $file->monthly_report_line_id);
        $this->authorizeFileSide($line?->entered_by === IndicatorCollector::Meal);

        $this->deletingFileId = $file->id;
        $this->showDeleteFileModal = true;
    }

    public function closeDeleteFileModal(): void
    {
        $this->showDeleteFileModal = false;
        $this->deletingFileId = null;
    }

    public function deleteFile(): void
    {
        if ($this->deletingFileId === null) {
            return;
        }

        try {
            app(DeleteMonthlyReportFile::class)->handle($this->report, $this->renderedStatus(), $this->deletingFileId);
        } catch (ValidationException $exception) {
            $this->closeDeleteFileModal();
            Flux::toast(variant: 'danger', text: collect($exception->errors())->flatten()->first());
            $this->forgetMonthlyReport();

            return;
        } catch (AuthorizationException|ModelNotFoundException) {
            $this->closeDeleteFileModal();
            $this->refuseFileChange();

            return;
        }

        $this->closeDeleteFileModal();
        Flux::toast(variant: 'success', text: __('monthly_reports.toast_file_deleted'));
        $this->forgetMonthlyReport();
    }

    /**
     * The UI check before a dialog opens: the preparer side on a draft, the
     * validator on a `meal` line while submitted. The action authorizes.
     */
    protected function authorizeFileSide(bool $onMealLine): void
    {
        $byValidator = $this->report->status === MonthlyReportStatus::Submitted;

        if ($byValidator !== $onMealLine) {
            throw new AuthorizationException;
        }

        Gate::authorize($byValidator ? 'fillMealLines' : 'update', $this->report);
    }

    /**
     * Upload errors go on the dialog's fields; anything about the report
     * (stale) is a toast and a reload.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function showFileErrors(array $errors): void
    {
        $fields = ['upload' => 'newFile', 'file' => 'newFile', 'evidence_type_id' => 'newFileTypeId'];

        foreach ($errors as $key => $messages) {
            if (! isset($fields[$key])) {
                $this->closeFileModal();
                Flux::toast(variant: 'danger', text: $messages[0]);
                $this->forgetMonthlyReport();

                return;
            }

            $this->addError($fields[$key], $messages[0]);
        }
    }

    protected function refuseFileChange(): void
    {
        $this->closeFileModal();
        $locked = $this->report->status !== MonthlyReportStatus::Draft;
        Flux::toast(variant: 'danger', text: $locked ? __('monthly_reports.locked') : __('monthly_reports.not_allowed'));
        $this->forgetMonthlyReport();
    }
}
