<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds LIKE patterns from text a user typed.
 *
 * Binding already keeps a search term out of the SQL, but inside the pattern
 * `%` and `_` still act as wildcards, so a bare `%` matches every row and
 * `_` matches any one character. Every search box goes through here so the
 * term is matched literally.
 */
final class Like
{
    /**
     * Escape the LIKE metacharacters (and the escape character itself) so the
     * term matches literally. MySQL uses backslash as its default escape.
     */
    public static function escape(string $term): string
    {
        return addcslashes($term, '%_\\');
    }

    /**
     * A pattern matching rows that contain the (trimmed) term anywhere.
     */
    public static function contains(string $term): string
    {
        return '%'.self::escape(trim($term)).'%';
    }
}
