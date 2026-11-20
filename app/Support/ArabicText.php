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

    /**
     * Letters search folds on top of normalize(), so a word is found however
     * it was spelled: every alef form, taa marbuta / haa, alef maqsura / yaa,
     * and the hamza seats on waw and yaa.
     *
     * @var array<string, string>
     */
    private const array SearchFolds = ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و'];

    /**
     * Characters search drops: tashkeel (U+064B–U+0655), the superscript alef and tatweel.
     *
     * @var list<string>
     */
    private const array SearchDrops = ["\u{064B}", "\u{064C}", "\u{064D}", "\u{064E}", "\u{064F}", "\u{0650}", "\u{0651}", "\u{0652}", "\u{0653}", "\u{0654}", "\u{0655}", "\u{0670}", "\u{0640}"];

    /**
     * The text without tashkeel or tatweel and nothing else changed, so a
     * word reads the same on the other keyboard layout with or without them.
     */
    public static function withoutMarks(string $text): string
    {
        return str_replace(self::SearchDrops, '', $text);
    }

    /**
     * The text as search compares it: lower case, without tashkeel or
     * tatweel, every letter variant folded (see SearchFolds).
     */
    public static function fold(string $text): string
    {
        return mb_strtolower(strtr(str_replace(self::SearchDrops, '', $text), self::SearchFolds));
    }

    /**
     * fold() as SQL over an already-wrapped column, so a LIKE compares the
     * stored text folded exactly as the typed term is. The database collation
     * alone treats these letters as different.
     */
    public static function foldSql(string $wrappedColumn): string
    {
        $sql = 'lower('.$wrappedColumn.')';

        foreach ([...array_fill_keys(self::SearchDrops, ''), ...self::SearchFolds] as $from => $to) {
            $sql = "replace({$sql}, '{$from}', '{$to}')";
        }

        return $sql;
    }

    /**
     * Whether the typed term, as typed or as read on the other keyboard
     * layout, appears anywhere in the text, both folded.
     */
    public static function containsTyped(string $text, string $term, bool $acrossLayouts = true): bool
    {
        $text = self::fold($text);

        foreach ($acrossLayouts ? KeyboardLayout::readings(trim($term)) : [trim($term)] as $reading) {
            if (array_all(self::searchWords($reading), fn (string $word): bool => str_contains($text, $word))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The folded words of a search, each one to be found on its own. A
     * leading «ال» is dropped from words over four letters, so it is
     * optional: «اغاثه» is contained in «الاغاثه» either way.
     *
     * @return list<string>
     */
    public static function searchWords(string $search): array
    {
        $words = preg_split('/\s+/u', self::fold(trim($search)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(
            fn (string $word): string => mb_strlen($word) > 4 && str_starts_with($word, 'ال') ? mb_substr($word, 2) : $word,
            $words,
        );
    }
}
