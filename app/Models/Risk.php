<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsActivity;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\RiskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string|null $impact
 * @property string $name
 * @property string|null $name_en
 * @property int|null $project_id
 * @property int|null $proposal_location_id
 * @property int|null $library_risk_id
 * @property int|null $proposal_id
 * @property int|null $result_id
 * @property int|null $program_id
 * @property int|null $copied_from_id
 * @property bool $happened
 * @property int|null $likelihood
 * @property int|null $severity
 * @property string|null $solution
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['impact', 'name', 'name_en', 'project_id', 'proposal_location_id', 'library_risk_id', 'proposal_id', 'result_id', 'program_id', 'copied_from_id', 'happened', 'likelihood', 'severity', 'solution'])]
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
     * The location row this register risk belongs to (Y-13, D-93). Null for a
     * logframe risk, which belongs to a proposal's result instead, and for a
     * register risk the backfill could not map (D-80).
     *
     * @return BelongsTo<ProposalLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ProposalLocation::class, 'proposal_location_id');
    }

    /**
     * The library entry this risk was picked from (W-09, D-61); null for a
     * legacy risk logged against a project.
     *
     * @return BelongsTo<RiskLibrary, $this>
     */
    public function libraryRisk(): BelongsTo
    {
        return $this->belongsTo(RiskLibrary::class, 'library_risk_id');
    }

    /**
     * The proposal whose logframe carries this risk.
     *
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * The program whose results framework carries this risk (null for a
     * proposal risk).
     *
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * The program risk this proposal risk was copied from; null when it was
     * typed on the proposal itself or the source was deleted.
     *
     * @return BelongsTo<Risk, $this>
     */
    public function copiedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'copied_from_id');
    }

    /**
     * The risks of one program's results framework.
     *
     * @param  Builder<Risk>  $query
     */
    #[Scope]
    protected function onProgram(Builder $query, int $programId): void
    {
        $query->where('program_id', $programId)->whereNull('proposal_id');
    }

    /**
     * The logframe risks of one proposal, leaving out its register (location) risks.
     *
     * @param  Builder<Risk>  $query
     */
    #[Scope]
    protected function onLogframeOf(Builder $query, int $proposalId): void
    {
        $query->where('proposal_id', $proposalId)->whereNull('proposal_location_id');
    }

    /**
     * Whether this proposal risk was copied from a program risk (remove only).
     */
    public function isCopied(): bool
    {
        return $this->copied_from_id !== null;
    }

    /**
     * The logframe result this risk sits on.
     *
     * @return BelongsTo<Result, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    /**
     * Whether this risk sits on a proposal's logframe rather than in a
     * place's register. Since Y-13 (D-93) a register risk carries its row's
     * proposal too, so the row is what tells the two apart: a logframe risk
     * belongs to a proposal and to no row.
     */
    public function isOnLogframe(): bool
    {
        return $this->proposal_location_id === null && $this->proposal_id !== null;
    }

    /**
     * The name in the current language; a risk with no English name (a legacy
     * row) shows its Arabic name everywhere.
     */
    public function localizedName(): string
    {
        return app()->getLocale() === 'ar' || blank($this->name_en) ? $this->name : $this->name_en;
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
        $programId = $this->activityAttribute('program_id');

        return $programId !== null ? (int) $programId : ProposalLocation::programIdFor($this->activityAttribute('proposal_location_id'));
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
     * The row's owner, remembered for the deleted event.
     *
     * @return array<string, mixed>
     */
    protected function activityPropertiesBeforeDelete(): array
    {
        return ['employee_ids' => array_filter([ProposalLocation::ownerEmployeeIdFor($this->activityAttribute('proposal_location_id'))])];
    }
}
