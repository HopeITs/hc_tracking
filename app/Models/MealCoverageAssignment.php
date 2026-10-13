<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsActivity;
use App\Enums\MealCoverageScope;
use App\Notifications\EmployeeRecipients;
use App\Notifications\TracksActivity;
use App\Support\MealCoverage;
use Carbon\CarbonImmutable;
use Database\Factories\MealCoverageAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The MEAL officer of one governorate (HR city) or of one project
 * (contracts §12.3). Routing reads it through App\Support\MealCoverage; the
 * only writer is App\Actions\Cities\AssignMealCoverage.
 *
 * @property int $id
 * @property MealCoverageScope $scope_type
 * @property int|null $city_id
 * @property int|null $project_id
 * @property int $employee_id
 * @property int|null $assigned_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['scope_type', 'city_id', 'project_id', 'employee_id', 'assigned_by'])]
class MealCoverageAssignment extends Model implements TracksActivity
{
    /** @use HasFactory<MealCoverageAssignmentFactory> */
    use HasFactory, RecordsActivity;

    protected $table = 'meal_coverages';

    /**
     * The project this row covers, when scoped to a project.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The tracking user who made the assignment.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => MealCoverageScope::class,
            'city_id' => 'integer',
            'project_id' => 'integer',
            'employee_id' => 'integer',
            'assigned_by' => 'integer',
        ];
    }

    public function activityModule(): string
    {
        return 'cities';
    }

    public function activityLabel(): string
    {
        if ($this->coversProject()) {
            return (string) Project::query()->whereKey($this->activityAttribute('project_id'))->value('name');
        }

        return trim((string) HrCity::query()->whereKey($this->activityAttribute('city_id'))->value('name_en'));
    }

    public function activityProgramId(): ?int
    {
        return $this->coversProject() ? Project::programIdFor($this->activityAttribute('project_id')) : null;
    }

    /**
     * @return list<string>
     */
    public function activityEmployeeFields(): array
    {
        return ['employee_id'];
    }

    /**
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return ['employee_id' => 'responsible_changed'];
    }

    /**
     * A new officer on a project is always "project_assigned" (D-12a).
     */
    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        if ($field !== 'employee_id') {
            return null;
        }

        return $this->coversProject() ? 'project_assigned' : 'responsible_changed';
    }

    protected function activityCreatedEvent(): string
    {
        return $this->getMorphClass().($this->coversProject() ? '.project_assigned' : '.assigned');
    }

    /**
     * The new officer's name in both languages, and for a project the
     * governorate officer it overrides (who is told about it).
     *
     * @return array<string, mixed>
     */
    protected function activityProperties(string $event): array
    {
        $employeeId = (int) $this->activityAttribute('employee_id');
        $properties = ['employee_id' => $employeeId, 'employee' => app(EmployeeRecipients::class)->names([$employeeId])[$employeeId] ?? null];

        if ($this->coversProject()) {
            $project = Project::query()->select(['id', 'city_id', 'center_id'])->whereKey($this->activityAttribute('project_id'))->first();
            $cityId = $project === null ? null : MealCoverage::cityIdOf($project);
            $properties['governorate_officer_id'] = $cityId === null ? null : MealCoverage::officerOfCity($cityId);
        }

        return $properties;
    }

    private function coversProject(): bool
    {
        return $this->activityAttribute('scope_type') === MealCoverageScope::Project;
    }
}
