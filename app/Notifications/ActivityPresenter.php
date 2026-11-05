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
            'key' => $this->sentenceKey($activity),
            'params' => $this->parameters->for($activity),
            'count' => 1,
        ], $locale);
    }

    /**
     * The lang line for an activity. A manager change reads differently when
     * the manager was cleared ("removed X as manager", never "made none the
     * manager") or when the same save also linked a strategy.
     */
    private function sentenceKey(Activity $activity): string
    {
        $key = 'notifications.events.'.$activity->event;

        if (! in_array($activity->event, ['program.manager_changed', 'annual_plan.manager_changed'], true)) {
            return $key;
        }

        if (blank($activity->changes['manager_id'][1] ?? null)) {
            return 'notifications.events.'.Str::before($activity->event, '.').'.manager_removed';
        }

        if ($activity->event === 'program.manager_changed' && filled($activity->changes['strategy_id'][1] ?? null)) {
            return 'notifications.events.program.manager_and_strategy_changed';
        }

        return $key;
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
