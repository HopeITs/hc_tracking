<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportActivityEntry;
use App\Models\Result;
use App\Support\MonthlyReportActivities;
use App\Support\MonthlyReportGuard;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Save the activities this month (contracts §13, D-51) on a draft. Each
 * entry names an activity the report lists (MonthlyReportActivities); one
 * unlisted id rejects the whole batch. The inputs each status needs:
 * Not started → reason ≥ 10; Started → start date; Suspended / Cancelled →
 * reason; Completed → completion date; Conducted (events only) → execution
 * date. Evidence for Completed / Conducted is checked by Submit. Inputs a
 * status does not use are stored as NULL.
 */
final class SaveMonthlyReportActivities
{
    /**
     * @param  list<array{result_id: int, status?: ?string, reason?: ?string, started_on?: ?string, completed_on?: ?string, executed_on?: ?string, note?: ?string}>  $entries
     */
    public function handle(MonthlyReport $report, MonthlyReportStatus $expectedStatus, array $entries): MonthlyReport
    {
        return DB::transaction(function () use ($report, $expectedStatus, $entries): MonthlyReport {
            $locked = MonthlyReportGuard::lock($report, $expectedStatus, 'update');

            Validator::make(['entries' => $entries], [
                'entries' => ['array', 'list'],
                'entries.*.result_id' => ['required', 'integer', 'distinct'],
                'entries.*.status' => ['nullable', Rule::enum(ActivityStatus::class)],
                'entries.*.reason' => ['nullable', 'string', 'max:2000'],
                'entries.*.started_on' => ['nullable', 'date'],
                'entries.*.completed_on' => ['nullable', 'date'],
                'entries.*.executed_on' => ['nullable', 'date'],
                'entries.*.note' => ['nullable', 'string', 'max:2000'],
            ])->validate();

            /** @var array<int, Result> $listed */
            $listed = MonthlyReportActivities::for($locked)->mapWithKeys(fn (array $row): array => [$row['activity']->id => $row['activity']])->all();

            foreach ($entries as $entry) {
                if (! isset($listed[(int) $entry['result_id']])) {
                    throw (new ModelNotFoundException)->setModel(Result::class, [(int) $entry['result_id']]);
                }
            }

            $errors = [];
            $rows = [];

            foreach ($entries as $index => $entry) {
                $activity = $listed[(int) $entry['result_id']];
                $status = isset($entry['status']) ? ActivityStatus::from((string) $entry['status']) : null;
                $reason = trim((string) ($entry['reason'] ?? ''));

                foreach (self::problems($status, $activity->activity_kind ?? ActivityKind::Continuous, $reason, $entry) as $field => $key) {
                    $errors["entries.{$index}.{$field}"] = __("monthly_reports.{$key}");
                }

                $rows[] = [
                    'result_id' => $activity->id,
                    'status' => $status,
                    'reason' => in_array($status, [ActivityStatus::NotStarted, ActivityStatus::Suspended, ActivityStatus::Cancelled], true) ? $reason : null,
                    'started_on' => $status === ActivityStatus::Started ? ($entry['started_on'] ?? null) : null,
                    'completed_on' => $status === ActivityStatus::Completed ? ($entry['completed_on'] ?? null) : null,
                    'executed_on' => $status === ActivityStatus::Conducted ? ($entry['executed_on'] ?? null) : null,
                    'note' => isset($entry['note']) && trim((string) $entry['note']) !== '' ? trim((string) $entry['note']) : null,
                ];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            foreach ($rows as $row) {
                MonthlyReportActivityEntry::query()->updateOrCreate(
                    ['monthly_report_id' => $locked->id, 'result_id' => $row['result_id']],
                    $row,
                );
            }

            return $locked;
        });
    }

    /**
     * The missing or invalid inputs of one entry, as field => lang key.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, string>
     */
    private static function problems(?ActivityStatus $status, ActivityKind $kind, string $reason, array $entry): array
    {
        if ($status === null) {
            return [];
        }

        if (! in_array($status, ActivityStatus::allowedFor($kind), true)) {
            return ['status' => 'activity_status_not_allowed'];
        }

        $missing = fn (string $field): bool => ! isset($entry[$field]) || (string) $entry[$field] === '';

        return match ($status) {
            ActivityStatus::NotStarted => mb_strlen($reason) < 10 ? ['reason' => 'activity_reason_min'] : [],
            ActivityStatus::Suspended, ActivityStatus::Cancelled => $reason === '' ? ['reason' => 'activity_reason_required'] : [],
            ActivityStatus::Started => $missing('started_on') ? ['started_on' => 'activity_date_required'] : [],
            ActivityStatus::Ongoing => [],
            ActivityStatus::Completed => $missing('completed_on') ? ['completed_on' => 'activity_date_required'] : [],
            ActivityStatus::Conducted => $missing('executed_on') ? ['executed_on' => 'activity_date_required'] : [],
        };
    }
}
