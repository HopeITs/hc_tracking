<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReopenRequestStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MonthlyReportReopenRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to reopen a validated monthly report, decided per D-09a
 * (contracts §13). Requests are never deleted.
 *
 * @property int $id
 * @property int $monthly_report_id
 * @property int $requested_by
 * @property string $reason
 * @property ReopenRequestStatus $status
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'monthly_report_id',
    'requested_by',
    'reason',
    'status',
    'decided_by',
    'decided_at',
    'decision_note',
])]
class MonthlyReportReopenRequest extends Model
{
    /** @use HasFactory<MonthlyReportReopenRequestFactory> */
    use HasFactory;

    /**
     * Mirror the column default for strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return BelongsTo<MonthlyReport, $this>
     */
    public function monthlyReport(): BelongsTo
    {
        return $this->belongsTo(MonthlyReport::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
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
            'requested_by' => 'integer',
            'status' => ReopenRequestStatus::class,
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
        ];
    }
}
