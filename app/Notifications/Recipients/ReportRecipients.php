<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\Report;
use App\Models\User;

/**
 * Reports: the author is direct; MEAL watch all reports; Public Relations
 * and Media Team watch reports written by someone holding their own role.
 */
final class ReportRecipients extends ModuleRecipients
{
    private const TeamRoles = ['Public Relations', 'Media Team'];

    public function directUserIds(Activity $activity): array
    {
        $author = $this->authorId($activity);

        return $author === null ? [] : [$author];
    }

    public function watcherRoles(Activity $activity): array
    {
        $author = $this->authorId($activity);
        $authorRoles = $author === null ? [] : (User::query()->with('roles:id,name')->find($author)?->roles->pluck('name')->all() ?? []);

        return ['MEAL', ...array_values(array_intersect(self::TeamRoles, $authorRoles))];
    }

    private function authorId(Activity $activity): ?int
    {
        $author = $activity->properties['author_id'] ?? Report::query()->whereKey($activity->subject_id)->value('user_id');

        return $author === null ? null : (int) $author;
    }
}
