<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ReportStatus;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\MonthlyReport;
use App\Models\PlanIndicator;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Fills the Report Tracker with a few reports of every type and state, on top
 * of the data already there.
 *
 * - Monthly reports: left to MonthlyMatrixDemoSeeder (validated, submitted and
 *   draft months); it runs here only when no monthly report exists yet.
 * - Donor reports: for up to three existing funding agreements, one overdue,
 *   one due soon and one submitted, the open ones owned by a MEAL user.
 * - Existing (narrative) reports: for up to four existing plan indicators, a
 *   late draft, an upcoming draft, a report waiting for approval and an
 *   approved one.
 *
 * Not part of DatabaseSeeder. Run it by hand:
 *
 *     php artisan db:seed --class=ReportTrackerDemoSeeder
 *
 * Safe to run again: donor reports are matched on agreement and title,
 * narrative reports on indicator and planned date. No notification is sent.
 */
class ReportTrackerDemoSeeder extends Seeder
{
    private const string TitlePrefix = 'Demo · ';

    public function run(): void
    {
        if (MonthlyReport::query()->doesntExist()) {
            $this->call(MonthlyMatrixDemoSeeder::class);
        }

        $today = CarbonImmutable::today();
        $meal = User::query()->role('MEAL')->whereNotNull('employee_id')->first();
        $author = $meal ?? User::query()->first();

        Model::unguarded(fn () => Activity::withoutRecording(function () use ($today, $meal, $author): void {
            $this->donorReports($today, $meal?->employee_id);

            if ($author !== null) {
                $this->narrativeReports($today, $author);
            }
        }));
    }

    private function donorReports(CarbonImmutable $today, ?int $employeeId): void
    {
        $states = [
            ['Interim report', $today->subDays(12), false],
            ['Quarterly update', $today->addDays(10), false],
            ['Final report', $today->subMonths(2), true],
        ];

        foreach (DonorProposal::query()->limit(3)->get() as $agreement) {
            foreach ($states as [$title, $due, $submitted]) {
                DonorReport::query()->firstOrCreate(
                    ['donor_proposal_id' => $agreement->id, 'title' => self::TitlePrefix.$title],
                    [
                        'due_on' => $due->toDateString(),
                        'submitted_on' => $submitted ? $due->toDateString() : null,
                        'responsible_employee_id' => $submitted ? null : $employeeId,
                    ],
                );
            }
        }
    }

    private function narrativeReports(CarbonImmutable $today, User $author): void
    {
        $states = [
            [$today->subDays(20), ReportStatus::Draft],
            [$today->addDays(15), ReportStatus::Draft],
            [$today->subDays(5), ReportStatus::Reviewed],
            [$today->subMonths(2), ReportStatus::Approved],
        ];

        foreach (PlanIndicator::query()->limit(4)->get() as $planIndicator) {
            foreach ($states as [$planned, $status]) {
                Report::query()->firstOrCreate(
                    ['plan_indicator_id' => $planIndicator->id, 'planned_date' => $planned->toDateString()],
                    [
                        'user_id' => $author->id,
                        'status' => $status,
                        'value' => min(10, (float) ($planIndicator->target ?? 10)),
                        'submit_date' => $status === ReportStatus::Draft ? null : $planned->toDateString(),
                        'is_seen_by_meal' => $status === ReportStatus::Approved,
                    ],
                );
            }
        }
    }
}
