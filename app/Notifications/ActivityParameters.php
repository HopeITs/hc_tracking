<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Activity;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * Builds the typed sentence params for an activity. Nothing is translated
 * here: enum values, dates and names stay typed so they render in whatever
 * language the reader uses (see NotificationPresenter::param()).
 */
final class ActivityParameters
{
    /**
     * Properties copied through verbatim when present.
     */
    private const PassThrough = ['subtask', 'file', 'note', 'role', 'baseline', 'value'];

    /**
     * @return array<string, mixed>
     */
    public function for(Activity $activity): array
    {
        $actorName = $activity->relationLoaded('actor')
            ? $activity->getRelation('actor')?->name
            : ($activity->actor_id === null ? null : $activity->actor()->first(['id', 'hr_user_id'])?->name);

        $params = [
            'actor' => $actorName ?? ['trans' => 'notifications.system'],
            'subject' => $activity->label(),
        ];

        $field = $activity->properties['field'] ?? null;

        if (is_string($field) && array_key_exists($field, $activity->changes)) {
            [$old, $new] = $activity->changes[$field];
            $params['from'] = $this->typedChange($activity, $field, $old, 0);
            $params['to'] = $this->typedChange($activity, $field, $new, 1);
        }

        if (isset($activity->properties['employee'])) {
            $params['employee'] = ['localized' => $activity->properties['employee']];
        }

        foreach (self::PassThrough as $key) {
            if (isset($activity->properties[$key])) {
                $params[$key] = (string) $activity->properties[$key];
            }
        }

        return $params;
    }

    /**
     * One side of a change, typed by the subject model's cast.
     */
    public function typedChange(Activity $activity, string $field, mixed $value, int $side): mixed
    {
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        if ($value === null || $value === '') {
            return ['trans' => 'notifications.none'];
        }

        $name = $activity->properties['names'][$field][$side] ?? null;

        if (is_array($name)) {
            return ['localized' => $name];
        }

        $cast = $this->castFor($activity->subject_type, $field);

        if ($cast !== null && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class)) {
            return ['enum' => Str::snake(class_basename($cast)), 'value' => (string) $value];
        }

        if ($cast !== null && str_contains($cast, 'date')) {
            return ['date' => substr((string) $value, 0, 10)];
        }

        return (string) $value;
    }

    private function castFor(string $subjectType, string $field): ?string
    {
        $class = Relation::getMorphedModel($subjectType);

        if ($class === null) {
            return null;
        }

        /** @var Model $model */
        $model = new $class;

        return $model->getCasts()[$field] ?? null;
    }
}
