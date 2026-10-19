<?php

declare(strict_types=1);

namespace App\Actions\DonorReports;

use App\Enums\DonorReportType;
use App\Enums\ReportAudience;
use App\Enums\ReportingPeriod;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\HrEmployee;
use App\Models\Proposal;
use App\Models\User;
use App\Support\DonorReportPeriods;
use App\Support\DonorReportResponsibles;
use App\Support\Slots;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Save a donor report as a frozen snapshot of the validated figures
 * (contracts §14.3). Saving the same agreement, type and period again adds
 * the next version; nothing saved changes when monthly data changes later.
 *
 * - Who: `reports.aggregate` and `view` on the proposal.
 * - The proposal needs a funding agreement (`donor_reports.donor_proposal_id`
 *   is NOT NULL); without one the builder can preview but not save.
 * - Default responsible: narrative → the Fundraising Manager slot holder,
 *   financial → the Finance Manager slot holder (§14.3); null when vacant.
 * - Errors are keyed by the builder's field names (`periodType`, `startMonth`…).
 */
final class SaveDonorReportSnapshot
{
    public function __construct(private readonly ComposeDonorReport $composer) {}

    /**
     * @param  array{period_type?: mixed, start_month?: mixed, end_month?: mixed, type?: mixed, audience?: mixed, due_on?: mixed, responsible_employee_id?: mixed, notes?: mixed, title?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function handle(Proposal $proposal, array $input, User $actor): DonorReport
    {
        Gate::forUser($actor)->authorize('reports.aggregate');

        $proposal = $this->composer->eligibleProposal($proposal->id, $actor);
        $data = $this->validated($input);
        $period = ReportingPeriod::from($data['periodType']);
        $type = DonorReportType::from($data['type']);
        $window = DonorReportPeriods::window($proposal, $period, $data['startMonth'], $data['endMonth'] ?? null);
        $agreementId = DonorProposal::query()->where('proposal_id', $proposal->id)->value('id');

        if ($agreementId === null) {
            throw ValidationException::withMessages(['proposalId' => __('donor_reports.agreement_required')]);
        }

        $snapshot = $this->composer->snapshot($proposal, $period, $window['start'], $window['end'], $actor);

        return self::insertNextVersion((int) $agreementId, [
            'title' => filled($data['title'] ?? null) ? $data['title'] : __('donor_reports.default_title', [
                'type' => $type->label(),
                'start' => $window['start']->format('Y-m'),
                'end' => $window['end']->format('Y-m'),
            ]),
            'type' => $type,
            'audience' => ReportAudience::from($data['audience']),
            'period_type' => $period,
            'period_start' => $window['start']->toDateString(),
            'period_end' => $window['end']->toDateString(),
            'due_on' => $data['dueOn'],
            'snapshot' => $snapshot,
            'responsible_employee_id' => $data['responsibleEmployeeId'] ?? Slots::holderOf(DonorReportResponsibles::slotFor($type)),
            'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null,
        ]);
    }

    /**
     * Insert the next version of a report: lock the agreement row, take
     * max(version) + 1 for (agreement, type, period) and insert. The unique
     * index is the backstop; a clash is retried once. `$guard` runs after the
     * lock, inside the transaction, and may refuse.
     *
     * @param  array<string, mixed>  $attributes  must carry type, period_start and period_end
     */
    public static function insertNextVersion(int $agreementId, array $attributes, ?Closure $guard = null): DonorReport
    {
        $insert = fn (): DonorReport => DB::transaction(function () use ($agreementId, $attributes, $guard): DonorReport {
            DonorProposal::query()->whereKey($agreementId)->lockForUpdate()->firstOrFail();

            if ($guard !== null) {
                $guard();
            }

            $type = $attributes['type'] instanceof DonorReportType ? $attributes['type']->value : $attributes['type'];
            $latest = DonorReport::query()
                ->where('donor_proposal_id', $agreementId)
                ->where('type', $type)
                ->where('period_start', $attributes['period_start'])
                ->where('period_end', $attributes['period_end'])
                ->max('version');

            return DonorReport::query()->create([
                ...$attributes,
                'donor_proposal_id' => $agreementId,
                'version' => (int) $latest + 1,
            ]);
        });

        try {
            return $insert();
        } catch (UniqueConstraintViolationException) {
            return $insert();
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{periodType: string, startMonth: string, endMonth?: ?string, type: string, audience: string, dueOn: string, responsibleEmployeeId?: ?int, notes?: ?string, title?: ?string}
     *
     * @throws ValidationException
     */
    private function validated(array $input): array
    {
        $data = [
            'periodType' => $input['period_type'] ?? null,
            'startMonth' => $input['start_month'] ?? null,
            'endMonth' => $input['end_month'] ?? null,
            'type' => $input['type'] ?? null,
            'audience' => $input['audience'] ?? null,
            'dueOn' => $input['due_on'] ?? null,
            'responsibleEmployeeId' => $input['responsible_employee_id'] ?? null,
            'notes' => $input['notes'] ?? null,
            'title' => $input['title'] ?? null,
        ];

        /** @var array{periodType: string, startMonth: string, endMonth?: ?string, type: string, audience: string, dueOn: string, responsibleEmployeeId?: ?int, notes?: ?string, title?: ?string} $validated */
        $validated = Validator::make($data, [
            'periodType' => ['required', Rule::enum(ReportingPeriod::class)],
            'startMonth' => ['required', 'date_format:Y-m'],
            'endMonth' => ['nullable', 'required_if:periodType,'.ReportingPeriod::Custom->value, 'date_format:Y-m', 'after_or_equal:startMonth'],
            'type' => ['required', Rule::enum(DonorReportType::class)],
            'audience' => ['required', Rule::enum(ReportAudience::class)],
            'dueOn' => ['required', 'date'],
            'responsibleEmployeeId' => ['nullable', 'integer', Rule::exists(HrEmployee::class, 'id')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:255'],
        ], [
            'responsibleEmployeeId.exists' => __('donor_reports.responsible_inactive'),
        ])->validate();

        return $validated;
    }
}
