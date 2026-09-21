<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FileType;
use Carbon\CarbonImmutable;
use Database\Factories\ReportFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $report_id
 * @property string $path
 * @property string $disk
 * @property string|null $original_name
 * @property string|null $mime_type
 * @property int|null $size
 * @property CarbonImmutable $date
 * @property FileType $type
 * @property int|null $uploaded_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['report_id', 'path', 'disk', 'original_name', 'mime_type', 'size', 'date', 'type', 'uploaded_by'])]
class ReportFile extends Model
{
    /** @use HasFactory<ReportFileFactory> */
    use HasFactory;

    /**
     * Mirror the column default so an unsaved model still reports its disk
     * under strict mode (see Media::$attributes).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'disk' => 'local',
    ];

    /**
     * The report this file backs up.
     *
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * The staff member who uploaded this file.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The name shown to people and used as the downloaded filename.
     *
     * Reference-only rows recorded before real storage existed have no
     * original name, so they fall back to the last segment of their path.
     */
    public function displayName(): string
    {
        return $this->original_name ?? basename($this->path);
    }

    /**
     * Whether a real file sits on disk behind this row.
     *
     * Reference-only rows (and seeded demo rows) never had one written.
     */
    public function isStored(): bool
    {
        return $this->original_name !== null && Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * Whether the browser can render this file type inline (PDF or image).
     */
    public function isPreviewable(): bool
    {
        return in_array($this->type, [FileType::Pdf, FileType::Jpg, FileType::Png], true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => FileType::class,
            'size' => 'integer',
        ];
    }
}
