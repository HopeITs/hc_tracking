<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Jobs\RunAiPrompt;
use App\Models\User;
use App\Support\Ai\Agents\RecordSummarizer;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * "Summarize with AI" on a record page: the page hands over the facts it
 * already shows this viewer, the summarizer turns them into a short brief,
 * and the brief is kept until those facts change (same facts, same brief,
 * no second call). Rendered by x-ai.brief.
 *
 * The page provides aiSummaryKind() and aiSummaryFacts().
 *
 * @property-read array{verdict: string, headline: string, points: list<string>, next_step: string, made_at: string}|null $aiSummary
 */
trait SummarizesWithAi
{
    /**
     * How long a brief is kept while its facts stay the same.
     */
    public const int AI_SUMMARY_TTL_DAYS = 30;

    /**
     * The queued summary this page waits for, if any.
     */
    #[Locked]
    public ?string $aiSummaryKey = null;

    /**
     * Short name of the record type, part of the cache key: 'project', 'proposal'.
     */
    abstract protected function aiSummaryKind(): string;

    /**
     * The plain-text facts the page shows this viewer about the record.
     */
    abstract protected function aiSummaryFacts(): string;

    public function canSummarizeWithAi(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('ai.summarize');
    }

    /**
     * The brief for the facts as they stand now, or null when there is none yet
     * (never made, or the record changed since).
     *
     * @return array{verdict: string, headline: string, points: list<string>, next_step: string, made_at: string}|null
     */
    #[Computed]
    public function aiSummary(): ?array
    {
        if (! $this->canSummarizeWithAi()) {
            return null;
        }

        $cached = Cache::get($this->aiSummaryCacheKey());

        return is_array($cached) ? self::shapedAiSummary($cached) : null;
    }

    public function summarizeWithAi(): void
    {
        if (! $this->canSummarizeWithAi()) {
            Flux::toast(variant: 'danger', text: __('ai.not_allowed'));

            return;
        }

        if ($this->aiSummaryKey !== null || $this->aiSummary !== null) {
            return;
        }

        /** @var User $user */
        $user = Auth::user();
        $summarizer = new RecordSummarizer(app()->getLocale() === 'ar' ? 'ar' : 'en');

        $this->aiSummaryKey = RunAiPrompt::start(
            $summarizer,
            $summarizer->promptFor($this->aiSummaryFacts()),
            $this->aiSummaryKind().'-summary',
            $user,
            'fast',
        );
    }

    /**
     * Pick up the brief once it is ready; safe to call while still pending.
     */
    public function collectAiSummary(): void
    {
        if ($this->aiSummaryKey === null) {
            return;
        }

        /** @var User $user */
        $user = Auth::user();
        $result = RunAiPrompt::resultFor($this->aiSummaryKey, $user);

        if ($result !== null && $result['status'] === 'pending') {
            return;
        }

        $this->aiSummaryKey = null;
        $brief = $result === null || $result['status'] === 'failed' ? null : self::parsedAiSummary((string) $result['text']);

        if ($brief === null) {
            Flux::toast(variant: 'danger', text: __('ai.summary_failed'));

            return;
        }

        Cache::put($this->aiSummaryCacheKey(), $brief, now()->addDays(self::AI_SUMMARY_TTL_DAYS));
        unset($this->aiSummary);
    }

    /**
     * Same record, same viewer language, same facts: same brief. Any change
     * the viewer could see on the page changes the facts and so the key.
     */
    protected function aiSummaryCacheKey(): string
    {
        return 'ai-summary:'.$this->aiSummaryKind().':'.app()->getLocale().':'.hash('xxh128', $this->aiSummaryFacts());
    }

    /**
     * The summarizer's JSON as a brief, or null when it is not the agreed shape.
     *
     * @return array{verdict: string, headline: string, points: list<string>, next_step: string, made_at: string}|null
     */
    protected static function parsedAiSummary(string $text): ?array
    {
        $decoded = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $text) ?? ''), true);

        return is_array($decoded) ? self::shapedAiSummary($decoded) : null;
    }

    /**
     * A brief in the agreed shape, or null; also re-checks what the cache holds.
     *
     * @param  array<mixed>  $decoded
     * @return array{verdict: string, headline: string, points: list<string>, next_step: string, made_at: string}|null
     */
    protected static function shapedAiSummary(array $decoded): ?array
    {
        if (! in_array($decoded['verdict'] ?? null, ['on_track', 'watch', 'at_risk'], true)
            || ! is_string($decoded['headline'] ?? null) || trim($decoded['headline']) === ''
            || ! is_array($decoded['points'] ?? null)
            || ! is_string($decoded['next_step'] ?? null)) {
            return null;
        }

        $points = array_values(array_filter(
            array_map(fn (mixed $point): string => is_string($point) ? trim($point) : '', $decoded['points']),
            fn (string $point): bool => $point !== '',
        ));

        return [
            'verdict' => $decoded['verdict'],
            'headline' => trim($decoded['headline']),
            'points' => array_slice($points, 0, 4),
            'next_step' => trim($decoded['next_step']),
            'made_at' => is_string($decoded['made_at'] ?? null) ? $decoded['made_at'] : now()->toIso8601String(),
        ];
    }
}
