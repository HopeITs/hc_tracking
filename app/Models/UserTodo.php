<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserTodoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a user's private To-do desk panel. Only its owner reads or
 * changes it; it is never shared, linked to a task or logged as activity.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property bool $is_done
 * @property CarbonImmutable|null $done_at
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'title', 'is_done', 'done_at', 'sort_order'])]
class UserTodo extends Model
{
    /** @use HasFactory<UserTodoFactory> */
    use HasFactory;

    /**
     * Mirrors the column defaults, so a new to-do reads as open before it is refreshed.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_done' => false,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'done_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The user whose list this is.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Open to-dos in their own order, then done ones, latest ticked first.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('is_done')
            ->orderByRaw('CASE WHEN is_done = 1 THEN 0 ELSE sort_order END')
            ->orderByDesc('done_at')
            ->orderBy('id');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('is_done', false);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function done(Builder $query): void
    {
        $query->where('is_done', true);
    }
}
