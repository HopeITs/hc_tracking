<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportRisk;
use App\Models\Risk;
use App\Support\MonthlyReportGuard;
use App\Support\MonthlyReportRisks;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Save the optional "Update risks" line of a draft: whether the report
 * carries it, and for each listed risk whether it happened this month. Each
 * answer is stored on the report (monthly_report_risks) and becomes the
 * risk's last answer (risks.happened). One unlisted risk id rejects the
 * whole batch. Turning the line off keeps the saved answers.
 */
final class SaveMonthlyReportRisks
{
    /**
     * @param  array<int, bool>  $happened  risk id => happened
     */
    public function handle(MonthlyReport $report, MonthlyReportStatus $expectedStatus, bool $updatesRisks, array $happened = []): MonthlyReport
    {
        return DB::transaction(function () use ($report, $expectedStatus, $updatesRisks, $happened): MonthlyReport {
            $locked = MonthlyReportGuard::lock($report, $expectedStatus, 'update');

            /** @var array<int, Risk> $listed */
            $listed = MonthlyReportRisks::for($locked)->mapWithKeys(fn (array $row): array => [$row['risk']->id => $row['risk']])->all();

            foreach (array_keys($happened) as $riskId) {
                if (! isset($listed[(int) $riskId])) {
                    throw (new ModelNotFoundException)->setModel(Risk::class, [(int) $riskId]);
                }
            }

            $locked->forceFill(['updates_risks' => $updatesRisks])->save();

            foreach ($happened as $riskId => $didHappen) {
                MonthlyReportRisk::query()->updateOrCreate(
                    ['monthly_report_id' => $locked->id, 'risk_id' => (int) $riskId],
                    ['happened' => (bool) $didHappen],
                );

                $risk = $listed[(int) $riskId];
                $risk->happened = (bool) $didHappen;
                $risk->save();
            }

            return $locked;
        });
    }
}
