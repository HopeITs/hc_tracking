<?php

declare(strict_types=1);

namespace App\Actions\ReportTemplates;

use App\Enums\ReportTemplateLineStatus;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * MEAL stops a confirmed line of a project's report template (contracts.md
 * §12.4): the line becomes `retired` and `effective_to` the current month,
 * so it is still due this month and stops from the next. Only a `confirmed`
 * line can be retired; the row is never deleted, so past reports keep it.
 */
final class RetireReportTemplateLine
{
    /**
     * @throws ModelNotFoundException<ReportTemplateLine> when the line is not on this template
     * @throws ValidationException when the line is not confirmed (any more)
     */
    public function handle(ReportTemplate $template, int $lineId): ReportTemplateLine
    {
        return DB::transaction(function () use ($template, $lineId): ReportTemplateLine {
            Gate::authorize('manage', $template);

            $line = $template->lines()->whereKey($lineId)->lockForUpdate()->firstOrFail();

            if ($line->status !== ReportTemplateLineStatus::Confirmed) {
                throw ValidationException::withMessages(['template' => __('report_templates.stale')]);
            }

            $currentMonth = today()->startOfMonth()->toImmutable();
            $effectiveTo = $line->effective_to !== null && $line->effective_to->lessThan($currentMonth) ? $line->effective_to : $currentMonth;

            $line->forceFill([
                'status' => ReportTemplateLineStatus::Retired,
                'effective_to' => $effectiveTo->toDateString(),
            ])->save();

            return $line;
        });
    }
}
