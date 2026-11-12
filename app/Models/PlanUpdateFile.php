<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supporting file of a whole monthly update (attendance sheets, photos…),
 * stored privately and downloaded through annual-plans.update-files.download.
 *
 * @property int $id
 * @property int $plan_update_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime_type
 * @property int $size
 * @property int|null $uploaded_by
 */
#[Fillable(['plan_update_id', 'disk', 'path', 'original_name', 'mime_type', 'size', 'uploaded_by'])]
class PlanUpdateFile extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'disk' => 'local',
        'size' => 0,
    ];

    /**
     * @return BelongsTo<PlanUpdate, $this>
     */
    public function planUpdate(): BelongsTo
    {
        return $this->belongsTo(PlanUpdate::class, 'plan_update_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan_update_id' => 'integer',
            'size' => 'integer',
            'uploaded_by' => 'integer',
        ];
    }
}
