<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\AiDraftRating;
use App\Enums\AiRequestStatus;
use App\Models\AiDraft;
use App\Models\AiRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The numbers behind the AI results page: how much the assistant was used,
 * how fast and reliably it answered, and what officers made of its drafts.
 * Read straight from the usage ledger (ai_requests) and ai_drafts.
 */
final class AiTrialStats
{
    /**
     * Feature keys that carry a record id ("project-12-summary") report under
     * one name.
     */
    public static function featureGroup(string $feature): string
    {
        return match (true) {
            (bool) preg_match('/^project-\d+-summary$/', $feature) => 'project-summary',
            (bool) preg_match('/^proposal-\d+-summary$/', $feature) => 'proposal-summary',
            default => $feature,
        };
    }

    /**
     * @return array{
     *     calls: int, failed: int, success_rate: int|null, typical_seconds: float|null, people: int, tokens: int,
     *     features: list<array{feature: string, calls: int, failed: int, typical_seconds: float|null, last_used: CarbonImmutable|null}>,
     *     drafts: array{made: int, rated: int, helpful: int, not_helpful: int, helpful_rate: int|null, kept_percent: int|null},
     * }
     */
    public static function since(?CarbonImmutable $since): array
    {
        $requests = AiRequest::query()
            ->when($since !== null, fn (Builder $query) => $query->where('created_at', '>=', $since))
            ->get(['id', 'user_id', 'feature', 'status', 'input_tokens', 'output_tokens', 'duration_ms', 'created_at']);

        $succeeded = $requests->where('status', AiRequestStatus::Succeeded);
        $features = [];

        foreach ($requests->groupBy(fn (AiRequest $request): string => self::featureGroup($request->feature)) as $feature => $group) {
            $features[] = [
                'feature' => (string) $feature,
                'calls' => $group->count(),
                'failed' => $group->where('status', AiRequestStatus::Failed)->count(),
                'typical_seconds' => self::typicalSeconds($group->where('status', AiRequestStatus::Succeeded)->pluck('duration_ms')->all()),
                'last_used' => $group->max('created_at'),
            ];
        }

        usort($features, fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);

        return [
            'calls' => $requests->count(),
            'failed' => $requests->count() - $succeeded->count(),
            'success_rate' => $requests->isEmpty() ? null : (int) round($succeeded->count() / $requests->count() * 100),
            'typical_seconds' => self::typicalSeconds($succeeded->pluck('duration_ms')->all()),
            'people' => $requests->pluck('user_id')->filter()->unique()->count(),
            'tokens' => (int) $requests->sum(fn (AiRequest $request): int => $request->input_tokens + $request->output_tokens),
            'features' => $features,
            'drafts' => self::drafts($since),
        ];
    }

    /**
     * @return array{made: int, rated: int, helpful: int, not_helpful: int, helpful_rate: int|null, kept_percent: int|null}
     */
    private static function drafts(?CarbonImmutable $since): array
    {
        $drafts = AiDraft::query()
            ->when($since !== null, fn (Builder $query) => $query->where('created_at', '>=', $since))
            ->with('answer:id,answer')
            ->get(['id', 'monthly_report_answer_id', 'drafted_text', 'rating']);

        $helpful = $drafts->where('rating', AiDraftRating::Helpful)->count();
        $notHelpful = $drafts->where('rating', AiDraftRating::NotHelpful)->count();
        $rated = $helpful + $notHelpful;

        return [
            'made' => $drafts->count(),
            'rated' => $rated,
            'helpful' => $helpful,
            'not_helpful' => $notHelpful,
            'helpful_rate' => $rated === 0 ? null : (int) round($helpful / $rated * 100),
            'kept_percent' => $drafts->isEmpty() ? null : (int) round($drafts->avg(fn (AiDraft $draft): int => $draft->keptPercent($draft->answer?->answer))),
        ];
    }

    /**
     * The median answer time in seconds: one slow cold start does not skew it.
     *
     * @param  array<int|string, mixed>  $durations
     */
    private static function typicalSeconds(array $durations): ?float
    {
        $values = array_values(array_map('intval', $durations));

        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);
        $median = count($values) % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];

        return round($median / 1000, 1);
    }
}
