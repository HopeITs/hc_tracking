<?php

declare(strict_types=1);

namespace App\Actions\ReportTemplates;

use App\Enums\IndicatorCollector;
use App\Enums\LineSchedule;
use App\Enums\ReportTemplateLineStatus;
use App\Models\EvidenceType;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * MEAL edits one line of a project's report template (contracts.md §12.4):
 * its schedule, who enters it, the required evidence types and the fixed
 * denominator. Works on `pending` and `confirmed` lines; a `retired` line is
 * stale. Status, dates and the target never change here.
 *
 * A change to a confirmed line saves immediately and applies only to monthly
 * reports still in `draft` (T17's Refresh Lines); submitted and validated
 * reports keep what they were built with. `expected_updated_at` is the line's
 * `updated_at` as the editor read it; any other value is stale.
 */
final class SaveReportTemplateLine
{
    /**
     * @param  array{schedule?: mixed, schedule_months?: mixed, once_after_months?: mixed, entered_by?: mixed, evidence_type_ids?: mixed, fixed_denominator?: mixed, expected_updated_at?: mixed}  $input
     *
     * @throws ModelNotFoundException<ReportTemplateLine> when the line is not on this template
     * @throws ValidationException
     */
    public function handle(ReportTemplate $template, int $lineId, array $input): ReportTemplateLine
    {
        return DB::transaction(function () use ($template, $lineId, $input): ReportTemplateLine {
            Gate::authorize('manage', $template);

            $line = $template->lines()
                ->whereKey($lineId)
                ->lockForUpdate()
                ->with('planIndicator:id,indicator_id', 'planIndicator.indicator:id,library_indicator_id', 'planIndicator.indicator.libraryIndicator:id,frequency')
                ->firstOrFail();

            if ($line->status === ReportTemplateLineStatus::Retired || ! $this->isCurrent($line, $input['expected_updated_at'] ?? null)) {
                throw ValidationException::withMessages(['template' => __('report_templates.stale')]);
            }

            $validated = $this->validate($line, $input);
            $schedule = LineSchedule::from($validated['schedule']);
            $frequency = $line->planIndicator?->indicator?->libraryIndicator?->frequency;

            if ($line->status === ReportTemplateLineStatus::Confirmed
                && $schedule === LineSchedule::LibraryDefault
                && in_array($frequency, ConfirmReportTemplate::FrequenciesNeedingMonths, true)) {
                throw ValidationException::withMessages(['schedule' => __('report_templates.choose_measurement_months')]);
            }

            $months = array_values(array_unique(array_map(intval(...), $validated['schedule_months'])));
            sort($months);

            $line->fill([
                'schedule' => $schedule,
                'schedule_months' => $schedule === LineSchedule::SelectedMonths ? $months : null,
                'once_after_months' => $schedule === LineSchedule::OnceAfterMonths ? (int) $validated['once_after_months'] : null,
                'entered_by' => IndicatorCollector::from($validated['entered_by']),
                'fixed_denominator' => $validated['fixed_denominator'] === null ? null : (int) $validated['fixed_denominator'],
            ])->save();

            $line->evidenceTypes()->sync(array_values(array_unique(array_map(intval(...), $validated['evidence_type_ids']))));

            return $line;
        });
    }

    /**
     * Whether the line is still as the editor read it.
     */
    private function isCurrent(ReportTemplateLine $line, mixed $expectedUpdatedAt): bool
    {
        if (! is_string($expectedUpdatedAt) || $line->updated_at === null) {
            return false;
        }

        try {
            return CarbonImmutable::parse($expectedUpdatedAt)->equalTo($line->updated_at);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Evidence types must be active, except ones the line already requires.
     *
     * @param  array<string, mixed>  $input
     * @return array{schedule: string, schedule_months: list<int|string>, once_after_months: int|string|null, entered_by: string, evidence_type_ids: list<int|string>, fixed_denominator: int|string|null}
     */
    private function validate(ReportTemplateLine $line, array $input): array
    {
        $keptIds = $line->evidenceTypes()->pluck('evidence_types.id')->map(fn (int|string $id): int => (int) $id)->all();
        $selectable = fn ($query) => $query->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $keptIds));

        $schedule = $input['schedule'] ?? null;

        /** @var array{schedule: string, schedule_months: list<int|string>, once_after_months: int|string|null, entered_by: string, evidence_type_ids: list<int|string>, fixed_denominator: int|string|null} */
        return Validator::make(
            [
                'schedule' => $schedule,
                'schedule_months' => $input['schedule_months'] ?? [],
                'once_after_months' => $input['once_after_months'] ?? null,
                'entered_by' => $input['entered_by'] ?? null,
                'evidence_type_ids' => $input['evidence_type_ids'] ?? [],
                'fixed_denominator' => $input['fixed_denominator'] ?? null,
            ],
            [
                'schedule' => ['required', Rule::enum(LineSchedule::class)],
                'schedule_months' => ['array', Rule::requiredIf($schedule === LineSchedule::SelectedMonths->value)],
                'schedule_months.*' => ['integer', 'between:1,12'],
                'once_after_months' => [Rule::requiredIf($schedule === LineSchedule::OnceAfterMonths->value), 'nullable', 'integer', 'between:1,120'],
                'entered_by' => ['required', Rule::enum(IndicatorCollector::class)],
                'evidence_type_ids' => ['array'],
                'evidence_type_ids.*' => ['integer', 'distinct', Rule::exists(EvidenceType::class, 'id')->where($selectable)],
                'fixed_denominator' => ['nullable', 'integer', 'min:1'],
            ],
            [
                'schedule.*' => __('report_templates.schedule_invalid'),
                'schedule_months.*' => __('report_templates.schedule_months_invalid'),
                'schedule_months.*.*' => __('report_templates.schedule_months_invalid'),
                'once_after_months.*' => __('report_templates.once_after_months_invalid'),
                'entered_by.*' => __('report_templates.entered_by_invalid'),
                'evidence_type_ids.*' => __('report_templates.evidence_types_invalid'),
                'evidence_type_ids.*.*' => __('report_templates.evidence_types_invalid'),
                'fixed_denominator.*' => __('report_templates.fixed_denominator_invalid'),
            ],
        )->validate();
    }
}
