<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsActivity;
use App\Enums\DonorReportType;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\DonorReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A report owed to a donor under one funding agreement.
 *
 * @property int $id
 * @property int $donor_proposal_id
 * @property string $title
 * @property DonorReportType $type
 * @property CarbonImmutable $due_on
 * @property CarbonImmutable|null $submitted_on
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['donor_proposal_id', 'title', 'type', 'due_on', 'submitted_on'])]
class DonorReport extends Model implements TracksActivity
{
    /** @use HasFactory<DonorReportFactory> */
    use HasFactory, RecordsActivity;

    /**
     * Mirrors the column default, so a report created without a type reads as
     * narrative before it is refreshed.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'narrative',
    ];

    /**
     * The funding agreement this report is owed under.
     *
     * @return BelongsTo<DonorProposal, $this>
     */
    public function donorProposal(): BelongsTo
    {
        return $this->belongsTo(DonorProposal::class);
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_on !== null;
    }

    /**
     * Still unsent after its due date. A report is due on its date, not late.
     */
    public function isOverdue(): bool
    {
        return ! $this->isSubmitted() && $this->due_on->lt(CarbonImmutable::today());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'type' => DonorReportType::class,
            'due_on' => 'date',
            'submitted_on' => 'date',
        ];
    }

    public function activityModule(): string
    {
        return 'funding-agreements';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('title');
    }

    public function activityProgramId(): ?int
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return ['submitted_on' => 'submitted', 'due_on' => 'updated', 'title' => 'updated'];
    }

    /**
     * Only gaining a submission date is "submitted".
     */
    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        if ($field === 'submitted_on') {
            return $new !== null ? 'submitted' : 'updated';
        }

        return $this->activityEvents()[$field] ?? null;
    }
}
