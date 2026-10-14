<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsActivity;
use App\Notifications\EmployeeRecipients;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\SupervisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supervision exception or addition on top of the HR direct manager
 * (contracts §10, D-13). Routing reads it through App\Support\SupervisionMap;
 * the only writers are App\Actions\Supervisions\{AddSupervision,RemoveSupervision}.
 * Supervision grants no permission and no visibility.
 *
 * @property int $id
 * @property int $supervisor_employee_id
 * @property int $supervisee_employee_id
 * @property int|null $created_by
 * @property int $pair_low
 * @property int $pair_high
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['supervisor_employee_id', 'supervisee_employee_id', 'created_by'])]
class Supervision extends Model implements TracksActivity
{
    /** @use HasFactory<SupervisionFactory> */
    use HasFactory, RecordsActivity;

    /**
     * The tracking user who added the row.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supervisor_employee_id' => 'integer',
            'supervisee_employee_id' => 'integer',
            'created_by' => 'integer',
            'pair_low' => 'integer',
            'pair_high' => 'integer',
        ];
    }

    public function activityModule(): string
    {
        return 'access-management';
    }

    public function activityProgramId(): ?int
    {
        return null;
    }

    /**
     * «Supervisor → Supervisee», in English like every stored label.
     */
    public function activityLabel(): string
    {
        $names = $this->activityNames();

        return ($names['supervisor']['en'] ?? '').' → '.($names['supervisee']['en'] ?? '');
    }

    /**
     * @return list<string>
     */
    public function activityEmployeeFields(): array
    {
        return ['supervisor_employee_id', 'supervisee_employee_id'];
    }

    /**
     * Rows are added and removed, never edited.
     *
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return [];
    }

    protected function activityCreatedEvent(): string
    {
        return $this->getMorphClass().'.added';
    }

    protected function activityDeletedEvent(): string
    {
        return $this->getMorphClass().'.removed';
    }

    /**
     * Both people's ids and both-language names, so either sentence can be rendered.
     *
     * @return array<string, mixed>
     */
    protected function activityProperties(string $event): array
    {
        return [
            'supervisor_employee_id' => (int) $this->activityAttribute('supervisor_employee_id'),
            'supervisee_employee_id' => (int) $this->activityAttribute('supervisee_employee_id'),
            ...$this->activityNames(),
        ];
    }

    /**
     * @return array{supervisor: array{en: string, ar: string}|null, supervisee: array{en: string, ar: string}|null}
     */
    private function activityNames(): array
    {
        $supervisorId = (int) $this->activityAttribute('supervisor_employee_id');
        $superviseeId = (int) $this->activityAttribute('supervisee_employee_id');
        $names = app(EmployeeRecipients::class)->names([$supervisorId, $superviseeId]);

        return ['supervisor' => $names[$supervisorId] ?? null, 'supervisee' => $names[$superviseeId] ?? null];
    }
}
