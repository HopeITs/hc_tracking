<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NarrativeQuestionFrequency;
use Illuminate\Support\Facades\DB;

/**
 * The starting narrative question bank (T33), and the one idempotent rule
 * that seeds it, shared by the data migration and NarrativeQuestionSeeder.
 * The texts are placeholders for MEAL to review and edit on the page.
 *
 * contracts.md §12.4 gives `narrative_questions` no key column, so a default
 * counts as present when any question carries its Arabic **or** its English
 * text. A question MEAL edited in one language, or deactivated, therefore
 * never comes back as a duplicate. Query builder only: no model events and
 * no activity.
 */
final class DefaultNarrativeQuestions
{
    /**
     * In page order: the monthly questions, then the quarter-end ones.
     *
     * @return list<array{text_ar: string, text_en: string, frequency: string}>
     */
    public static function all(): array
    {
        $monthly = NarrativeQuestionFrequency::Monthly->value;
        $quarterEnd = NarrativeQuestionFrequency::QuarterEnd->value;

        return [
            ['text_ar' => 'ما أبرز الإنجازات التي حققها المشروع هذا الشهر؟', 'text_en' => 'What were the project\'s main achievements this month?', 'frequency' => $monthly],
            ['text_ar' => 'ما التحديات التي واجهتموها هذا الشهر، وكيف تعاملتم معها؟', 'text_en' => 'What challenges did you face this month, and how did you handle them?', 'frequency' => $monthly],
            ['text_ar' => 'هل حدثت تغييرات على الخطة أو انحرافات عنها؟ وما أسبابها؟', 'text_en' => 'Were there any changes to the plan or deviations from it? Why?', 'frequency' => $monthly],
            ['text_ar' => 'ما خطة العمل للشهر القادم؟', 'text_en' => 'What is the work plan for next month?', 'frequency' => $monthly],
            ['text_ar' => 'ما مدى التقدم نحو نتائج المشروع خلال هذا الربع، وما الدروس المستفادة؟', 'text_en' => 'What progress did the project make towards its results this quarter, and what lessons were learned?', 'frequency' => $quarterEnd],
            ['text_ar' => 'شاركونا قصة نجاح أو دراسة حالة لأحد المستفيدين.', 'text_en' => 'Share a beneficiary success story or case study.', 'frequency' => $quarterEnd],
        ];
    }

    /**
     * Insert the defaults that are missing, after the existing questions of
     * their frequency.
     *
     * @return int how many were inserted
     */
    public static function ensure(): int
    {
        return DB::transaction(function (): int {
            $existing = DB::table('narrative_questions')->orderBy('id')->lockForUpdate()->get(['text_ar', 'text_en', 'frequency', 'sort_order']);
            $texts = $existing->pluck('text_ar')->merge($existing->pluck('text_en'))->filter()->all();
            $positions = $existing->groupBy('frequency')->map(fn ($questions): int => (int) $questions->max('sort_order'))->all();
            $now = now();
            $inserted = 0;

            foreach (self::all() as $default) {
                if (in_array($default['text_ar'], $texts, true) || in_array($default['text_en'], $texts, true)) {
                    continue;
                }

                $positions[$default['frequency']] = ($positions[$default['frequency']] ?? 0) + 1;

                DB::table('narrative_questions')->insert([
                    ...$default,
                    'is_active' => true,
                    'sort_order' => $positions[$default['frequency']],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $inserted++;
            }

            return $inserted;
        });
    }
}
