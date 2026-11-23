<?php

declare(strict_types=1);

namespace App\Support\Locations;

use App\Support\Reporting\ReportPlace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MealCoveragePlaceBackfill
{
    public function __construct(private ProjectSuccessor $projects) {}

    /** @return array{project_scopes: int, written: int, already_covered: int, unset_rows: list<int>, conflicts: array<string, list<int>>} */
    public function run(bool $apply = false): array
    {
        return DB::transaction(function () use ($apply): array {
            $legacy = DB::table('meal_coverages')->where('scope_type', 'project')->orderBy('id')->lockForUpdate()->get();
            $existing = DB::table('meal_coverages')->where('scope_type', 'place')->lockForUpdate()->get()->keyBy('scope_key');
            $places = [];
            $claims = [];
            $unset = [];

            foreach ($legacy as $coverage) {
                foreach ($this->projects->rowsFor((int) $coverage->project_id)->loadMissing('centers') as $row) {
                    if ($row->program_id === null || (! $row->city_wide && $row->centers->isEmpty())) {
                        $unset[$row->id] = $row->id;

                        continue;
                    }

                    $centers = $row->city_wide ? [null] : $row->centers->pluck('center_id')->all();
                    foreach ($centers as $center) {
                        $place = new ReportPlace($row->city_id, $row->program_id, $center === null ? null : (int) $center, $row->city_wide);
                        $key = 'place:'.$place->key();
                        $places[$key] ??= [$place, $coverage];
                        $claims[$key][(int) $coverage->employee_id][] = (int) $coverage->id;
                    }
                }
            }

            $conflicts = [];
            $pending = [];
            $covered = 0;
            foreach ($places as $key => [$place, $coverage]) {
                $current = $existing->get($key);
                $officers = $claims[$key];
                if ($current !== null) {
                    $officers[(int) $current->employee_id][] = (int) $current->id;
                }
                if (count($officers) > 1) {
                    $conflicts[$key] = array_values(array_unique(array_merge(...array_values($officers))));

                    continue;
                }
                if ($current !== null) {
                    $covered++;

                    continue;
                }

                $pending[] = [
                    'scope_type' => 'place',
                    'scope_key' => $key,
                    'city_id' => $place->cityId,
                    'program_id' => $place->programId,
                    'center_id' => $place->centerId,
                    'city_wide' => $place->cityWide,
                    'employee_id' => $coverage->employee_id,
                    'assigned_by' => $coverage->assigned_by,
                    'created_at' => $coverage->created_at,
                    'updated_at' => $coverage->updated_at,
                ];
            }

            if ($apply) {
                if ($conflicts !== []) {
                    throw new RuntimeException('Conflicting MEAL coverage assignments: '.json_encode($conflicts, JSON_THROW_ON_ERROR));
                }
                foreach ($pending as $attributes) {
                    DB::table('meal_coverages')->insert($attributes);
                }
                foreach (DB::table('meal_coverages')->where('scope_type', 'city')->whereNull('scope_key')->get(['id', 'city_id']) as $city) {
                    DB::table('meal_coverages')->where('id', $city->id)->update(['scope_key' => 'city:'.$city->city_id]);
                }
            }

            return [
                'project_scopes' => $legacy->count(),
                'written' => count($pending),
                'already_covered' => $covered,
                'unset_rows' => array_values($unset),
                'conflicts' => $conflicts,
            ];
        });
    }
}
