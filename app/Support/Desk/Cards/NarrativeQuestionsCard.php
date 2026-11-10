<?php

declare(strict_types=1);

namespace App\Support\Desk\Cards;

use App\Enums\NarrativeQuestionFrequency;
use App\Models\NarrativeQuestion;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Narrative questions in card form: what monthly reports ask, split by when
 * each question is asked.
 */
final class NarrativeQuestionsCard extends PageCard
{
    public function route(): string
    {
        return 'admin.narrative-questions.index';
    }

    public function figures(User $user): array
    {
        $active = NarrativeQuestion::query()->active()->count();

        return [
            $this->figure(__('desk-workspace.card.figure.asked'), $active, 'good'),
            $this->figure(NarrativeQuestionFrequency::QuarterEnd->label(), NarrativeQuestion::query()->active()->where('frequency', NarrativeQuestionFrequency::QuarterEnd)->count()),
            $this->figure(__('desk-workspace.card.figure.inactive'), NarrativeQuestion::query()->count() - $active),
        ];
    }

    public function breakdown(User $user): array
    {
        $counts = NarrativeQuestion::query()->active()->toBase()->selectRaw('frequency, count(*) as total')->groupBy('frequency')->pluck('total', 'frequency');

        return $this->parts(array_map(fn (NarrativeQuestionFrequency $frequency): array => [
            'label' => (string) $frequency->label(),
            'count' => (int) ($counts[$frequency->value] ?? 0),
            'tone' => $frequency === NarrativeQuestionFrequency::Monthly ? 'active' : 'warn',
        ], NarrativeQuestionFrequency::cases()));
    }

    public function hero(User $user): ?array
    {
        $counts = NarrativeQuestion::query()->active()->toBase()->selectRaw('frequency, count(*) as total')->groupBy('frequency')->pluck('total', 'frequency');

        return [
            'kind' => 'dots',
            'big' => $this->number((int) $counts->sum()),
            'caption' => (string) __('desk-workspace.card.figure.asked'),
            'tone' => 'good',
            'items' => array_map(fn (NarrativeQuestionFrequency $frequency): array => [
                'label' => (string) $frequency->label(),
                'value' => $this->number((int) ($counts[$frequency->value] ?? 0)),
                'count' => min(12, (int) ($counts[$frequency->value] ?? 0)),
                'tone' => $frequency === NarrativeQuestionFrequency::Monthly ? 'active' : 'warn',
            ], NarrativeQuestionFrequency::cases()),
        ];
    }

    public function layout(): string
    {
        return 'numbered';
    }

    protected function records(User $user, string $search): Collection
    {
        $arabic = app()->getLocale() === 'ar';
        $place = 0;

        return NarrativeQuestion::query()->ordered()->get()->map(function (NarrativeQuestion $question) use ($arabic, &$place): array {
            $place++;

            return $this->shaped(
                $this->row(
                    'nq-'.$question->id,
                    $arabic || blank($question->text_en) ? $question->text_ar : (string) $question->text_en,
                    (string) $question->frequency->label(),
                    $question->is_active ? null : $this->activeBadge(false),
                    route($this->route()),
                    $question->text_en.' '.$question->text_ar,
                ),
                lead: ['kind' => 'number', 'big' => (string) $place, 'tone' => $question->is_active ? 'active' : 'neutral'],
            );
        });
    }
}
