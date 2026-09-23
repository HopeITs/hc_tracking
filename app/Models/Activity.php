<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One meaningful thing that happened to a record: the source of truth for the
 * Activity tab, the global log and every action notification.
 *
 * Recording is global and on by default. Seeders, imports and the HR mirror
 * wrap their writes in withoutRecording() so they produce no activity and
 * therefore no notifications.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $subject_type
 * @property int $subject_id
 * @property int|null $program_id
 * @property string $module
 * @property string $event
 * @property array<string, array{0: mixed, 1: mixed}> $changes
 * @property array<string, mixed> $properties
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['actor_id', 'subject_type', 'subject_id', 'program_id', 'module', 'event', 'changes', 'properties'])]
class Activity extends Model
{
    use Prunable;

    private static bool $recording = true;

    /**
     * Run the callback without recording any activity, restoring the previous
     * state afterwards even when it throws.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutRecording(callable $callback): mixed
    {
        $previous = self::$recording;
        self::$recording = false;

        try {
            return $callback();
        } finally {
            self::$recording = $previous;
        }
    }

    /**
     * Whether model events currently write activity.
     */
    public static function isRecording(): bool
    {
        return self::$recording;
    }

    /**
     * The user who acted; null for the system.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The record this happened to. Can be gone.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether this activity is meaningful enough to notify anyone.
     */
    public function shouldNotify(): bool
    {
        return (bool) ($this->properties['notify'] ?? false);
    }

    /**
     * The record's name as it was when this happened.
     */
    public function label(): string
    {
        return (string) ($this->properties['label'] ?? '');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'subject_id' => 'integer',
            'program_id' => 'integer',
            'changes' => 'json:unicode',
            'properties' => 'json:unicode',
        ];
    }

    /**
     * Activity older than the retention period (default two years).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subYears((int) config('notifications.retention.activity_years')));
    }
}
