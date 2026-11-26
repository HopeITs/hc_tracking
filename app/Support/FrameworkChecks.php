<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ResultType;
use App\Models\Assumption;
use App\Models\Indicator;
use App\Models\Result;
use App\Models\Risk;

/**
 * The four checks of a proposal's "fill the logical framework" task, read
 * the same way by the task bar, its "What's left?" list, the Mark as done
 * dialog and the Needs attention bar:
 *
 * - ind: every outcome and output has an indicator
 * - tgt: every indicator (activities' included) has a target
 * - act: every activity delivers an output
 * - ra:  risks and assumptions are written
 *
 * Five queries, whatever the size of the framework.
 */
final class FrameworkChecks
{
    /**
     * @return list<array{key: string, ok: bool, count: int, total: int}>
     */
    public static function for(int $proposalId): array
    {
        $results = Result::query()
            ->select(['id', 'parent_id', 'type'])
            ->proposalFramework($proposalId)
            ->withCount('indicators')
            ->get();

        $indicators = Indicator::query()->whereIn('result_id', $results->pluck('id'))->get(['id', 'target']);

        $measurable = $results->filter(fn (Result $result): bool => in_array($result->type, [ResultType::Outcome, ResultType::Output], true));
        $unmeasured = $measurable->filter(fn (Result $result): bool => $result->indicators_count === 0)->count();
        $noTarget = $indicators->whereNull('target')->count();
        $activities = $results->filter(fn (Result $result): bool => $result->type === ResultType::Activity);
        $loose = $activities->whereNull('parent_id')->count();
        $risks = Risk::query()->onLogframeOf($proposalId)->count();
        $assumptions = Assumption::query()->where('proposal_id', $proposalId)->count();

        return [
            ['key' => 'ind', 'ok' => $unmeasured === 0 && $measurable->isNotEmpty(), 'count' => $unmeasured, 'total' => $measurable->count()],
            ['key' => 'tgt', 'ok' => $noTarget === 0 && $indicators->isNotEmpty(), 'count' => $noTarget, 'total' => $indicators->count()],
            ['key' => 'act', 'ok' => $loose === 0, 'count' => $loose, 'total' => $activities->count()],
            ['key' => 'ra', 'ok' => $risks + $assumptions > 0, 'count' => $risks, 'total' => $assumptions],
        ];
    }

    /**
     * The checks that are not ready yet.
     *
     * @param  list<array{key: string, ok: bool, count: int, total: int}>  $checks
     * @return list<array{key: string, ok: bool, count: int, total: int}>
     */
    public static function open(array $checks): array
    {
        return array_values(array_filter($checks, fn (array $check): bool => ! $check['ok']));
    }
}
