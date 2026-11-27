<?php

declare(strict_types=1);

namespace App\Actions\DonorReports;

use App\Models\DonorReport;
use App\Models\HrEmployee;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Name who delivers a donor report, or clear it, from the report's own page
 * (page review, 6 Nov 2026: every report read "Not assigned" and nothing on
 * the page could change it). The person then finds it in My Reporting Duties.
 *
 * - Who: `assign` on the report (funding-agreements.update or
 *   reports.aggregate), only while it is not submitted.
 * - The person must be an active HR employee, as the builder requires.
 * - The change is logged by the model (activityEmployeeFields).
 */
final class AssignDonorReportResponsible
{
    public function handle(DonorReport $report, ?int $employeeId, User $user): DonorReport
    {
        Gate::forUser($user)->authorize('assign', $report);

        Validator::make(
            ['responsibleEmployeeId' => $employeeId],
            ['responsibleEmployeeId' => ['nullable', 'integer', Rule::exists(HrEmployee::class, 'id')->where('is_active', true)]],
            ['responsibleEmployeeId.exists' => __('donor_reports.responsible_inactive')],
        )->validate();

        $report->update(['responsible_employee_id' => $employeeId]);

        return $report;
    }
}
