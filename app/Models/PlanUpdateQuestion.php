<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question a reviewer asked on a monthly update, or the project manager's
 * answer: one thread, oldest first.
 *
 * @property int $id
 * @property int $plan_update_id
 * @property int|null $user_id
 * @property string $body
 */
#[Fillable(['plan_update_id', 'user_id', 'body'])]
class PlanUpdateQuestion extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan_update_id' => 'integer',
            'user_id' => 'integer',
        ];
    }
}
