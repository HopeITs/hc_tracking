<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FileType;
use Carbon\CarbonImmutable;
use Database\Factories\MonthlyReportFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A typed evidence file of a monthly report (contracts §13, §16, D-42), on
 * the report, one of its lines or one of its activity entries. Stored and
 * removed through StoredFiles; downloaded through T17B's protected route.
 *
 * @property int $id
 * @property int $monthly_report_id
 * @property int|null $monthly_report_line_id
 * @property int|null $monthly_report_activity_entry_id
 * @property int $evidence_type_id
 * @property string $path
 * @property string $disk
 * @property string|null $original_name
 * @property string|null $mime_type
 * @property int|null $size
 * @property string|null $encryption_key_id
 * @property FileType $type
 * @property int $uploaded_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'monthly_report_id',
    'monthly_report_line_id',
    'monthly_report_activity_entry_id',
    'evidence_type_id',
    'path',
    'disk',
    'original_name',
    'mime_type',
    'size',
    'encryption_key_id',
    'type',
    'uploaded_by',
])]
class MonthlyReportFile extends Model
{
    /** @use HasFactory<MonthlyReportFileFactory> */
    use HasFactory;

    /**
     * Mirror the column defaults for strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'disk' => 'local',
        'encryption_key_id' => null,
    ];

    /**
     * @return BelongsTo<MonthlyReport, $this>
     */
    public function monthlyReport(): BelongsTo
    {
        return $this->belongsTo(MonthlyReport::class);
    }

    /**
     * @return BelongsTo<MonthlyReportLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(MonthlyReportLine::class, 'monthly_report_line_id');
    }

    /**
     * @return BelongsTo<MonthlyReportActivityEntry, $this>
     */
    public function activityEntry(): BelongsTo
    {
        return $this->belongsTo(MonthlyReportActivityEntry::class, 'monthly_report_activity_entry_id');
    }

    /**
     * @return BelongsTo<EvidenceType, $this>
     */
    public function evidenceType(): BelongsTo
    {
        return $this->belongsTo(EvidenceType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'monthly_report_id' => 'integer',
            'monthly_report_line_id' => 'integer',
            'monthly_report_activity_entry_id' => 'integer',
            'evidence_type_id' => 'integer',
            'size' => 'integer',
            'type' => FileType::class,
            'uploaded_by' => 'integer',
        ];
    }
}
