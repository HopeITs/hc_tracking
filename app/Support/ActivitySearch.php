<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The activity log's search box. A row reads as a sentence ("Sami Haddad
 * deactivated the library indicator “HC.G.1”"), but only its event key and
 * properties are stored: the person's name lives in HR and the wording in
 * the lang files. So each typed word is found in any of: the event key, the
 * properties (ignoring case), the name of the person who did it, or the
 * wording of the sentence in the reader's language.
 */
final class ActivitySearch
{
    /**
     * Lang lines that are drawn for a stored event other than their own
     * name (see ActivityPresenter::sentenceKey()).
     *
     * @var array<string, string>
     */
    private const array SentenceVariants = [
        'program.manager_removed' => 'program.manager_changed',
        'program.manager_and_strategy_changed' => 'program.manager_changed',
        'annual_plan.manager_removed' => 'annual_plan.manager_changed',
    ];

    /**
     * A where-closure: rows where every typed word is found somewhere in the
     * entry. People and wording are looked up once per word, ahead of the query.
     *
     * @return Closure(EloquentBuilder<*>|QueryBuilder): void
     */
    public static function matching(string $search): Closure
    {
        $actorIds = [];
        $events = [];

        foreach (Like::searchWords($search) as $word) {
            $actorIds[$word] = array_values(User::query()->whereNameOrEmailLike($word)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
            $events[$word] = self::eventsSaying($word);
        }

        return Like::everyWord($search, fn (EloquentBuilder|QueryBuilder $query, string $word) => $query
            ->where(Like::word('event', $word))
            ->orWhere(self::propertiesContain($word))
            ->when(($actorIds[$word] ?? []) !== [], fn (EloquentBuilder|QueryBuilder $query) => $query->orWhereIn('actor_id', $actorIds[$word]))
            ->when(($events[$word] ?? []) !== [], fn (EloquentBuilder|QueryBuilder $query) => $query->orWhereIn('event', $events[$word])));
    }

    /**
     * The event keys whose sentence, without its placeholders, contains the
     * already-folded word in the current language.
     *
     * @return list<string>
     */
    public static function eventsSaying(string $word): array
    {
        $groups = __('notifications.events');
        $events = [];

        if (! is_array($groups)) {
            return [];
        }

        foreach ($groups as $module => $lines) {
            if (! is_array($lines)) {
                continue;
            }

            foreach ($lines as $name => $line) {
                $text = is_array($line) ? ($line['body'] ?? '') : $line;

                if (! is_string($text)) {
                    continue;
                }

                $wording = preg_replace('/:[a-z_]+/', ' ', $text) ?? $text;

                if (str_contains(ArabicText::fold($wording), $word)) {
                    $key = "{$module}.{$name}";
                    $events[] = self::SentenceVariants[$key] ?? $key;
                }
            }
        }

        return array_values(array_unique($events));
    }

    /**
     * The properties column is JSON, which the database keeps in a binary,
     * case-sensitive collation, so an English word is compared lower-cased
     * (an Arabic word is already folded and lower-cased by Like::word()).
     *
     * @return Closure(EloquentBuilder<*>|QueryBuilder): void
     */
    private static function propertiesContain(string $word): Closure
    {
        return function (EloquentBuilder|QueryBuilder $query) use ($word): void {
            if (KeyboardLayout::hasArabic($word)) {
                $query->where(Like::word('properties', $word));

                return;
            }

            $query->whereRaw('lower(properties) like ?', ['%'.Like::escape($word).'%']);
        };
    }
}
