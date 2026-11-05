<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Support\Ai\Agents\DigestBriefer;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The AI paragraph on top of the monthly digest email.
 *
 * Readers with the same counts in the same language share one paragraph (one
 * model call per distinct digest, also across mail retries). It is optional:
 * when AI is switched off or the model fails, the mail goes out without it.
 *
 * @phpstan-import-type Sections from \App\Notifications\Alerts\MonthlyDigestScanner
 */
final class MonthlyDigestBriefing
{
    public function __construct(private AiGateway $gateway) {}

    /**
     * @param  Sections  $sections
     */
    public function for(string $monthLabel, bool $allPrograms, array $sections, string $locale): ?string
    {
        if (! config('ai.digest_briefing')) {
            return null;
        }

        $facts = $this->facts($monthLabel, $allPrograms, $sections);
        $key = 'ai-digest-briefing:'.$locale.':'.hash('xxh128', $facts);

        if (is_string($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $briefer = new DigestBriefer($locale === 'ar' ? 'ar' : 'en');
            $text = $this->gateway->answerText($this->gateway->prompt($briefer, $briefer->promptFor($facts), 'monthly-digest-briefing', tier: 'fast'));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if ($text === '') {
            return null;
        }

        Cache::put($key, $text, now()->addDays(7));

        return $text;
    }

    /**
     * @param  Sections  $sections
     */
    private function facts(string $monthLabel, bool $allPrograms, array $sections): string
    {
        $facts = [
            'MONTH: '.$monthLabel,
            'SCOPE: '.($allPrograms ? 'all programs' : 'the reader\'s programs'),
            'COUNTS:',
        ];

        foreach ($sections as $section => $lines) {
            foreach ($lines as $line => $count) {
                $facts[] = '- '.__('notifications.digests.monthly.lines.'.$section.'_'.$line).': '.$count;
            }
        }

        return implode("\n", $facts);
    }
}
