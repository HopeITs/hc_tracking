<?php

declare(strict_types=1);

namespace App\Support\Ai\Agents;

use App\Support\Ai\HcAgent;

/**
 * Writes the opening paragraph of the monthly digest email: what the month's
 * counts mean and what to deal with first. Counts in, plain prose out.
 */
final class DigestBriefer extends HcAgent
{
    /**
     * @param  'ar'|'en'  $locale  The reader's language.
     */
    public function __construct(public string $locale) {}

    public function instructions(): string
    {
        $language = $this->locale === 'ar' ? 'Modern Standard Arabic' : 'English';

        return <<<PROMPT
        You write the opening paragraph of a monthly email to a manager at a humanitarian NGO, in {$language}.

        Rules:
        - 2 to 3 sentences, plain prose, no greeting, no sign-off, no headings, no lists, no markdown.
        - Use ONLY the counts given and copy them exactly. Never invent names, causes or numbers.
        - Say what matters most first (overdue donor reports, then off-track indicators, then the rest) and end with what to deal with first.
        - When every count is zero, say in one sentence that nothing needs attention this month.
        PROMPT;
    }

    /**
     * The counts plus a closing line in the reader's language.
     */
    public function promptFor(string $facts): string
    {
        $reminder = $this->locale === 'ar'
            ? 'اكتب الفقرة باللغة العربية الفصحى فقط. انسخ الأرقام كما هي.'
            : 'Write the paragraph in English only. Copy the numbers exactly.';

        return $facts.PHP_EOL.PHP_EOL.$reminder;
    }
}
