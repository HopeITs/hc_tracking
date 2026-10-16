<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;

/**
 * Projects whose names look like the one being typed (T10, D-30).
 *
 * A hint for the Create Project form, never a block: two centers may run
 * similar projects, and the same pair of center and program is refused
 * elsewhere (Project::openProjectFor). The typed name is split into words;
 * a project is similar when it contains at least half of them, matched with
 * Like::contains() under utf8mb4_unicode_ci, so Arabic and diacritics match.
 * The current center is excluded, archived projects are not offered, and the
 * newest come first. Only the project's own columns are read: no money.
 */
final class SimilarProjects
{
    /** Most projects offered. */
    public const Limit = 5;

    /** Most words of the name that are matched. */
    private const MaxWords = 6;

    /**
     * @return Collection<int, Project>
     */
    public static function find(string $name, ?int $programId = null, ?int $excludeCenterId = null): Collection
    {
        $words = self::words($name);

        if ($words === []) {
            return new Collection;
        }

        $patterns = array_map(fn (string $word): string => Like::contains($word), $words);
        $score = implode(' + ', array_fill(0, count($patterns), '(projects.name like ?)'));
        $needed = (int) ceil(count($patterns) / 2);

        return Project::query()
            ->select(['projects.id', 'projects.slug', 'projects.name', 'projects.status', 'projects.center_id', 'projects.program_id'])
            ->where('projects.status', '!=', ProjectStatus::Archived->value)
            ->when($programId !== null, fn ($query) => $query->where('projects.program_id', $programId))
            ->when($excludeCenterId !== null, fn ($query) => $query->where(
                fn ($inner) => $inner->whereNull('projects.center_id')->orWhere('projects.center_id', '!=', $excludeCenterId),
            ))
            ->whereRaw("({$score}) >= ?", [...$patterns, $needed])
            ->orderByDesc('projects.id')
            ->limit(self::Limit)
            ->get();
    }

    /**
     * The distinct words of a name worth matching: at least two characters,
     * punctuation and dashes dropped, capped at MaxWords.
     *
     * @return list<string>
     */
    public static function words(string $name): array
    {
        $parts = preg_split('/[\s\p{P}\p{S}]+/u', Like::term($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter($parts, fn (string $word): bool => mb_strlen($word) >= 2);

        return array_slice(array_values(array_unique(array_map('mb_strtolower', $words))), 0, self::MaxWords);
    }
}
