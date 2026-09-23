<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Activity;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Renders an activity row for the Activity tab and the global log, in the
 * reader's language, with the same sentences as notifications.
 */
final class ActivityPresenter
{
    public function __construct(
        private ActivityParameters $parameters,
        private NotificationPresenter $presenter,
    ) {}

    public function sentence(Activity $activity, ?string $locale = null): string
    {
        return $this->presenter->sentence([
            'key' => 'notifications.events.'.$activity->event,
            'params' => $this->parameters->for($activity),
            'count' => 1,
        ], $locale);
    }

    /**
     * @return list<array{field: string, from: string, to: string}>
     */
    public function changes(Activity $activity, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $rows = [];

        foreach ($activity->changes as $field => [$old, $new]) {
            $rows[] = [
                'field' => Lang::has("activity.fields.{$field}", $locale) ? (string) __("activity.fields.{$field}", [], $locale) : Str::headline($field),
                'from' => $this->presenter->param($this->parameters->typedChange($activity, $field, $old, 0), $locale),
                'to' => $this->presenter->param($this->parameters->typedChange($activity, $field, $new, 1), $locale),
            ];
        }

        return $rows;
    }
}
