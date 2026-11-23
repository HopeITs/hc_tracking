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
use App\Enums\ResultType;
use App\Models\ActivitySchedule;
use App\Models\AnnualPlan;
use App\Models\EvidenceType;
use App\Models\MonthlyReportAnswer;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\ReportTemplateQuestion;
use App\Models\Result;
use App\Support\Reporting\PlaceBridge;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Reads a monthly report template (D-75: the template of a place) for the
 * "Report template" tab on projects/show (contracts.md §12.4, T33 slice 3): its lines grouped by the
 * proposal (or annual plan) their target comes from, the proposal targets that
 * still need a library link, and the template's questions.
 *
 * No money is read. The query count is fixed whatever the number of lines:
 * the template with its lines, targets, indicators, library entries and
 * evidence types, the questions (with whether each is answered), the proposals, the annual plans and the
 * unlinked targets, each in one eager-loaded query.
 */
final class ReportTemplateReader
{
    public function __construct(private PlaceBridge $places) {}

    /**
     * @return array{
     *     template: array{id: int, status: ReportTemplateStatus, pendingCount: int, confirmableCount: int, lineCount: int, proposals: list<array{id: int, name: string, number: string|null}>},
     *     groups: list<array{key: string, title: string, code: string|null, lines: list<array{id: int, name: string, code: string|null, target: string|null, status: ReportTemplateLineStatus, schedule: LineSchedule, scheduleLabel: string, scheduleMonths: list<int>, onceAfterMonths: int|null, enteredBy: IndicatorCollector, evidence: list<string>, evidenceTypeIds: list<int>, window: string, fixedDenominator: int|null, updatedAt: string, unlinked: bool, needsMonths: bool, missingFixedDenominator: bool, confirmable: bool}>, unlinkedTargets: list<array{id: int, name: string, code: string|null, target: string|null}>}>,
     *     questions: list<array{id: int, text: string, textAr: string, textEn: string|null, frequency: NarrativeQuestionFrequency, isActive: bool, fromBank: bool, hasAnswers: bool, position: int, updatedAt: string}>
     * }|null
     */
    public function tabItems(ReportTemplate $template): ?array
    {
        $template = ReportTemplate::query()
            ->whereKey($template->id)
            ->with([
                'proposals:id,name,internal_project_number',
                'lines' => fn ($query) => $query->orderBy('id'),
                'lines.planIndicator:id,source_type,source_id,indicator_id,target',
                'lines.planIndicator.indicator:id,name,code,library_indicator_id',
                'lines.planIndicator.indicator.libraryIndicator:id,code,name_ar,name_en,frequency,denominator_basis',
                'lines.evidenceTypes' => fn ($query) => $query->select(['evidence_types.id', 'evidence_types.name_ar', 'evidence_types.name_en'])->ordered(),
                'questions' => fn ($query) => $query->ordered()->select('report_template_questions.*')->addSelect([
                    'has_answers' => MonthlyReportAnswer::query()
                        ->selectRaw('1')
                        ->whereColumn('monthly_report_answers.report_template_question_id', 'report_template_questions.id')
                        ->limit(1),
                ]),
            ])
            ->first();

        if ($template === null) {
            return null;
        }

        $unlinkedTargets = $this->unlinkedTargets($template);
        $proposalIds = $template->lines
            ->map(fn (ReportTemplateLine $line): ?PlanIndicator => $line->planIndicator)
            ->filter(fn (?PlanIndicator $target): bool => $target?->source_type === 'proposal')
            ->map(fn (PlanIndicator $target): int => (int) $target->source_id)
            ->toBase()
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
                'proposals' => array_values($template->proposals->sortBy('name')->map(fn (Proposal $proposal): array => ['id' => $proposal->id, 'name' => (string) $proposal->name, 'number' => $proposal->internal_project_number])->all()),
            ],
            'groups' => $groups,
            'notReportedTargets' => PlanIndicator::query()->where('source_type', 'proposal')
                ->whereIn('source_id', $template->proposals->modelKeys())
                ->whereNotIn('id', $template->lines->pluck('plan_indicator_id')->all())
                ->whereHas('indicator', fn ($query) => $query->whereNotNull('library_indicator_id'))
                ->with('indicator')->get()
                ->filter(fn (PlanIndicator $target): bool => $this->places->targetCoversPlace($target, $template->place()))
                ->map(fn (PlanIndicator $target): array => ['id' => $target->id, 'name' => $target->indicator->name])
                ->values()->all(),
            'questions' => $this->questionItems($template->questions),
        ];
    }

    /**
     * The template's questions in asking order, each with its position in the
     * whole frequency (as on the bank's page), where it came from and whether
     * a monthly report has answered it (one subquery on the eager load).
     *
     * @param  EloquentCollection<int, ReportTemplateQuestion>  $questions
     * @return list<array{id: int, text: string, textAr: string, textEn: string|null, frequency: NarrativeQuestionFrequency, isActive: bool, fromBank: bool, hasAnswers: bool, position: int, updatedAt: string}>
     */
    private function questionItems(EloquentCollection $questions): array
    {
        $positions = [];
        $items = [];

        foreach ($questions as $question) {
            $positions[$question->frequency->value] = ($positions[$question->frequency->value] ?? 0) + 1;

            $items[] = [
                'id' => $question->id,
                'text' => $question->localizedText(),
                'textAr' => $question->text_ar,
                'textEn' => $question->text_en,
                'frequency' => $question->frequency,
                'isActive' => $question->is_active,
                'fromBank' => $question->narrative_question_id !== null,
                'hasAnswers' => $question->getAttribute('has_answers') !== null,
                'position' => $positions[$question->frequency->value],
                'updatedAt' => (string) $question->updated_at?->toIso8601String(),
            ];
        }

        return $items;
    }

    /**
     * The donor-approved proposal targets of this template's proposals that
     * cover its place (or, for a template that still carries a project, that
     * project's), whose indicator has no library link and no line yet: "Link
     * it to the library first".
     *
     * @return Collection<int, PlanIndicator>
     */
    private function unlinkedTargets(ReportTemplate $template): Collection
    {
        $targets = PlanIndicator::query()
            ->select(['plan_indicators.id', 'plan_indicators.source_type', 'plan_indicators.source_id', 'plan_indicators.project_id', 'plan_indicators.indicator_id', 'plan_indicators.target'])
            ->with('indicator:id,name,code')
            ->where('plan_indicators.source_type', 'proposal')
            ->whereNotNull('plan_indicators.project_id')
            ->when($template->project_id !== null, fn ($query) => $query->where('plan_indicators.project_id', $template->project_id))
            ->whereIn('plan_indicators.source_id', Proposal::query()
                ->select('id')
                ->when($template->project_id === null, fn ($query) => $query->whereIn('id', $template->proposals->modelKeys()))
                ->whereIn('status', array_map(fn (ProposalStatus $status): string => $status->value, ProposalStatus::approvedByDonor())))
            ->whereIn('plan_indicators.indicator_id', fn ($query) => $query->select('id')->from('indicators')->whereNull('library_indicator_id'))
            ->whereNotIn('plan_indicators.id', $template->lines->pluck('plan_indicator_id')->all())
            ->orderBy('plan_indicators.id')
            ->get();

        if ($template->project_id === null) {
            $place = $template->place();
            $targets = $targets->filter(fn (PlanIndicator $target): bool => $this->places->targetCoversPlace($target, $place));
        }

        return $targets->values()->toBase();
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

    /**
     * The activity calendar of the template (Y-06, D-78): the activities of
     * the template's proposals by month, read only. A proposal's own calendar
     * applies unless the place has an override (D-49), found through the
     * bridge. Months are the union of the proposals' schedule windows; an
     * activity shows only the months inside its own proposal's window. Four
     * queries whatever the number of activities; no money.
     *
     * @return array{months: list<array{key: string, label: string}>, groups: list<array{proposal: Proposal, rows: list<array{id: int, statement: string, adjusted: bool, cells: array<string, array{weeks: list<int>|null, label: string}>}>}>, multiple: bool}
     */
    public function calendarFor(ReportTemplate $template): array
    {
        $proposals = $template->proposals()->orderBy('proposals.id')->get(['proposals.*']);

        if ($proposals->isEmpty()) {
            return ['months' => [], 'groups' => [], 'multiple' => false];
        }

        $windows = $proposals->mapWithKeys(fn (Proposal $proposal): array => [$proposal->id => ProposalFramework::scheduleWindow($proposal)]);
        $first = $windows->map(fn (array $window): CarbonImmutable => $window[0])->min();
        $last = $windows->map(fn (array $window): CarbonImmutable => $window[1])->max();

        $months = [];

        for ($month = $first; $month->lessThanOrEqualTo($last); $month = $month->addMonth()) {
            $months[] = ['key' => $month->format('Y-m'), 'label' => $month->translatedFormat('M Y')];
        }

        $activities = Result::query()
            ->where('type', ResultType::Activity->value)
            ->whereIn('proposal_id', $proposals->modelKeys())
            ->orderBy('id')
            ->get(['id', 'proposal_id', 'statement']);
        $overrideProjectId = $this->places->legacyProjectIdFor($template);

        /** @var Collection<int, Collection<int, ActivitySchedule>> $schedules */
        $schedules = $activities->isEmpty() ? collect() : ActivitySchedule::query()
            ->whereIn('result_id', $activities->modelKeys())
            ->where(fn ($query) => $query->whereNull('project_id')->when($overrideProjectId !== null, fn ($query) => $query->orWhere('project_id', $overrideProjectId)))
            ->get()
            ->groupBy('result_id');

        $groups = [];

        foreach ($proposals as $proposal) {
            [$from, $to] = $windows->get($proposal->id);
            $rows = [];

            foreach ($activities->where('proposal_id', $proposal->id) as $activity) {
                $own = $schedules->get($activity->id, collect());
                $adjusted = $own->contains(fn (ActivitySchedule $row): bool => $row->project_id !== null);
                $calendar = $adjusted ? $own->whereNotNull('project_id') : $own->whereNull('project_id');
                $cells = [];

                foreach ($calendar as $row) {
                    $month = CarbonImmutable::parse($row->month)->startOfMonth();

                    if ($month->lessThan($from) || $month->greaterThan($to)) {
                        continue;
                    }

                    /** @var list<int>|null $weeks */
                    $weeks = $row->weeks === [] ? null : $row->weeks;
                    $cells[$month->format('Y-m')] = [
                        'weeks' => $weeks,
                        'label' => $weeks === null
                            ? (string) __('report_templates.calendar.scheduled')
                            : (string) __('report_templates.calendar.scheduled_weeks', ['weeks' => self::weeksText($weeks)]),
                    ];
                }

                $rows[] = ['id' => $activity->id, 'statement' => (string) $activity->statement, 'adjusted' => $adjusted, 'cells' => $cells];
            }

            $groups[] = ['proposal' => $proposal, 'rows' => $rows];
        }

        return ['months' => $months, 'groups' => $groups, 'multiple' => $proposals->count() > 1];
    }

    /**
     * "1–2, 4": consecutive weeks collapse into a range.
     *
     * @param  list<int>  $weeks
     */
    public static function weeksText(array $weeks): string
    {
        sort($weeks);
        $runs = [];

        foreach ($weeks as $week) {
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last][1] === $week - 1) {
                $runs[$last][1] = $week;
            } else {
                $runs[] = [$week, $week];
            }
        }

        return implode(', ', array_map(fn (array $run): string => $run[0] === $run[1] ? (string) $run[0] : $run[0].'–'.$run[1], $runs));
    }
}
