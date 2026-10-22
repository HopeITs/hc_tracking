<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsActivity;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\RiskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string|null $impact
 * @property string $name
 * @property int $project_id
 * @property bool $happened
 * @property int|null $likelihood
 * @property int|null $severity
 * @property string|null $solution
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['impact', 'name', 'project_id', 'happened', 'likelihood', 'severity', 'solution'])]
class Risk extends Model implements TracksActivity
{
    /** @use HasFactory<RiskFactory> */
    use HasFactory, RecordsActivity;

    /**
     * The project this risk was logged against.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Likelihood × severity (1–9) for the heat map, or null until both are rated.
     */
    public function heatScore(): ?int
    {
        return $this->likelihood === null || $this->severity === null ? null : $this->likelihood * $this->severity;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'happened' => 'boolean',
            'likelihood' => 'integer',
            'severity' => 'integer',
        ];
    }

    public function activityModule(): string
    {
        return 'risks';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
    }

    public function activityProgramId(): ?int
    {
        return Project::programIdFor($this->activityAttribute('project_id'));
    }

    /**
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return ['happened' => 'happened', 'name' => 'updated', 'impact' => 'updated', 'likelihood' => 'updated', 'severity' => 'updated', 'solution' => 'updated'];
    }

    /**
     * Only becoming a challenge is "happened"; going back is a plain update.
     */
    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        if ($field === 'happened') {
            return (bool) $new ? 'happened' : 'updated';
        }

        return $this->activityEvents()[$field] ?? null;
    }

    /**
     * The project owner, remembered for the deleted event.
     *
     * @return array<string, mixed>
     */
    protected function activityPropertiesBeforeDelete(): array
    {
        return ['employee_ids' => array_filter([Project::ownerEmployeeIdFor($this->activityAttribute('project_id'))])];
    }
}
