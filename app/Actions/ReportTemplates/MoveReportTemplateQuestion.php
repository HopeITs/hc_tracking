<?php

declare(strict_types=1);

namespace App\Actions\ReportTemplates;

use App\Models\ReportTemplate;
use App\Models\ReportTemplateQuestion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Move a narrative question of one report template one place up or down
 * within its frequency (contracts.md §12.4, Y-07), exactly as on the bank's
 * page: every question of that frequency on the template is locked in
 * ascending id order and the group is renumbered 1…n. A move past either end
 * changes nothing. The bank is never written. Not recorded as an activity.
 */
final class MoveReportTemplateQuestion
{
    /**
     * @param  'up'|'down'|string  $direction
     *
     * @throws InvalidArgumentException when the direction is neither `up` nor `down`
     * @throws ModelNotFoundException<ReportTemplateQuestion> when the question is not on this template
     * @throws ValidationException when someone else moved the question to the other frequency meanwhile
     */
    public function handle(ReportTemplate $template, int $questionId, string $direction): void
    {
        if (! in_array($direction, ['up', 'down'], true)) {
            throw new InvalidArgumentException("Unknown direction [{$direction}].");
        }

        DB::transaction(function () use ($template, $questionId, $direction): void {
            Gate::authorize('manage', $template);

            $frequency = $template->questions()->whereKey($questionId)->firstOrFail()->frequency;

            $template->questions()->where('frequency', $frequency)->orderBy('id')->lockForUpdate()->pluck('id');

            $question = $template->questions()->whereKey($questionId)->lockForUpdate()->firstOrFail();

            if ($question->frequency !== $frequency) {
                throw ValidationException::withMessages(['question' => __('report_templates.stale')]);
            }

            /** @var list<int> $order */
            $order = array_values($template->questions()->where('frequency', $frequency)->ordered()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
            $index = (int) array_search($question->id, $order, true);
            $target = $direction === 'up' ? $index - 1 : $index + 1;

            if ($target < 0 || $target >= count($order)) {
                return;
            }

            [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

            foreach ($order as $position => $id) {
                ReportTemplateQuestion::query()->whereKey($id)->where('sort_order', '!=', $position + 1)->update(['sort_order' => $position + 1]);
            }
        });
    }
}
