<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds LIKE patterns from text a user typed.
 *
 * Binding already keeps a search term out of the SQL, but inside the pattern
 * `%` and `_` still act as wildcards, so a bare `%` matches every row and
 * `_` matches any one character. Every search box goes through here so the
 * term is matched literally, and is never longer than MaxTermLength: a search
 * box accepts as much text as a Livewire request may carry, and a pattern
 * that long makes the database scan every row for it.
 */
final class Like
{
    /**
     * Characters of a search term that are used; the rest is ignored.
     */
    public const MaxTermLength = 100;

    /**
     * The part of what was typed that is searched for: trimmed, and cut to
     * MaxTermLength.
     */
    public static function term(string $term): string
    {
        return mb_substr(trim($term), 0, self::MaxTermLength);
    }

    /**
     * Escape the LIKE metacharacters (and the escape character itself) so the
     * term matches literally. MySQL uses backslash as its default escape.
     */
    public static function escape(string $term): string
    {
        return addcslashes($term, '%_\\');
    }

    /**
     * A pattern matching rows that contain the (trimmed, capped) term anywhere.
     */
    public static function contains(string $term): string
    {
        return '%'.self::escape(self::term($term)).'%';
    }
}
