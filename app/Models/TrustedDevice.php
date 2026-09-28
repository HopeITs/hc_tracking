<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TrustedDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser that skips the second sign-in step until `expires_at` (see
 * App\Actions\Fortify\TrustedDevices).
 *
 * `token_hash` is the SHA-256 of the random token kept in that browser's
 * encrypted cookie. `label` is UserAgent::description(), always English.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string $label
 * @property string|null $last_ip
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'token_hash', 'label', 'last_ip', 'last_used_at', 'expires_at'])]
class TrustedDevice extends Model
{
    /** @use HasFactory<TrustedDeviceFactory> */
    use HasFactory, MassPrunable;

    /**
     * The user this browser is trusted for.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Expired trust is useless: model:prune drops it.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
