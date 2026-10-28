<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Actions\ReportTemplates\ConfirmReportTemplate;
use App\Actions\ReportTemplates\RetireReportTemplateLine;
use App\Actions\ReportTemplates\SaveReportTemplateLine;
use App\Enums\IndicatorCollector;
use App\Enums\LineSchedule;
use App\Enums\ProjectStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Enums\ReportTemplateStatus;
use App\Models\EvidenceType;
use App\Models\Project;
use App\Models\ReportTemplate;
use App\Support\ReportTemplateReader;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The "Report template" tab on projects/show (contracts.md §12.4, T33 slice
 * 3): the lines picked for confirmation, the edit-line dialog and the retire
 * confirmation. Every write goes through the T33 actions, which authorize
 * `manage` themselves and load each line through the template (BOLA); this
 * trait only gates the dialogs and turns refusals into translated toasts or
 * field errors.
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

    /**
     * The project's template, with just enough of the project for the policy.
     */
    #[Computed]
    public function reportTemplate(): ?ReportTemplate
    {
        $template = ReportTemplate::query()
            ->where('project_id', $this->projectId)
            ->withCount(['lines as pending_lines_count' => fn ($query) => $query->where('status', ReportTemplateLineStatus::Pending)])
            ->first();
        $template?->setRelation('project', $this->project);

        return $template;
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

        return app(ReportTemplateReader::class)->tabItems($this->project);
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
        return ReportTemplate::query()->where('project_id', $this->projectId)->firstOrFail();
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

    private function forgetReportTemplate(): void
    {
        unset($this->reportTemplate, $this->reportTemplateItems, $this->canViewReportTemplate, $this->canManageReportTemplate);
    }
}
