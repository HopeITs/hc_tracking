<?php

declare(strict_types=1);

namespace App\Actions\ReportTemplates;

use App\Actions\Projects\ActivateProject;
use App\Enums\CollectionFrequency;
use App\Enums\LineSchedule;
use App\Enums\ProjectStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Enums\ReportTemplateStatus;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * MEAL confirms pending lines of a project's report template (contracts.md
 * §12.4, D-04b, D-39, D-41).
 *
 * One transaction, locking the template then the project: every selected
 * line must belong to the template (a foreign id rejects the batch as not
 * found), still be `pending`, have a library-linked target and, on
 * `library_default`, a library frequency with automatic months. At least one
 * line must be confirmed once done. The first confirmation moves the template
 * to `confirmed`. An `approved` project is then activated through Team A's
 * ActivateProject in the same transaction (this action never writes a
 * project or proposal status); on an `active` project the newly confirmed
 * lines record `report_template.lines_added` (proposals and month range) for
 * the owner instead. Nothing is retroactive. Notifications and
 * ProjectActivated fire after the commit.
 */
final class ConfirmReportTemplate
{
    /**
     * Library frequencies that are never due on `library_default`: MEAL must
     * choose `selected_months` or `once_after_months`.
     */
    public const FrequenciesNeedingMonths = [
        CollectionFrequency::PerSeason,
        CollectionFrequency::BaselineEndline,
        CollectionFrequency::OneTime,
    ];

    public function __construct(private ActivateProject $activateProject) {}

    /**
     * @param  list<int>  $lineIds
     *
     * @throws ModelNotFoundException<ReportTemplateLine> when a line is not on this template
     * @throws ValidationException
     */
    public function handle(ReportTemplate $template, array $lineIds): ReportTemplate
    {
        $lineIds = array_values(array_unique(array_map(intval(...), $lineIds)));

        $confirmed = DB::transaction(function () use ($template, $lineIds): ReportTemplate {
            $locked = ReportTemplate::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
            $project = Project::query()->whereKey($locked->project_id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('project', $project);

            Gate::authorize('manage', $locked);

            if (! in_array($project->status, [ProjectStatus::Approved, ProjectStatus::Active], true)) {
                throw ValidationException::withMessages(['template' => __('projects.activate_not_approved')]);
            }

            $lines = $this->lockLines($locked, $lineIds);
            $this->ensureConfirmable($lines);

            if ($lines->isEmpty() && ! $locked->lines()->where('status', ReportTemplateLineStatus::Confirmed)->exists()) {
                throw ValidationException::withMessages(['lines' => __('report_templates.confirm_at_least_one')]);
            }

            $now = now();
            $actorId = Auth::id();

            foreach ($lines as $line) {
                $line->forceFill([
                    'status' => ReportTemplateLineStatus::Confirmed,
                    'confirmed_at' => $now,
                    'confirmed_by' => $actorId,
                ])->save();
            }

            if (! $locked->isConfirmed()) {
                $locked->forceFill([
                    'status' => ReportTemplateStatus::Confirmed,
                    'confirmed_at' => $now,
                    'confirmed_by' => $actorId,
                ])->save();
            }

            if ($project->status === ProjectStatus::Approved) {
                $this->activateProject->handle($project, 'template_confirmation');
            } elseif ($lines->isNotEmpty()) {
                $this->recordLinesAdded($locked, $lines);
            }

            return $locked;
        });

        $template->refresh();

        return $confirmed;
    }

    /**
     * The selected lines of this template, locked in id order. Any id that is
     * not one of the template's lines rejects the whole batch.
     *
     * @param  list<int>  $lineIds
     * @return Collection<int, ReportTemplateLine>
     */
    private function lockLines(ReportTemplate $template, array $lineIds): Collection
    {
        if ($lineIds === []) {
            return new Collection;
        }

        $lines = $template->lines()
            ->whereKey($lineIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->with('planIndicator:id,source_type,source_id,indicator_id', 'planIndicator.indicator:id,library_indicator_id', 'planIndicator.indicator.libraryIndicator:id,frequency')
            ->get();

        if ($lines->count() !== count($lineIds)) {
            throw (new ModelNotFoundException)->setModel(ReportTemplateLine::class, array_values(array_diff($lineIds, $lines->modelKeys())));
        }

        return $lines;
    }

    /**
     * Every selected line must still be pending, library-linked and, on the
     * library schedule, automatically due. Nothing is written otherwise.
     *
     * @param  Collection<int, ReportTemplateLine>  $lines
     *
     * @throws ValidationException
     */
    private function ensureConfirmable(Collection $lines): void
    {
        foreach ($lines as $line) {
            if ($line->status !== ReportTemplateLineStatus::Pending) {
                throw ValidationException::withMessages(['template' => __('report_templates.stale')]);
            }

            $library = $line->planIndicator?->indicator?->libraryIndicator;

            if ($library === null) {
                throw ValidationException::withMessages(["lines.{$line->id}" => __('report_templates.link_library_first')]);
            }

            if ($line->schedule === LineSchedule::LibraryDefault && in_array($library->frequency, self::FrequenciesNeedingMonths, true)) {
                throw ValidationException::withMessages(["lines.{$line->id}" => __('report_templates.choose_measurement_months')]);
            }
        }
    }

    /**
     * Tell the owner of an active project which proposals the new lines come
     * from and the months they cover (`report_template.lines_added`).
     *
     * @param  Collection<int, ReportTemplateLine>  $lines
     */
    private function recordLinesAdded(ReportTemplate $template, Collection $lines): void
    {
        $proposalIds = $lines
            ->map(fn (ReportTemplateLine $line): ?int => $line->planIndicator?->source_type === 'proposal' ? $line->planIndicator->source_id : null)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $proposalNames = $proposalIds === [] ? [] : Proposal::query()
            ->whereKey($proposalIds)
            ->orderBy('id')
            ->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();

        $from = $lines->min(fn (ReportTemplateLine $line): string => $line->effective_from->format('Y-m'));
        $to = $lines->contains(fn (ReportTemplateLine $line): bool => $line->effective_to === null)
            ? $this->projectEndMonth($template) ?? $from
            : $lines->max(fn (ReportTemplateLine $line): string => $line->effective_to?->format('Y-m') ?? '');

        $template->recordActivity('report_template.lines_added', properties: [
            'project_id' => $template->project_id,
            'line_ids' => $lines->modelKeys(),
            'proposal_ids' => array_values(array_map(intval(...), $proposalIds)),
            'proposals' => array_values($proposalNames),
            'from_month' => $from,
            'to_month' => $to,
        ]);
    }

    /**
     * The project's last month, for a line with no end of its own.
     */
    private function projectEndMonth(ReportTemplate $template): ?string
    {
        $end = $template->project?->end_date;

        return $end === null ? null : CarbonImmutable::parse($end->toDateString())->format('Y-m');
    }
}
