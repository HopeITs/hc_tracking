<?php

namespace App\Support;

/**
 * Fixes text typed with the wrong keyboard layout: "حقخلقشةس" was typed on
 * the Arabic layout while meaning "programs", and "fvhl[" on the English
 * layout while meaning "برامج". Maps each key of the standard Arabic (101)
 * layout to its QWERTY twin and back.
 */
class KeyboardLayout
{
    /**
     * QWERTY key => the Arabic character the same key types (unshifted, then shifted).
     *
     * @var array<string, string>
     */
    private const LatinToArabic = [
        'q' => 'ض', 'w' => 'ص', 'e' => 'ث', 'r' => 'ق', 't' => 'ف', 'y' => 'غ', 'u' => 'ع', 'i' => 'ه', 'o' => 'خ', 'p' => 'ح', '[' => 'ج', ']' => 'د',
        'a' => 'ش', 's' => 'س', 'd' => 'ي', 'f' => 'ب', 'g' => 'ل', 'h' => 'ا', 'j' => 'ت', 'k' => 'ن', 'l' => 'م', ';' => 'ك', "'" => 'ط',
        'z' => 'ئ', 'x' => 'ء', 'c' => 'ؤ', 'v' => 'ر', 'b' => 'لا', 'n' => 'ى', 'm' => 'ة', ',' => 'و', '.' => 'ز', '/' => 'ظ', '`' => 'ذ',
        'Q' => 'َ', 'W' => 'ً', 'E' => 'ُ', 'R' => 'ٌ', 'T' => 'لإ', 'Y' => 'إ', 'U' => '‘', 'I' => '÷', 'O' => '×', 'P' => '؛',
        'A' => 'ِ', 'S' => 'ٍ', 'G' => 'لأ', 'H' => 'أ', 'Z' => '~', 'X' => 'ْ', 'B' => 'لآ', 'N' => 'آ', 'M' => '’', '<' => ',', '>' => '.', '?' => '؟',
    ];

    /**
     * Whether the text holds Arabic letters.
     */
    public static function hasArabic(string $text): bool
    {
        return preg_match('/\p{Arabic}/u', $text) === 1;
    }

    /**
     * Whether the text holds Latin letters.
     */
    public static function hasLatin(string $text): bool
    {
        return preg_match('/[a-z]/i', $text) === 1;
    }

    /**
     * Arabic-layout keystrokes read as the English letters on the same keys (lower case).
     */
    public static function toLatin(string $text): string
    {
        return strtr($text, self::arabicToLatin());
    }

    /**
     * English-layout keystrokes read as the Arabic letters on the same keys.
     */
    public static function toArabic(string $text): string
    {
        return strtr(mb_strtolower($text), array_filter(self::LatinToArabic, fn (string $key): bool => ! ctype_upper($key), ARRAY_FILTER_USE_KEY));
    }

    /**
     * The text as it would read had each word been typed on the other layout:
     * first every Arabic word read as English keys, then every English word
     * read as Arabic keys. Words already in the other script stay as they
     * are, so "حقخلقشةس report" offers "programs report". Only versions that
     * differ from the text are returned.
     *
     * @return list<string>
     */
    public static function candidates(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $latin = implode(' ', array_map(fn (string $word): string => self::hasArabic($word) ? self::toLatin($word) : $word, $words));
        $arabic = implode(' ', array_map(fn (string $word): string => ! self::hasArabic($word) && self::hasLatin($word) ? self::toArabic($word) : $word, $words));

        return array_values(array_unique(array_filter([$latin, $arabic], fn (string $candidate): bool => $candidate !== '' && $candidate !== implode(' ', $words))));
    }

    /**
     * The text as typed, then its readings on the other layout. Text under
     * three letters stays as typed: «في» read as "hd" would match unrelated
     * rows, and so short a search is rarely a layout mistake. Tashkeel and
     * tatweel are dropped first: search ignores them, so «شَمَّه» must read
     * exactly as «شمه» does, not as keys nobody meant.
     *
     * @return non-empty-list<string>
     */
    public static function readings(string $text): array
    {
        $text = ArabicText::withoutMarks($text);

        if (preg_match_all('/[\p{L}\p{M}]/u', $text) < 3) {
            return [$text];
        }

        return [$text, ...self::candidates($text)];
    }

    /**
     * Arabic character => its QWERTY key; strtr tries the longest keys first, so "لا" beats "ل".
     *
     * @return array<string, string>
     */
    private static function arabicToLatin(): array
    {
        static $map = null;

        if ($map === null) {
            $map = [];

            foreach (self::LatinToArabic as $key => $arabic) {
                // Shifted punctuation (, . ~ ÷) would rewrite the reader's own commas and dots.
                if (self::hasArabic($arabic)) {
                    $map[$arabic] ??= mb_strtolower($key);
                }
            }
        }

        return $map;
    }
}
