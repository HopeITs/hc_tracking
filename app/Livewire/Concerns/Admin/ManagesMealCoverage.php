<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Admin;

use App\Actions\Cities\AssignMealCoverage;
use App\Enums\MealCoverageScope;
use App\Models\HrCenter;
use App\Models\HrCity;
use App\Models\HrEmployee;
use App\Models\MealCoverageAssignment;
use App\Models\Program;
use App\Models\ProposalLocation;
use App\Models\ReportTemplate;
use App\Support\Reporting\ReportPlace;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

/**
 * MEAL coverage on the cities page (contracts §12.3, D-12/D-12a): each
 * governorate's officer, the place officers section, and the assign/change
 * dialog. Writes go through AssignMealCoverage, which authorizes itself; the
 * dialog actions also check MealCoverageAssignmentPolicy first.
 *
 * Used by admin/cities only. The page provides allCities() and mapCities().
 *
 * @property-read Collection<int, HrCity> $allCities  #[Computed] allCities()
 * @property-read array<int, array<string, mixed>> $mapCities  #[Computed] mapCities()
 * @property-read bool $canManageMealCoverage  #[Computed] canManageMealCoverage()
 * @property-read Collection<int, MealCoverageAssignment> $mealCoverages  #[Computed] mealCoverages()
 * @property-read Collection<int, HrEmployee> $mealOfficers  #[Computed] mealOfficers()
 * @property-read array<int, array{name: string, inactive: bool}> $cityOfficers  #[Computed] cityOfficers()
 * @property-read Collection<int, array{id: int, name: string, city: string|null, officer: array{name: string, inactive: bool}|null, inherited: bool, noGovernorate: bool}> $placeOfficers  #[Computed] placeOfficers()
 * @property-read array{name: string, inactiveCity: bool, officer: array{name: string, inactive: bool}|null}|null $mealTarget  #[Computed] mealTarget()
 * @property-read Collection<int, HrEmployee> $eligibleMealOfficers  #[Computed] eligibleMealOfficers()
 */
trait ManagesMealCoverage
{
    /**
     * How many projects the place officers section lists at once; the
     * search narrows it down.
     */
    private const ProjectOfficerLimit = 12;

    public string $projectSearch = '';

    public bool $showMealModal = false;

    /**
     * 'city' or 'place' — see {@see MealCoverageScope}.
     */
    public string $mealScope = 'city';

    public int|string|null $mealTargetId = null;

    public ?string $mealEmployeeId = null;

    /**
     * Whether this user may assign MEAL officers: meal-coverage.manage held by
     * the MEAL Manager slot holder (see MealCoverageAssignmentPolicy).
     */
    #[Computed]
    public function canManageMealCoverage(): bool
    {
        return Gate::allows('manage', MealCoverageAssignment::class);
    }

    /**
     * Every MEAL coverage row, in one query (the table holds at most one row
     * per governorate and per place).
     *
     * @return Collection<int, MealCoverageAssignment>
     */
    #[Computed]
    public function mealCoverages(): Collection
    {
        return MealCoverageAssignment::query()
            ->select(['id', 'scope_type', 'city_id', 'scope_key', 'employee_id'])
            ->get();
    }

    /**
     * The officers named on those rows, in one HR query, keyed by employee id.
     *
     * @return Collection<int, HrEmployee>
     */
    #[Computed]
    public function mealOfficers(): Collection
    {
        $employeeIds = $this->mealCoverages->pluck('employee_id')->unique()->values()->all();

        return $employeeIds === [] ? collect() : HrEmployee::query()
            ->select([...HrEmployee::NameColumns, 'is_active'])
            ->whereKey($employeeIds)
            ->get()
            ->keyBy('id');
    }

    /**
     * Each governorate's officer, keyed by city id.
     *
     * @return array<int, array{name: string, inactive: bool}>
     */
    #[Computed]
    public function cityOfficers(): array
    {
        $officers = [];

        foreach ($this->mealCoverages->where('scope_type', MealCoverageScope::City) as $coverage) {
            $officers[(int) $coverage->city_id] = $this->officerCardFor($coverage->employee_id);
        }

        return $officers;
    }

    /** @return Collection<string, ReportPlace> */
    #[Computed]
    public function coveragePlaces(): Collection
    {
        $places = ReportTemplate::query()->get()->map(fn (ReportTemplate $template) => $template->place());
        foreach (ProposalLocation::query()->whereNotNull('program_id')->with('centers')->get() as $row) {
            $centers = $row->city_wide ? [null] : $row->centers->pluck('center_id')->all();
            foreach ($centers as $center) {
                $places->push(new ReportPlace($row->city_id, $row->program_id, $center, $row->city_wide));
            }
        }

        return $places->reject(fn (ReportPlace $place): bool => $place->isUnset())->keyBy(fn (ReportPlace $place): string => $place->key());
    }

    #[Computed]
    public function placeOfficers(): Collection
    {
        $places = $this->coveragePlaces;
        $programs = Program::namesById();
        $centers = HrCenter::query()->whereKey($places->pluck('centerId')->filter()->unique()->all())->get()->keyBy('id');
        $cities = $this->allCities->keyBy('id');
        $direct = $this->mealCoverages->where('scope_type', MealCoverageScope::Place)->keyBy('scope_key');
        $governorate = $this->mealCoverages->where('scope_type', MealCoverageScope::City)->keyBy('city_id');
        $search = trim($this->projectSearch);

        return $places->map(function (ReportPlace $place) use ($programs, $centers, $cities, $direct, $governorate): array {
            $assignment = $direct->get('place:'.$place->key());
            $fallback = $governorate->get($place->cityId);
            $site = $place->cityWide ? __('cities.meal_field_work') : ($centers->get($place->centerId)?->name ?? '#'.$place->centerId);

            return [
                'id' => $place->key(),
                'name' => ($programs[$place->programId] ?? '#'.$place->programId).' · '.$site,
                'city' => $cities->get($place->cityId)?->name,
                'officer' => $this->officerCard(($assignment ?? $fallback)?->employee_id),
                'inherited' => $assignment === null && $fallback !== null,
                'noGovernorate' => $assignment === null && ! $cities->has($place->cityId),
            ];
        })->filter(fn (array $row): bool => $search === '' || mb_stripos($row['name'].' '.$row['city'], $search) !== false)
            ->sortBy(['city', 'name']);
    }

    /**
     * The governorate or place the open dialog assigns an officer to.
     *
     * @return array{name: string, inactiveCity: bool, officer: array{name: string, inactive: bool}|null}|null
     */
    #[Computed]
    public function mealTarget(): ?array
    {
        if ($this->mealTargetId === null) {
            return null;
        }

        if ($this->mealScope === MealCoverageScope::Place->value) {
            $place = $this->placeOfficers->get($this->mealTargetId);

            return $place === null ? null : [
                'name' => $place['name'],
                'inactiveCity' => false,
                'officer' => $place['inherited'] ? null : $place['officer'],
            ];
        }

        $city = HrCity::query()->select(['id', 'name_en', 'name_ar', 'is_active'])->find($this->mealTargetId);

        return $city === null ? null : [
            'name' => $city->name,
            'inactiveCity' => ! $city->is_active,
            'officer' => $this->cityOfficers[$city->id] ?? null,
        ];
    }

    /**
     * @return Collection<int, HrEmployee>
     */
    #[Computed]
    public function eligibleMealOfficers(): Collection
    {
        return HrEmployee::query()
            ->where('is_active', true)
            ->get(HrEmployee::NameColumns)
            ->sortBy('name');
    }

    public function updatedProjectSearch(): void
    {
        unset($this->placeOfficers);
    }

    /**
     * Open the assign/change dialog for a governorate or reporting place,
     * preselecting its current officer.
     */
    public function openMealModal(string $scope, int|string $targetId): void
    {
        Gate::authorize('manage', MealCoverageAssignment::class);

        $coverageScope = MealCoverageScope::from($scope);
        $current = $this->mealCoverages->first(fn (MealCoverageAssignment $coverage): bool => $coverage->scope_type === $coverageScope
            && match ($coverageScope) {
                MealCoverageScope::City => $coverage->city_id === (int) $targetId,
                MealCoverageScope::Place => $coverage->scope_key === 'place:'.$targetId,
                default => false,
            });

        $this->resetErrorBag();
        $this->mealScope = $coverageScope->value;
        $this->mealTargetId = $targetId;
        $this->mealEmployeeId = $current !== null && $this->mealOfficers->get($current->employee_id)?->is_active
            ? (string) $current->employee_id
            : null;
        $this->showMealModal = true;
    }

    public function closeMealModal(): void
    {
        $this->showMealModal = false;
        $this->reset('mealTargetId', 'mealEmployeeId');
        $this->resetErrorBag();
    }

    public function saveMealCoverage(AssignMealCoverage $assignMealCoverage): void
    {
        Gate::authorize('manage', MealCoverageAssignment::class);

        $this->validate([
            'mealScope' => ['required', Rule::enum(MealCoverageScope::class)],
            'mealTargetId' => ['required'],
            'mealEmployeeId' => ['required', 'integer'],
        ], [
            'mealEmployeeId.required' => __('cities.choose_an_active_employee'),
            'mealEmployeeId.integer' => __('cities.choose_an_active_employee'),
        ]);

        $scope = MealCoverageScope::from($this->mealScope);
        $target = (int) $this->mealTargetId;
        if ($scope === MealCoverageScope::Place) {
            $place = $this->coveragePlaces->get($this->mealTargetId);
            if ($place === null) {
                throw ValidationException::withMessages(['mealTargetId' => __('cities.meal_place_invalid')]);
            }
            $target = ['city_id' => $place->cityId, 'program_id' => $place->programId, 'center_id' => $place->centerId, 'city_wide' => $place->cityWide];
        }
        $assignMealCoverage->handle($scope, $target, (int) $this->mealEmployeeId);

        $this->closeMealModal();
        unset($this->mealCoverages, $this->mealOfficers, $this->cityOfficers, $this->placeOfficers, $this->mapCities);

        Flux::toast(variant: 'success', text: __('cities.meal_officer_saved'));
    }

    /**
     * An officer as the page shows them; someone HR no longer lists counts as
     * inactive.
     *
     * @return array{name: string, inactive: bool}|null
     */
    private function officerCard(?int $employeeId): ?array
    {
        return $employeeId === null ? null : $this->officerCardFor($employeeId);
    }

    /**
     * @return array{name: string, inactive: bool}
     */
    private function officerCardFor(int $employeeId): array
    {
        $employee = $this->mealOfficers->get($employeeId);

        if ($employee === null) {
            return ['name' => '#'.$employeeId, 'inactive' => true];
        }

        return ['name' => $employee->name, 'inactive' => ! $employee->is_active];
    }
}
