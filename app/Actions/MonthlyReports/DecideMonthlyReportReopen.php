<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Enums\MonthlyReportStatus;
use App\Enums\ReopenRequestStatus;
use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportReopenRequest;
use App\Support\MonthlyReportActivityStatuses;
use App\Support\MonthlyReportGuard;
use App\Support\MonthlyReportMirrors;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grant or deny a pending reopen request (contracts §13 Workflow, D-09a).
 * Grant → the report and its mirrors return to draft, so its figures leave
 * achievement until it is validated again, and the activity statuses it
 * confirmed are restored (slice 4). Deny keeps it validated and needs a
 * note. The request loads through the report (BOLA); the requester is told
 * (`monthly_report.reopen_decided`).
 */
final class DecideMonthlyReportReopen
{
    public function __construct(
        private MonthlyReportMirrors $mirrors,
        private MonthlyReportActivityStatuses $activityStatuses,
    ) {}

    public function handle(MonthlyReport $report, int $requestId, MonthlyReportStatus $expectedStatus, bool $grant, ?string $note = null): MonthlyReportReopenRequest
    {
        $note = $note === null ? null : trim($note);

        if (! $grant && ($note === null || $note === '')) {
            throw ValidationException::withMessages(['decision_note' => __('monthly_reports.decision_note_required')]);
        }

        $request = $report->reopenRequests()->whereKey($requestId)->firstOrFail();

        return DB::transaction(function () use ($report, $request, $expectedStatus, $grant, $note): MonthlyReportReopenRequest {
            $locked = MonthlyReportGuard::lock($report, $expectedStatus, 'decideReopen', [
                MonthlyReportReopenRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail(),
            ]);
            $request->refresh();

            if ($grant) {
                $this->activityStatuses->restore($locked);
                $locked->fill(['status' => MonthlyReportStatus::Draft, 'validated_at' => null, 'validated_by' => null]);
                Activity::withoutRecording(fn () => $locked->save());
                $this->mirrors->moveTo($locked, null);
            }

            $request->fill([
                'status' => $grant ? ReopenRequestStatus::Granted : ReopenRequestStatus::Denied,
                'decided_by' => (int) Auth::id(),
                'decided_at' => now(),
                'decision_note' => $note === '' ? null : $note,
            ])->save();

            $locked->recordActivity('monthly_report.reopen_decided', properties: [
                'request_id' => $request->id,
                'requester_id' => $request->requested_by,
                'granted' => $grant,
            ]);

            return $request;
        });
    }
}
