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

/**
 * @property int $id
 * @property int $report_id
 * @property string $path
 * @property CarbonImmutable $date
 * @property FileType $type
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['report_id', 'path', 'date', 'type'])]
class ReportFile extends Model
{
    /** @use HasFactory<ReportFileFactory> */
    use HasFactory;

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
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => FileType::class,
        ];
    }
}
