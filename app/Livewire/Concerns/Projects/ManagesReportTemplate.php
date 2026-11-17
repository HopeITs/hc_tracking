<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Actions\ReportTemplates\ConfirmReportTemplate;
use App\Actions\ReportTemplates\DeleteReportTemplateQuestion;
use App\Actions\ReportTemplates\MoveReportTemplateQuestion;
use App\Actions\ReportTemplates\RetireReportTemplateLine;
use App\Actions\ReportTemplates\SaveReportTemplateLine;
use App\Actions\ReportTemplates\SaveReportTemplateQuestion;
use App\Actions\ReportTemplates\SetReportTemplateQuestionActive;
use App\Enums\IndicatorCollector;
use App\Enums\LineSchedule;
use App\Enums\NarrativeQuestionFrequency;
use App\Enums\ProjectStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Enums\ReportTemplateStatus;
use App\Models\ActivitySchedule;
use App\Models\EvidenceType;
use App\Models\MonthlyReportAnswer;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateQuestion;
use App\Support\Reporting\PlaceBridge;
use App\Support\ReportTemplateReader;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The "Report template" tab on projects/show (contracts.md §12.4, T33 slice
 * 3): the lines picked for confirmation, the edit-line dialog and the retire
 * confirmation, and the narrative questions panel (Y-07 slice 2: add, edit,
 * reorder, switch off / on, delete). Every write goes through the T33 and
 * Y-07 actions, which authorize `manage` themselves and load each line or
 * question through the template (BOLA); this trait only gates the dialogs and
 * turns refusals into translated toasts or field errors. The template is
 * always the one the tab shows, resolved server-side from the project.
 *
 * Used by projects/show only. The page provides projectId and the #[Computed]
 * project().
 *
 * @property int $projectId
 * @property-read Project $project  #[Computed] project()
 * @property-read ReportTemplate|null $reportTemplate  #[Computed] reportTemplate()
 * @property-read bool $canViewReportTemplate  #[Computed] canViewReportTemplate()
 * @property-read bool $canManageReportTemplate  #[Computed] canManageReportTemplate()
 * @property-read array<string, mixed>|null $reportTemplateItems  #[Computed] reportTemplateItems()
 * @property-read Collection<int, EvidenceType> $templateEvidenceTypeOptions  #[Computed] templateEvidenceTypeOptions()
 */
trait ManagesReportTemplate
{
    /** @var list<int|string> */
    public array $selectedTemplateLineIds = [];

    public bool $showTemplateConfirmModal = false;

    public bool $showTemplateLineModal = false;

    public bool $showRetireTemplateLineModal = false;

    #[Locked]
    public ?int $editingTemplateLineId = null;

    #[Locked]
    public string $templateLineUpdatedAt = '';

    #[Locked]
    public ?int $retiringTemplateLineId = null;

    public string $templateLineSchedule = '';

    /** @var list<int|string> */
    public array $templateLineMonths = [];

    public string $templateLineOnceAfter = '';

    public string $templateLineEnteredBy = '';

    /** @var list<int|string> */
    public array $templateLineEvidenceTypeIds = [];

    public string $templateLineFixedDenominator = '';

    public bool $showTemplateQuestionModal = false;

    #[Locked]
    public ?int $editingTemplateQuestionId = null;

    #[Locked]
    public string $templateQuestionUpdatedAt = '';

    public string $templateQuestionTextAr = '';

    public string $templateQuestionTextEn = '';

    public string $templateQuestionFrequency = '';

    public bool $showSwitchOffTemplateQuestionModal = false;

    #[Locked]
    public ?int $switchingOffTemplateQuestionId = null;

    #[Locked]
    public string $switchingOffTemplateQuestionName = '';

    public bool $showDeleteTemplateQuestionModal = false;

    #[Locked]
    public ?int $deletingTemplateQuestionId = null;

    #[Locked]
    public string $deletingTemplateQuestionName = '';

    /** An answered question cannot be deleted: its delete dialog offers to switch it off instead. */
    #[Locked]
    public bool $deletingTemplateQuestionHasAnswers = false;

    /** Read out by the panel's polite live region after a move. */
    public string $templateQuestionAnnouncement = '';

    /**
     * The project's template, with just enough of the project for the policy.
     */
    #[Computed]
    public function reportTemplate(): ?ReportTemplate
    {
        return ReportTemplate::query()
            ->whereKey(app(PlaceBridge::class)->templateIdsOfProject($this->projectId))
            ->withCount(['lines as pending_lines_count' => fn ($query) => $query->where('status', ReportTemplateLineStatus::Pending)])
            ->orderBy('id')
            ->first();
    }

    #[Computed]
    public function canViewReportTemplate(): bool
    {
        return $this->reportTemplate !== null && (Auth::user()?->can('view', $this->reportTemplate) ?? false);
    }

    #[Computed]
    public function canManageReportTemplate(): bool
    {
        return $this->reportTemplate !== null && (Auth::user()?->can('manage', $this->reportTemplate) ?? false);
    }

    /**
     * The tab's grouped lines and questions; nothing for a viewer the policy
     * refuses, so a forced `?tab=template` reads nothing.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function reportTemplateItems(): ?array
    {
        if (! $this->canViewReportTemplate) {
            return null;
        }

        return app(ReportTemplateReader::class)->tabItems($this->reportTemplate);
    }

    /**
     * The template's activity calendar (Y-06): read only, with a link to each
     * proposal's own calendar tab for those who may edit it there. The link
     * also needs the right to open the proposal (MEAL may edit a calendar
     * without holding `proposals.view`).
     *
     * @return array{months: list<array{key: string, label: string}>, groups: list<array{proposal: Proposal, editUrl: string|null, rows: list<array<string, mixed>>}>, multiple: bool}|null
     */
    #[Computed]
    public function templateCalendar(): ?array
    {
        if (! $this->canViewReportTemplate) {
            return null;
        }

        $calendar = app(ReportTemplateReader::class)->calendarFor($this->reportTemplate);
        $user = Auth::user();

        $calendar['groups'] = array_map(function (array $group) use ($user): array {
            $mayEdit = $user !== null
                && $user->can('update', [ActivitySchedule::class, $group['proposal']])
                && $user->can('view', $group['proposal']);

            return [...$group, 'editUrl' => $mayEdit ? route('proposals.show', ['proposal' => $group['proposal'], 'ptab' => 'logframe']) : null];
        }, $calendar['groups']);

        return $calendar;
    }

    /**
     * Evidence types for the edit dialog: the active ones plus any the edited
     * line already requires.
     *
     * @return Collection<int, EvidenceType>
     */
    #[Computed]
    public function templateEvidenceTypeOptions(): Collection
    {
        $kept = array_map(intval(...), $this->templateLineEvidenceTypeIds);

        return EvidenceType::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $kept))
            ->ordered()
            ->get(['id', 'name_ar', 'name_en']);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function templateScheduleOptions(): array
    {
        return array_map(fn (LineSchedule $schedule): array => ['value' => $schedule->value, 'label' => $schedule->label()], LineSchedule::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function templateCollectorOptions(): array
    {
        return array_map(fn (IndicatorCollector $collector): array => ['value' => $collector->value, 'label' => $collector->label()], IndicatorCollector::cases());
    }

    /**
     * Pick every pending line that can be confirmed.
     */
    public function selectAllPendingTemplateLines(): void
    {
        $this->authorizeTemplate('manage');

        $this->selectedTemplateLineIds = $this->confirmableLineIds();
    }

    public function askConfirmReportTemplate(): void
    {
        $this->authorizeTemplate('manage');

        $this->showTemplateConfirmModal = true;
    }

    /**
     * Confirm the selected lines; on an approved project this activates it.
     */
    public function confirmReportTemplate(): void
    {
        $template = $this->authorizeTemplate('manage');
        $wasApproved = $this->project->status === ProjectStatus::Approved;

        try {
            app(ConfirmReportTemplate::class)->handle($template, array_map(intval(...), $this->selectedTemplateLineIds));
        } catch (ValidationException $exception) {
            $this->showTemplateConfirmModal = false;
            $this->selectedTemplateLineIds = [];
            $this->forgetReportTemplate();
            Flux::toast(variant: 'danger', text: $exception->validator->errors()->first());

            return;
        } catch (ModelNotFoundException) {
            $this->templateLineMissing();

            return;
        }

        $this->showTemplateConfirmModal = false;
        $this->selectedTemplateLineIds = [];
        $this->forgetReportTemplate();
        unset($this->project);

        Flux::toast(variant: 'success', text: $wasApproved ? __('report_templates.tab.activated') : __('report_templates.tab.confirmed'));
    }

    #[Computed]
    public function awaitingMonthlyReports(): bool
    {
        $project = $this->project;

        return $project->status === ProjectStatus::Active
            && $this->reportTemplate?->status === ReportTemplateStatus::Confirmed
            && $project->start_date !== null
            && $project->end_date !== null
            && ! $project->monthlyReports()->exists();
    }

    public function openTemplateLineModal(int $lineId): void
    {
        $template = $this->authorizeTemplate('manage');

        try {
            $line = $template->lines()->with('evidenceTypes:evidence_types.id')->findOrFail($lineId);
        } catch (ModelNotFoundException) {
            $this->templateLineMissing();

            return;
        }

        $this->resetValidation();
        $this->editingTemplateLineId = $line->id;
        $this->templateLineUpdatedAt = (string) $line->updated_at?->toDateTimeString();
        $this->templateLineSchedule = $line->schedule->value;
        $this->templateLineMonths = array_map(fn (mixed $month): string => (string) $month, $line->schedule_months ?? []);
        $this->templateLineOnceAfter = $line->once_after_months === null ? '' : (string) $line->once_after_months;
        $this->templateLineEnteredBy = $line->entered_by->value;
        $this->templateLineEvidenceTypeIds = array_values($line->evidenceTypes->map(fn (EvidenceType $type): string => (string) $type->id)->all());
        $this->templateLineFixedDenominator = $line->fixed_denominator === null ? '' : (string) $line->fixed_denominator;
        unset($this->templateEvidenceTypeOptions);
        $this->showTemplateLineModal = true;
    }

    public function saveTemplateLine(): void
    {
        $template = $this->authorizeTemplate('manage');

        if ($this->editingTemplateLineId === null) {
            return;
        }

        try {
            app(SaveReportTemplateLine::class)->handle($template, $this->editingTemplateLineId, [
                'schedule' => $this->templateLineSchedule,
                'schedule_months' => array_values(array_filter($this->templateLineMonths, fn (mixed $month): bool => $month !== '')),
                'once_after_months' => $this->templateLineOnceAfter === '' ? null : $this->templateLineOnceAfter,
                'entered_by' => $this->templateLineEnteredBy,
                'evidence_type_ids' => $this->templateLineEvidenceTypeIds,
                'fixed_denominator' => $this->templateLineFixedDenominator === '' ? null : $this->templateLineFixedDenominator,
                'expected_updated_at' => $this->templateLineUpdatedAt,
            ]);
        } catch (ValidationException $exception) {
            $this->showLineErrors($exception);

            return;
        } catch (ModelNotFoundException) {
            $this->closeTemplateLineModal();
            $this->templateLineMissing();

            return;
        }

        $this->closeTemplateLineModal();
        $this->forgetReportTemplate();

        Flux::toast(variant: 'success', text: __('report_templates.tab.saved'));
    }

    public function closeTemplateLineModal(): void
    {
        $this->showTemplateLineModal = false;
        $this->editingTemplateLineId = null;
        $this->templateLineUpdatedAt = '';
        $this->templateLineSchedule = '';
        $this->templateLineMonths = [];
        $this->templateLineOnceAfter = '';
        $this->templateLineEnteredBy = '';
        $this->templateLineEvidenceTypeIds = [];
        $this->templateLineFixedDenominator = '';
        $this->resetValidation();
    }

    public function askRetireTemplateLine(int $lineId): void
    {
        $template = $this->authorizeTemplate('manage');

        $line = $template->lines()->whereKey($lineId)->first(['id']);

        if ($line === null) {
            $this->templateLineMissing();

            return;
        }

        $this->retiringTemplateLineId = $line->id;
        $this->showRetireTemplateLineModal = true;
    }

    public function retireTemplateLine(): void
    {
        $template = $this->authorizeTemplate('manage');

        if ($this->retiringTemplateLineId === null) {
            return;
        }

        try {
            app(RetireReportTemplateLine::class)->handle($template, $this->retiringTemplateLineId);
        } catch (ValidationException $exception) {
            $this->showRetireTemplateLineModal = false;
            $this->retiringTemplateLineId = null;
            $this->forgetReportTemplate();
            Flux::toast(variant: 'danger', text: $exception->validator->errors()->first());

            return;
        } catch (ModelNotFoundException) {
            $this->showRetireTemplateLineModal = false;
            $this->retiringTemplateLineId = null;
            $this->templateLineMissing();

            return;
        }

        $this->showRetireTemplateLineModal = false;
        $this->retiringTemplateLineId = null;
        $this->forgetReportTemplate();

        Flux::toast(variant: 'success', text: __('report_templates.tab.retired'));
    }

    public function openAddTemplateQuestion(): void
    {
        $this->authorizeTemplate('manage');

        $this->resetTemplateQuestionForm();
        $this->templateQuestionFrequency = NarrativeQuestionFrequency::Monthly->value;
        $this->showTemplateQuestionModal = true;
    }

    public function openEditTemplateQuestion(int $questionId): void
    {
        $question = $this->findTemplateQuestion($questionId);

        if ($question === null) {
            return;
        }

        $this->resetTemplateQuestionForm();
        $this->editingTemplateQuestionId = $question->id;
        $this->templateQuestionUpdatedAt = (string) $question->updated_at?->toIso8601String();
        $this->templateQuestionTextAr = $question->text_ar;
        $this->templateQuestionTextEn = (string) $question->text_en;
        $this->templateQuestionFrequency = $question->frequency->value;
        $this->showTemplateQuestionModal = true;
    }

    public function saveTemplateQuestion(): void
    {
        $template = $this->authorizeTemplate('manage');
        $isCreate = $this->editingTemplateQuestionId === null;

        try {
            app(SaveReportTemplateQuestion::class)->handle($template, $this->editingTemplateQuestionId, [
                'text_ar' => $this->templateQuestionTextAr,
                'text_en' => $this->templateQuestionTextEn,
                'frequency' => $this->templateQuestionFrequency,
            ], $isCreate ? null : $this->templateQuestionUpdatedAt);
        } catch (ValidationException $exception) {
            $this->showTemplateQuestionErrors($exception);

            return;
        } catch (ModelNotFoundException) {
            $this->closeTemplateQuestionModal();
            $this->templateQuestionMissing();

            return;
        }

        $this->closeTemplateQuestionModal();
        $this->forgetReportTemplate();

        Flux::toast(variant: 'success', text: __($isCreate ? 'narrative_questions.created' : 'narrative_questions.updated'));
    }

    public function closeTemplateQuestionModal(): void
    {
        $this->showTemplateQuestionModal = false;
        $this->resetTemplateQuestionForm();
    }

    /**
     * Move a question one place up or down in its whole frequency; the panel
     * puts keyboard focus back on the moved row's arrow.
     */
    public function moveTemplateQuestion(int $questionId, string $direction): void
    {
        $template = $this->authorizeTemplate('manage');
        $direction = $direction === 'up' ? 'up' : 'down';

        try {
            app(MoveReportTemplateQuestion::class)->handle($template, $questionId, $direction);
        } catch (ValidationException $exception) {
            $this->forgetReportTemplate();
            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        } catch (ModelNotFoundException) {
            $this->templateQuestionMissing();

            return;
        }

        $this->forgetReportTemplate();

        $questions = collect($this->reportTemplateItems['questions'] ?? []);
        $moved = $questions->firstWhere('id', $questionId);

        $this->templateQuestionAnnouncement = $moved === null ? '' : __('narrative_questions.moved_to', [
            'text' => Str::limit($moved['text'], 80),
            'position' => $moved['position'],
            'total' => $questions->where('frequency', $moved['frequency'])->count(),
            'frequency' => $moved['frequency']->label(),
        ]);

        // Flux disables the clicked arrow while the request runs, which drops
        // keyboard focus; the panel puts it back on the moved row.
        $this->dispatch('template-question-moved', questionId: $questionId, direction: $direction);
    }

    public function askSwitchOffTemplateQuestion(int $questionId): void
    {
        $question = $this->findTemplateQuestion($questionId);

        if ($question === null) {
            return;
        }

        $this->switchingOffTemplateQuestionId = $question->id;
        $this->switchingOffTemplateQuestionName = Str::limit($question->localizedText(), 80);
        $this->showSwitchOffTemplateQuestionModal = true;
    }

    public function switchOffTemplateQuestion(): void
    {
        if ($this->switchingOffTemplateQuestionId === null) {
            return;
        }

        $questionId = $this->switchingOffTemplateQuestionId;
        $this->cancelSwitchOffTemplateQuestion();

        $this->runTemplateQuestionAction(fn (ReportTemplate $template) => app(SetReportTemplateQuestionActive::class)->handle($template, $questionId, false), 'narrative_questions.deactivated');
    }

    public function cancelSwitchOffTemplateQuestion(): void
    {
        $this->showSwitchOffTemplateQuestionModal = false;
        $this->switchingOffTemplateQuestionId = null;
        $this->switchingOffTemplateQuestionName = '';
    }

    /**
     * Switching a question back on loses nothing, so it needs no confirmation.
     */
    public function switchOnTemplateQuestion(int $questionId): void
    {
        $this->runTemplateQuestionAction(fn (ReportTemplate $template) => app(SetReportTemplateQuestionActive::class)->handle($template, $questionId, true), 'narrative_questions.reactivated');
    }

    public function askDeleteTemplateQuestion(int $questionId): void
    {
        $question = $this->findTemplateQuestion($questionId);

        if ($question === null) {
            return;
        }

        $this->deletingTemplateQuestionId = $question->id;
        $this->deletingTemplateQuestionName = Str::limit($question->localizedText(), 80);
        $this->deletingTemplateQuestionHasAnswers = $question->getAttribute('has_answers') !== null;
        $this->showDeleteTemplateQuestionModal = true;
    }

    public function deleteTemplateQuestion(): void
    {
        if ($this->deletingTemplateQuestionId === null) {
            return;
        }

        $questionId = $this->deletingTemplateQuestionId;
        $this->cancelDeleteTemplateQuestion();

        $this->runTemplateQuestionAction(fn (ReportTemplate $template) => app(DeleteReportTemplateQuestion::class)->handle($template, $questionId), 'narrative_questions.deleted');
    }

    /**
     * The delete dialog of an answered question: switch it off, as the message explains.
     */
    public function switchOffInsteadOfDeleteTemplateQuestion(): void
    {
        if ($this->deletingTemplateQuestionId === null) {
            return;
        }

        $questionId = $this->deletingTemplateQuestionId;
        $this->cancelDeleteTemplateQuestion();

        $this->runTemplateQuestionAction(fn (ReportTemplate $template) => app(SetReportTemplateQuestionActive::class)->handle($template, $questionId, false), 'narrative_questions.deactivated');
    }

    public function cancelDeleteTemplateQuestion(): void
    {
        $this->showDeleteTemplateQuestionModal = false;
        $this->deletingTemplateQuestionId = null;
        $this->deletingTemplateQuestionName = '';
        $this->deletingTemplateQuestionHasAnswers = false;
    }

    /**
     * The confirm dialog's consequence: activation for an approved project,
     * new lines from next report for an active one.
     */
    public function templateConfirmMessage(): string
    {
        return $this->project->status === ProjectStatus::Approved
            ? __('report_templates.tab.confirm_body_approved')
            : __('report_templates.tab.confirm_body_active');
    }

    public function templateIsConfirmed(): bool
    {
        return $this->reportTemplate?->status === ReportTemplateStatus::Confirmed;
    }

    /**
     * The template, freshly loaded and authorized for the ability.
     */
    private function authorizeTemplate(string $ability): ReportTemplate
    {
        $template = $this->managedTemplate();

        Gate::authorize($ability, $template);

        return $template;
    }

    private function managedTemplate(): ReportTemplate
    {
        return ReportTemplate::query()->whereKey(app(PlaceBridge::class)->templateIdsOfProject($this->projectId))->orderBy('id')->firstOrFail();
    }

    /**
     * @return list<int>
     */
    private function confirmableLineIds(): array
    {
        $ids = [];

        foreach ($this->reportTemplateItems['groups'] ?? [] as $group) {
            foreach ($group['lines'] as $line) {
                if ($line['confirmable']) {
                    $ids[] = $line['id'];
                }
            }
        }

        return $ids;
    }

    /**
     * Put the action's messages on the dialog's fields; a stale line closes
     * the dialog and reloads the tab.
     */
    private function showLineErrors(ValidationException $exception): void
    {
        $fields = [
            'schedule' => 'templateLineSchedule',
            'schedule_months' => 'templateLineMonths',
            'once_after_months' => 'templateLineOnceAfter',
            'entered_by' => 'templateLineEnteredBy',
            'evidence_type_ids' => 'templateLineEvidenceTypeIds',
            'fixed_denominator' => 'templateLineFixedDenominator',
        ];

        foreach ($exception->errors() as $key => $messages) {
            $field = $fields[explode('.', $key)[0]] ?? null;

            if ($field === null) {
                $this->closeTemplateLineModal();
                $this->forgetReportTemplate();
                Flux::toast(variant: 'danger', text: (string) ($messages[0] ?? __('report_templates.stale')));

                return;
            }

            $this->addError($field, (string) ($messages[0] ?? ''));
        }
    }

    private function templateLineMissing(): void
    {
        $this->showTemplateConfirmModal = false;
        $this->showTemplateLineModal = false;
        $this->selectedTemplateLineIds = [];
        $this->forgetReportTemplate();

        Flux::toast(variant: 'danger', text: __('report_templates.tab.line_missing'));
    }

    /**
     * The question on the tab's template, with whether a monthly report has
     * answered it; null (and a toast) when it is not on this template.
     */
    private function findTemplateQuestion(int $questionId): ?ReportTemplateQuestion
    {
        $template = $this->authorizeTemplate('manage');

        $question = $template->questions()
            ->whereKey($questionId)
            ->select('report_template_questions.*')
            ->addSelect(['has_answers' => MonthlyReportAnswer::query()
                ->selectRaw('1')
                ->whereColumn('monthly_report_answers.report_template_question_id', 'report_template_questions.id')
                ->limit(1)])
            ->first();

        if ($question === null) {
            $this->templateQuestionMissing();
        }

        return $question;
    }

    /**
     * Run a switch off, switch on or delete on the tab's template; a refusal
     * (answered, stale) becomes a toast, since there is no form.
     *
     * @param  callable(ReportTemplate): mixed  $action
     */
    private function runTemplateQuestionAction(callable $action, string $successKey): void
    {
        $template = $this->authorizeTemplate('manage');

        try {
            $action($template);
        } catch (ValidationException $exception) {
            $this->forgetReportTemplate();
            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        } catch (ModelNotFoundException) {
            $this->templateQuestionMissing();

            return;
        }

        $this->forgetReportTemplate();

        Flux::toast(variant: 'success', text: __($successKey));
    }

    /**
     * Put the action's messages on the dialog's fields; a stale question
     * closes the dialog and reloads the panel.
     */
    private function showTemplateQuestionErrors(ValidationException $exception): void
    {
        $fields = [
            'text_ar' => 'templateQuestionTextAr',
            'text_en' => 'templateQuestionTextEn',
            'frequency' => 'templateQuestionFrequency',
        ];

        foreach ($exception->errors() as $key => $messages) {
            $field = $fields[$key] ?? null;

            if ($field === null) {
                $this->closeTemplateQuestionModal();
                $this->forgetReportTemplate();
                Flux::toast(variant: 'danger', text: (string) ($messages[0] ?? __('report_templates.stale')));

                return;
            }

            $this->addError($field, (string) ($messages[0] ?? ''));
        }
    }

    private function resetTemplateQuestionForm(): void
    {
        $this->resetValidation();
        $this->editingTemplateQuestionId = null;
        $this->templateQuestionUpdatedAt = '';
        $this->templateQuestionTextAr = '';
        $this->templateQuestionTextEn = '';
        $this->templateQuestionFrequency = '';
    }

    private function templateQuestionMissing(): void
    {
        $this->showTemplateQuestionModal = false;
        $this->showSwitchOffTemplateQuestionModal = false;
        $this->showDeleteTemplateQuestionModal = false;
        $this->forgetReportTemplate();

        Flux::toast(variant: 'danger', text: __('report_templates.tab.question_missing'));
    }

    private function forgetReportTemplate(): void
    {
        unset($this->reportTemplate, $this->reportTemplateItems, $this->templateCalendar, $this->canViewReportTemplate, $this->canManageReportTemplate);
    }
}
