<?php

declare(strict_types=1);

namespace App\Support\Ai\Agents;

use App\Support\Ai\HcAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * Drafts the narrative answers of one monthly report from that month's facts.
 *
 * Only words: every figure it may use is handed to it in the brief, and the
 * officer reviews and edits the draft before it is submitted.
 */
final class MonthlyNarrativeDrafter extends HcAgent implements HasStructuredOutput
{
    /**
     * @param  'ar'|'en'  $locale  The language the officer works in.
     */
    public function __construct(public string $locale) {}

    public function instructions(): string
    {
        $language = $this->locale === 'ar' ? 'Modern Standard Arabic' : 'English';

        return <<<PROMPT
        You are a MEAL officer at a humanitarian NGO writing the narrative part of a monthly project report.

        Rules:
        - Write every answer in {$language}, in a clear, factual, professional tone.
        - Use ONLY the facts in the brief. Never invent numbers, dates, names, places or events.
        - When you mention a figure, copy it exactly as written in the brief.
        - Never calculate new figures (no differences, totals or percentages of change) and never turn a vague time such as "first week of November" into an exact date.
        - If the brief has no facts for a question, answer with an empty string.
        - Keep each answer to 2-5 sentences. No headings, no bullet symbols, no greetings.
        - Answer every question id from the brief exactly once.
        PROMPT;
    }

    /**
     * The brief plus a closing line in the answer language. Local models
     * follow the language of the facts over the system instructions, so the
     * language is restated last, in that language.
     */
    public function promptFor(string $brief): string
    {
        $reminder = $this->locale === 'ar'
            ? 'اكتب جميع الإجابات باللغة العربية الفصحى فقط، حتى لو كانت المعطيات أعلاه بالإنجليزية. انسخ الأرقام كما هي.'
            : 'Write every answer in English only, even if some facts above are in Arabic. Copy figures exactly.';

        return $brief.PHP_EOL.PHP_EOL.$reminder;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'answers' => $schema->array()->items($schema->object(fn (JsonSchema $item): array => [
                'question_id' => $item->integer()->required(),
                'answer' => $item->string()->required(),
            ]))->required(),
        ];
    }
}
