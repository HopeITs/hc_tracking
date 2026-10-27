<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\ReportTemplates\ConfirmReportTemplate;
use App\Enums\DenominatorBasis;
use App\Enums\IndicatorCollector;
use App\Enums\LineSchedule;
use App\Enums\NarrativeQuestionFrequency;
use App\Enums\ProposalStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Enums\ReportTemplateStatus;
use App\Models\AnnualPlan;
use App\Models\EvidenceType;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\ReportTemplateQuestion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads a project's monthly report template for the "Report template" tab on
 * projects/show (contracts.md §12.4, T33 slice 3): its lines grouped by the
 * proposal (or annual plan) their target comes from, the proposal targets that
 * still need a library link, and the template's questions.
 *
 * No money is read. The query count is fixed whatever the number of lines:
 * the template with its lines, targets, indicators, library entries and
 * evidence types, the questions, the proposals, the annual plans and the
 * unlinked targets, each in one eager-loaded query.
 */
final class ReportTemplateReader
{
    /**
     * @return array{
     *     template: array{id: int, status: ReportTemplateStatus, pendingCount: int, confirmableCount: int, lineCount: int},
     *     groups: list<array{key: string, title: string, code: string|null, lines: list<array{id: int, name: string, code: string|null, target: string|null, status: ReportTemplateLineStatus, schedule: LineSchedule, scheduleLabel: string, scheduleMonths: list<int>, onceAfterMonths: int|null, enteredBy: IndicatorCollector, evidence: list<string>, evidenceTypeIds: list<int>, window: string, fixedDenominator: int|null, updatedAt: string, unlinked: bool, needsMonths: bool, missingFixedDenominator: bool, confirmable: bool}>, unlinkedTargets: list<array{id: int, name: string, code: string|null, target: string|null}>}>,
     *     questions: list<array{id: int, text: string, frequency: NarrativeQuestionFrequency, isActive: bool}>
     * }|null
     */
    public function tabItems(Project $project): ?array
    {
        $template = ReportTemplate::query()
            ->where('project_id', $project->id)
            ->with([
                'lines' => fn ($query) => $query->orderBy('id'),
                'lines.planIndicator:id,source_type,source_id,indicator_id,target',
                'lines.planIndicator.indicator:id,name,code,library_indicator_id',
                'lines.planIndicator.indicator.libraryIndicator:id,code,name_ar,name_en,frequency,denominator_basis',
                'lines.evidenceTypes' => fn ($query) => $query->select(['evidence_types.id', 'evidence_types.name_ar', 'evidence_types.name_en'])->ordered(),
                'questions' => fn ($query) => $query->ordered(),
            ])
            ->first();

        if ($template === null) {
            return null;
        }

        $unlinkedTargets = $this->unlinkedTargets($project, $template);
        $proposalIds = $template->lines
            ->map(fn (ReportTemplateLine $line): ?PlanIndicator => $line->planIndicator)
            ->filter(fn (?PlanIndicator $target): bool => $target?->source_type === 'proposal')
            ->map(fn (PlanIndicator $target): int => (int) $target->source_id)
            ->merge($unlinkedTargets->map(fn (PlanIndicator $target): int => (int) $target->source_id))
            ->unique()
            ->values();
        $planIds = $template->lines
            ->map(fn (ReportTemplateLine $line): ?PlanIndicator => $line->planIndicator)
            ->filter(fn (?PlanIndicator $target): bool => $target?->source_type === 'annual_plan')
            ->map(fn (PlanIndicator $target): int => (int) $target->source_id)
            ->unique()
            ->values();

        $proposals = $proposalIds->isEmpty() ? collect() : Proposal::query()
            ->whereKey($proposalIds->all())
            ->orderBy('id')
            ->get(['id', 'name', 'internal_project_number'])
            ->keyBy('id');
        $plans = $planIds->isEmpty() ? collect() : AnnualPlan::query()
            ->whereKey($planIds->all())
            ->orderBy('year')
            ->get(['id', 'year'])
            ->keyBy('id');

        /** Group headers in display order: proposals by id, then annual plans by year. */
        $headers = [];

        foreach ($proposals as $proposal) {
            $headers['proposal-'.$proposal->id] = ['title' => (string) $proposal->name, 'code' => $proposal->internal_project_number];
        }

        foreach ($plans as $plan) {
            $headers['annual_plan-'.$plan->id] = ['title' => (string) __('report_templates.tab.group_annual_plan', ['year' => $plan->year]), 'code' => null];
        }

        $linesByGroup = [];
        $confirmableCount = 0;

        foreach ($template->lines as $line) {
            $target = $line->planIndicator;
            $key = $target === null ? 'other' : $target->source_type.'-'.$target->source_id;
            $headers[$key] ??= ['title' => (string) __('report_templates.tab.group_other'), 'code' => null];
            $item = $this->lineItem($line);
            $linesByGroup[$key][] = $item;
            $confirmableCount += $item['confirmable'] ? 1 : 0;
        }

        $unlinkedByGroup = [];

        foreach ($unlinkedTargets as $target) {
            $unlinkedByGroup['proposal-'.$target->source_id][] = [
                'id' => $target->id,
                'name' => (string) $target->indicator?->name,
                'code' => $target->indicator?->code,
                'target' => $this->formatTarget($target->target),
            ];
        }

        $groups = [];

        foreach ($headers as $key => $header) {
            $groupLines = $linesByGroup[$key] ?? [];
            $groupUnlinked = $unlinkedByGroup[$key] ?? [];

            if ($groupLines !== [] || $groupUnlinked !== []) {
                $groups[] = ['key' => $key, 'title' => $header['title'], 'code' => $header['code'], 'lines' => $groupLines, 'unlinkedTargets' => $groupUnlinked];
            }
        }

        $lines = $template->lines;

        return [
            'template' => [
                'id' => $template->id,
                'status' => $template->status,
                'pendingCount' => $lines->where('status', ReportTemplateLineStatus::Pending)->count(),
                'confirmableCount' => $confirmableCount,
                'lineCount' => $lines->count(),
            ],
            'groups' => $groups,
            'questions' => array_values($template->questions->map(fn (ReportTemplateQuestion $question): array => [
                'id' => $question->id,
                'text' => $question->localizedText(),
                'frequency' => $question->frequency,
                'isActive' => $question->is_active,
            ])->all()),
        ];
    }

    /**
     * The donor-approved proposal targets of this project whose indicator has
     * no library link and no line yet: "Link it to the library first".
     *
     * @return Collection<int, PlanIndicator>
     */
    private function unlinkedTargets(Project $project, ReportTemplate $template): Collection
    {
        return PlanIndicator::query()
            ->select(['plan_indicators.id', 'plan_indicators.source_id', 'plan_indicators.indicator_id', 'plan_indicators.target'])
            ->with('indicator:id,name,code')
            ->where('plan_indicators.project_id', $project->id)
            ->where('plan_indicators.source_type', 'proposal')
            ->whereIn('plan_indicators.source_id', Proposal::query()
                ->select('id')
                ->whereIn('status', array_map(fn (ProposalStatus $status): string => $status->value, ProposalStatus::approvedByDonor())))
            ->whereIn('plan_indicators.indicator_id', fn ($query) => $query->select('id')->from('indicators')->whereNull('library_indicator_id'))
            ->whereNotIn('plan_indicators.id', $template->lines->pluck('plan_indicator_id')->all())
            ->orderBy('plan_indicators.id')
            ->get()
            ->toBase();
    }

    /**
     * @return array{id: int, name: string, code: string|null, target: string|null, status: ReportTemplateLineStatus, schedule: LineSchedule, scheduleLabel: string, scheduleMonths: list<int>, onceAfterMonths: int|null, enteredBy: IndicatorCollector, evidence: list<string>, evidenceTypeIds: list<int>, window: string, fixedDenominator: int|null, updatedAt: string, unlinked: bool, needsMonths: bool, missingFixedDenominator: bool, confirmable: bool}
     */
    private function lineItem(ReportTemplateLine $line): array
    {
        $indicator = $line->planIndicator?->indicator;
        $library = $indicator?->libraryIndicator;
        $months = array_map(intval(...), $line->schedule_months ?? []);
        $unlinked = $library === null;
        $needsMonths = ! $unlinked
            && $line->status !== ReportTemplateLineStatus::Retired
            && $line->schedule === LineSchedule::LibraryDefault
            && in_array($library->frequency, ConfirmReportTemplate::FrequenciesNeedingMonths, true);

        return [
            'id' => $line->id,
            'name' => $library?->localizedName() ?? (string) $indicator?->name,
            'code' => $library !== null ? $library->code : $indicator?->code,
            'target' => $this->formatTarget($line->planIndicator?->target),
            'status' => $line->status,
            'schedule' => $line->schedule,
            'scheduleLabel' => $this->scheduleLabel($line, $months),
            'scheduleMonths' => $months,
            'onceAfterMonths' => $line->once_after_months,
            'enteredBy' => $line->entered_by,
            'evidence' => array_values($line->evidenceTypes->map(fn (EvidenceType $type): string => $type->localizedName())->all()),
            'evidenceTypeIds' => array_values($line->evidenceTypes->map(fn (EvidenceType $type): int => $type->id)->all()),
            'window' => $this->window($line),
            'fixedDenominator' => $line->fixed_denominator,
            'updatedAt' => (string) $line->updated_at?->toDateTimeString(),
            'unlinked' => $unlinked,
            'needsMonths' => $needsMonths,
            'missingFixedDenominator' => $library?->denominator_basis === DenominatorBasis::Fixed && $line->fixed_denominator === null,
            'confirmable' => $line->status === ReportTemplateLineStatus::Pending && ! $unlinked && ! $needsMonths,
        ];
    }

    /**
     * @param  list<int>  $months
     */
    private function scheduleLabel(ReportTemplateLine $line, array $months): string
    {
        return match ($line->schedule) {
            LineSchedule::LibraryDefault => ($frequency = $line->planIndicator?->indicator?->libraryIndicator?->frequency) === null
                ? $line->schedule->label()
                : __('report_templates.tab.schedule_library', ['frequency' => $frequency->label()]),
            LineSchedule::Monthly => $line->schedule->label(),
            LineSchedule::SelectedMonths => __('report_templates.tab.schedule_months', [
                'months' => implode(__('report_templates.tab.list_separator'), array_map(fn (int $month): string => CarbonImmutable::create(2000, $month, 1)->translatedFormat(__('formats.month_short')), $months)),
            ]),
            LineSchedule::OnceAfterMonths => trans_choice('report_templates.tab.schedule_once', (int) $line->once_after_months, ['count' => (int) $line->once_after_months]),
        };
    }

    private function window(ReportTemplateLine $line): string
    {
        $from = $line->effective_from->translatedFormat(__('formats.month_year'));

        return $line->effective_to === null
            ? __('report_templates.tab.window_open', ['from' => $from])
            : __('report_templates.tab.window', ['from' => $from, 'to' => $line->effective_to->translatedFormat(__('formats.month_year'))]);
    }

    private function formatTarget(mixed $target): ?string
    {
        if ($target === null || $target === '') {
            return null;
        }

        $value = (float) $target;

        return number_format($value, floor($value) === $value ? 0 : 2);
    }
}
