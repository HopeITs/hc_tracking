<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MonthlyReport;
use App\Models\Proposal;
use App\Models\Risk;
use Illuminate\Support\Collection;

/**
 * The risks of a monthly report's optional "Update risks" line: every
 * logframe risk (proposal_id set, no location row) of the proposals the
 * report covers (MonthlyReportActivities::proposalsOf()), with this
 * report's saved answer when it has one. Every month lists all of them.
 */
final class MonthlyReportRisks
{
    /**
     * `proposal_name` is set only when more than one proposal feeds the report.
     *
     * @return Collection<int, array{risk: Risk, proposal_name: string|null, answer: bool|null}>
     */
    public static function for(MonthlyReport $report): Collection
    {
        $proposals = MonthlyReportActivities::proposalsOf($report);

        if ($proposals->isEmpty()) {
            return collect();
        }

        $risks = Risk::query()
            ->whereIn('proposal_id', $proposals->keys()->all())
            ->whereNull('proposal_location_id')
            ->orderBy('proposal_id')
            ->orderBy('id')
            ->get();

        $answers = $report->riskEntries()->pluck('happened', 'risk_id');
        $labelled = $proposals->count() > 1;

        return $risks->map(fn (Risk $risk): array => [
            'risk' => $risk,
            'proposal_name' => $labelled ? (string) $proposals->get($risk->proposal_id)?->name : null,
            'answer' => $answers->has($risk->id) ? (bool) $answers->get($risk->id) : null,
        ])->values();
    }
}
