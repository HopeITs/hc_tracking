<?php

use App\Actions\DonorReports\DeleteDonorReportFile;
use App\Actions\DonorReports\SaveDonorReportFile;
use App\Actions\FundingAgreements\MarkDonorReportSubmitted;
use App\Enums\MediaCollection;
use App\Enums\ReportAudience;
use App\Models\Activity;
use App\Models\DonorReport;
use App\Models\Media;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Delivering a donor report (contracts §14.3, D-21): the responsible person
| submits it without any money permission; a donor-facing report needs its
| file; a submitted report is frozen; files load through the report.
*/

beforeEach(function () {
    $this->travelTo('2026-11-15 10:00:00');
    Storage::fake('local');
    $this->responsibleEmployeeId = insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Owner');
    $this->responsible = userWithRole('Team Member', $this->responsibleEmployeeId);
    $this->report = Activity::withoutRecording(fn (): DonorReport => DonorReport::factory()->composed()->create([
        'responsible_employee_id' => $this->responsibleEmployeeId,
        'due_on' => '2026-11-20',
    ]));
});

function dbSubmit(User $actor, DonorReport $report, string $submittedOn = '2026-11-15', array $uploads = []): DonorReport
{
    test()->actingAs($actor);

    return app(MarkDonorReportSubmitted::class)->handle($report->fresh(), $submittedOn, $uploads, $actor);
}

function dbAddFile(User $actor, DonorReport $report, ?UploadedFile $upload = null): Media
{
    test()->actingAs($actor);

    return app(SaveDonorReportFile::class)->handle($report->fresh(), $upload ?? validUpload('report.pdf'), $actor);
}

test('the responsible person submits their own report with its file, without any money permission', function () {
    expect($this->responsible->can('funding-agreements.view'))->toBeFalse();

    $submitted = dbSubmit($this->responsible, $this->report, '2026-11-14', [validUpload('final.pdf')]);

    $file = $submitted->media()->sole();

    expect($submitted->submitted_on->toDateString())->toBe('2026-11-14')
        ->and($file->collection)->toBe(MediaCollection::ReportSubmission)
        ->and($file->original_name)->toBe('final.pdf')
        ->and($file->encryption_key_id)->not->toBeNull()
        ->and($file->path)->toStartWith('media/donor-reports/'.$this->report->id.'/')
        ->and(Activity::query()->where('event', 'donor_report.submitted')->where('subject_id', $this->report->id)->exists())->toBeTrue();

    Storage::disk('local')->assertExists($file->path);
});

test('a file added before submitting counts for the submission', function () {
    dbAddFile($this->responsible, $this->report);

    expect(dbSubmit($this->responsible, $this->report)->isSubmitted())->toBeTrue()
        ->and(Activity::query()->where('event', 'donor_report.file_added')->where('subject_id', $this->report->id)->exists())->toBeTrue();
});

test('a donor-facing report without a file is refused, an internal one is accepted', function () {
    expect(fn () => dbSubmit($this->responsible, $this->report))->toThrow(ValidationException::class, __('donor_reports.file_required'))
        ->and($this->report->fresh()->submitted_on)->toBeNull();

    $this->report->update(['audience' => ReportAudience::Internal]);

    expect(dbSubmit($this->responsible, $this->report)->isSubmitted())->toBeTrue()
        ->and(Media::query()->count())->toBe(0);
});

test('a submission date in the future is refused', function () {
    expect(fn () => dbSubmit($this->responsible, $this->report, '2026-11-16', [validUpload('final.pdf')]))
        ->toThrow(ValidationException::class, __('donor_reports.submitted_in_future'));

    expect($this->report->fresh()->submitted_on)->toBeNull()
        ->and(Media::query()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('/');
});

test('a second submission writes nothing', function () {
    dbSubmit($this->responsible, $this->report, '2026-11-10', [validUpload('final.pdf')]);

    expect(fn () => dbSubmit($this->responsible, $this->report, '2026-11-15', [validUpload('again.pdf')]))
        ->toThrow(ValidationException::class, __('donor_reports.already_submitted'));

    expect($this->report->fresh()->submitted_on->toDateString())->toBe('2026-11-10')
        ->and(Media::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

test('an invalid upload among several writes no file at all', function () {
    expect(fn () => dbSubmit($this->responsible, $this->report, '2026-11-15', [
        validUpload('final.pdf'),
        UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'),
    ]))->toThrow(ValidationException::class);

    expect($this->report->fresh()->submitted_on)->toBeNull()
        ->and(Media::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('another employee may not submit or touch files; a funding-agreements.update holder may', function () {
    $other = userWithRole('Team Member', insertHrEmployee());

    expect(fn () => dbSubmit($other, $this->report, '2026-11-15', [validUpload('final.pdf')]))->toThrow(AuthorizationException::class)
        ->and(fn () => dbAddFile($other, $this->report))->toThrow(AuthorizationException::class)
        ->and($this->report->fresh()->submitted_on)->toBeNull()
        ->and(Media::query()->count())->toBe(0);

    expect(dbSubmit(userWithRole('Financial'), $this->report, '2026-11-15', [validUpload('final.pdf')])->isSubmitted())->toBeTrue();
});

test('a file of another report cannot be removed through this one', function () {
    $foreignReport = Activity::withoutRecording(fn (): DonorReport => DonorReport::factory()->create(['responsible_employee_id' => $this->responsibleEmployeeId]));
    $foreign = dbAddFile($this->responsible, $foreignReport);

    expect(fn () => app(DeleteDonorReportFile::class)->handle($this->report->fresh(), $foreign->id))->toThrow(ModelNotFoundException::class)
        ->and(Media::query()->whereKey($foreign->id)->exists())->toBeTrue();

    $own = dbAddFile($this->responsible, $this->report);
    app(DeleteDonorReportFile::class)->handle($this->report->fresh(), $own->id);

    expect(Media::query()->whereKey($own->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'donor_report.file_removed')->exists())->toBeTrue();
    Storage::disk('local')->assertMissing($own->path);
});

test('the files of a submitted report are frozen', function () {
    $file = dbAddFile($this->responsible, $this->report);
    dbSubmit($this->responsible, $this->report);

    expect(fn () => dbAddFile($this->responsible, $this->report))->toThrow(ValidationException::class, __('donor_reports.already_submitted'))
        ->and(fn () => app(DeleteDonorReportFile::class)->handle($this->report->fresh(), $file->id))->toThrow(ValidationException::class, __('donor_reports.already_submitted'))
        ->and($this->report->media()->count())->toBe(1);
});

test('the download honours the report policy, the report in the url and the files throttle', function () {
    $file = dbAddFile($this->responsible, $this->report, validUpload('final.pdf'));
    $foreignReport = Activity::withoutRecording(fn (): DonorReport => DonorReport::factory()->create(['responsible_employee_id' => $this->responsibleEmployeeId]));
    $url = fn (DonorReport $report, Media $media): string => route('donor-reports.files.download', [$report, $media]);

    $this->actingAs($this->responsible)->get($url($this->report, $file))->assertOk()->assertDownload('final.pdf');
    $this->actingAs(userWithRole('Financial'))->get($url($this->report, $file))->assertOk();
    $this->actingAs(userWithRole('Team Member', insertHrEmployee()))->get($url($this->report, $file))->assertForbidden();
    $this->actingAs($this->responsible)->get($url($foreignReport, $file))->assertNotFound();

    expect(Route::getRoutes()->getByName('donor-reports.files.download')->gatherMiddleware())->toContain('throttle:files');
});
