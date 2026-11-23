<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TemplatePlacementStatus;
use App\Support\MealCoverage;
use App\Support\Reporting\ReportPlace;
use App\Support\Slots;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $proposal_id
 * @property int $city_id
 * @property int $program_id
 * @property int|null $center_id
 * @property bool $city_wide
 * @property TemplatePlacementStatus $status
 * @property int|null $report_template_id
 * @property-read Proposal $proposal
 * @property-read Program $program
 * @property-read HrCity|null $city
 */
class ReportTemplatePlacement extends Model
{
    public function place(): ReportPlace
    {
        return new ReportPlace($this->city_id, $this->program_id, $this->center_id, $this->city_wide);
    }

    /** @return BelongsTo<HrCity, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(HrCity::class, 'city_id');
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return BelongsTo<Proposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /** @return BelongsTo<ReportTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'report_template_id');
    }

    /** @param Builder<ReportTemplatePlacement> $query */
    public function scopeAtPlace(Builder $query, ReportPlace $place): void
    {
        $query->where('city_id', $place->cityId)->where('program_id', $place->programId)
            ->where('center_id', $place->centerId)->where('city_wide', $place->cityWide);
    }

    /** @param Builder<ReportTemplatePlacement> $query */
    public function scopeManageableBy(Builder $query, User $user): void
    {
        if ($user->cannot('report-templates.manage') || $user->employee_id === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($user->employee_id === Slots::holderOf('meal_manager')) {
            return;
        }

        MealCoverage::scopeCoveredBy($query, $user->employee_id);
    }

    protected function casts(): array
    {
        return [
            'status' => TemplatePlacementStatus::class,
            'city_wide' => 'boolean',
            'proposal_id' => 'integer',
            'city_id' => 'integer',
            'program_id' => 'integer',
            'center_id' => 'integer',
            'report_template_id' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
