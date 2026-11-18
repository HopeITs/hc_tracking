<?php

namespace Database\Seeders\Concerns;

use App\Enums\ReportTemplateStatus;
use App\Models\Project;
use App\Models\ProposalLocation;
use App\Models\ReportTemplate;
use App\Support\Reporting\PlaceBridge;
use Carbon\CarbonImmutable;

/**
 * Hands seeders the report template a project's monthly reports hang on
 * (contracts §13, Y-03): the project's existing template (its own
 * `project_id`, or one at the place of a row carrying it), else a new one at
 * the place of the first proposal row that carries the project, joined by
 * that proposal; a project with no such row gets a template of the legacy
 * kind (its own `project_id`, city and program). Run it under
 * Activity::withoutRecording, as every seeder does.
 */
trait SeedsReportTemplates
{
    /**
     * The project's template, confirmed by $confirmedBy (on $confirmedAt, or
     * now) when one is given and it is not confirmed yet. With $legacyKind a
     * missing template is always created of the legacy kind.
     */
    protected function reportTemplateFor(Project $project, ?int $confirmedBy = null, ?CarbonImmutable $confirmedAt = null, bool $legacyKind = false): ReportTemplate
    {
        $bridge = app(PlaceBridge::class);
        $existingId = $bridge->templateIdsOfProject($project->id)[0] ?? null;
        $template = $existingId === null ? null : ReportTemplate::query()->find($existingId);

        if ($template === null) {
            $row = $legacyKind ? null : ProposalLocation::query()->with('proposal')->where('project_id', $project->id)->whereNotNull('program_id')->orderBy('id')->first();
            $place = $row?->proposal === null ? null : ($bridge->placesOfProject($row->proposal, $project->id)[0] ?? null);

            $template = new ReportTemplate;
            $template->forceFill($place === null
                ? ['project_id' => $project->id, 'city_id' => $project->city_id, 'program_id' => $project->program_id]
                : ['project_id' => null, 'city_id' => $place->cityId, 'program_id' => $place->programId, 'center_id' => $place->centerId, 'city_wide' => $place->cityWide])
                ->save();

            if ($place !== null) {
                $template->proposals()->attach($row->proposal_id, ['joined_at' => now()]);
            }
        }

        if ($confirmedBy !== null && ! $template->isConfirmed()) {
            $template->forceFill([
                'status' => ReportTemplateStatus::Confirmed,
                'confirmed_at' => $confirmedAt ?? now(),
                'confirmed_by' => $confirmedBy,
            ])->save();
        }

        return $template;
    }
}
