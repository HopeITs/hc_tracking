<?php

declare(strict_types=1);

namespace App\Actions\NarrativeQuestions;

use App\Models\NarrativeQuestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Move a question one place up or down within its frequency. Every row of
 * that frequency is locked (in ascending id order) and the group is
 * renumbered 1…n, so two managers moving at once never leave duplicate or
 * missing positions. A move past either end changes nothing.
 */
final class MoveNarrativeQuestion
{
    /**
     * @return int the question's new position within its frequency, counted from 1
     *
     * @throws ValidationException when someone else moved the question to the other frequency meanwhile
     */
    public function handle(int $questionId, bool $towardsStart): int
    {
        $question = NarrativeQuestion::query()->findOrFail($questionId);

        Gate::authorize('update', $question);

        $frequency = $question->frequency;

        return DB::transaction(function () use ($questionId, $towardsStart, $frequency): int {
            NarrativeQuestion::query()->where('frequency', $frequency)->orderBy('id')->lockForUpdate()->pluck('id');

            $question = NarrativeQuestion::query()->findOrFail($questionId);

            if ($question->frequency !== $frequency) {
                throw ValidationException::withMessages(['question' => __('narrative_questions.stale')]);
            }

            /** @var list<int> $order */
            $order = array_values(NarrativeQuestion::query()->where('frequency', $frequency)->ordered()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
            $index = (int) array_search($question->id, $order, true);
            $target = $towardsStart ? $index - 1 : $index + 1;

            if ($target >= 0 && $target < count($order)) {
                [$order[$index], $order[$target]] = [$order[$target], $order[$index]];
                $index = $target;
            }

            foreach ($order as $position => $id) {
                NarrativeQuestion::query()->whereKey($id)->where('sort_order', '!=', $position + 1)->update(['sort_order' => $position + 1]);
            }

            return $index + 1;
        });
    }
}
