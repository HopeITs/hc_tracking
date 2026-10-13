<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Folds Arabic text so a search matches however the words were typed.
 *
 * Tashkeel (U+064B–U+065F), the superscript alef (U+0670) and tatweel
 * (U+0640) are dropped, and the hamza and madda forms of alef (أ إ آ) become
 * a bare alef. Both the stored text and the typed term go through
 * normalize(), then are compared case-insensitively, so Latin text and codes
 * search the same way.
 */
final class ArabicText
{
    public static function normalize(string $text): string
    {
        $folded = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;

        return mb_strtolower(strtr($folded, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا']));
    }

    /**
     * Whether the term appears anywhere in the text, both folded.
     */
    public static function contains(string $text, string $term): bool
    {
        return str_contains(self::normalize($text), self::normalize($term));
    }
}
