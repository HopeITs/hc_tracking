<?php

declare(strict_types=1);

namespace App\Actions\ReportTemplates;

use App\Enums\TemplatePlacementStatus;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\ReportTemplatePlacement;
use App\Support\Reporting\PlaceBridge;
use App\Support\Reporting\ReportPlace;
use App\Support\ReportTemplatePrefiller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CreateReportTemplateForProposal
{
    public function __construct(private ReportTemplatePrefiller $prefiller, private PlaceBridge $places) {}

    public function handle(Proposal $proposal, ReportPlace $place, string $expectedStatus = 'pending'): ReportTemplate
    {
        Gate::authorize('report-templates.manage');

        return DB::transaction(function () use ($proposal, $place, $expectedStatus): ReportTemplate {
            Program::query()->whereKey($place->programId)->lockForUpdate()->firstOrFail();
            $proposal = Proposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $placement = ReportTemplatePlacement::query()->where('proposal_id', $proposal->id)->atPlace($place)->lockForUpdate()->firstOrFail();
            Gate::authorize('manage', $placement);

            if ($placement->status !== TemplatePlacementStatus::Pending || $expectedStatus !== 'pending') {
                throw ValidationException::withMessages(['placement' => __('report_templates.placement_stale')]);
            }
            if ($place->isUnset() || ! $proposal->status->isApprovedByDonor()
                || ! collect($this->places->placesOfProposal($proposal))->contains(fn (ReportPlace $candidate): bool => $place->equals($candidate))) {
                throw ValidationException::withMessages(['placement' => __('report_templates.placement_wrong_place')]);
            }

            $template = $this->prefiller->createForPlacement($proposal, $place);
            $placement->forceFill(['status' => TemplatePlacementStatus::Created, 'report_template_id' => $template->id, 'decided_at' => now(), 'decided_by' => Auth::id()])->save();

            return $template;
        });
    }
}
