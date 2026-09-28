<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\KnownDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser/platform pair one user has signed in from (see RecordSignInDevice).
 *
 * `fingerprint` is UserAgent::fingerprint(), which ignores version numbers, so
 * a browser update is still the same device. `label` is
 * UserAgent::description(), always English (e.g. "Chrome on Windows") so a
 * stored row never depends on the language of the request that wrote it;
 * screens and mails translate from the UserAgent instead.
 *
 * @property int $id
 * @property int $user_id
 * @property string $fingerprint
 * @property string $label
 * @property string|null $last_ip
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'fingerprint', 'label', 'last_ip', 'last_seen_at'])]
class KnownDevice extends Model
{
    /** @use HasFactory<KnownDeviceFactory> */
    use HasFactory;

    /**
     * The user who signed in from this device.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }
}
