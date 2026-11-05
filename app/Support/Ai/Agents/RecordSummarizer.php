<?php

declare(strict_types=1);

namespace App\Support\Ai\Agents;

use App\Support\Ai\HcAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * Reads the facts of one record page (a project, a proposal) and returns a
 * short brief for a busy manager: a verdict, a headline, a few points and
 * the one next step. Only what the page already shows the viewer goes in.
 */
final class RecordSummarizer extends HcAgent implements HasStructuredOutput
{
    /**
     * @param  'ar'|'en'  $locale  The viewer's language.
     */
    public function __construct(public string $locale) {}

    public function instructions(): string
    {
        $language = $this->locale === 'ar' ? 'Modern Standard Arabic' : 'English';

        return <<<PROMPT
        You brief a busy manager at a humanitarian NGO on one record, in {$language}.

        Rules:
        - Use ONLY the facts given. Never invent numbers, dates, names or events.
        - Copy figures exactly as written. Never calculate new figures and never turn a vague time into an exact date.
        - verdict: "on_track" when nothing needs attention, "watch" when something is late or thin, "at_risk" when targets, money or deadlines are clearly in trouble.
        - headline: one sentence, at most 20 words, saying where the record stands.
        - points: 2 to 4 short sentences, most important first: progress, problems, money, deadlines.
        - next_step: one concrete action for the manager, taken from the facts.
        PROMPT;
    }

    /**
     * The facts plus a closing line in the brief's language; local models
     * follow the language of the facts over the system instructions.
     */
    public function promptFor(string $facts): string
    {
        $reminder = $this->locale === 'ar'
            ? 'اكتب الملخص باللغة العربية الفصحى فقط، حتى لو كانت المعطيات أعلاه بالإنجليزية. انسخ الأرقام كما هي.'
            : 'Write the brief in English only, even if some facts above are in Arabic. Copy figures exactly.';

        return $facts.PHP_EOL.PHP_EOL.$reminder;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'verdict' => $schema->string()->enum(['on_track', 'watch', 'at_risk'])->required(),
            'headline' => $schema->string()->required(),
            'points' => $schema->array()->items($schema->string())->required(),
            'next_step' => $schema->string()->required(),
        ];
    }
}
