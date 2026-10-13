<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Admin;

use App\Actions\Cities\AssignMealCoverage;
use App\Enums\MealCoverageScope;
use App\Models\HrCity;
use App\Models\HrEmployee;
use App\Models\MealCoverageAssignment;
use App\Models\Project;
use App\Support\Like;
use App\Support\MealCoverage;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * MEAL coverage on the cities page (contracts §12.3, D-12/D-12a): each
 * governorate's officer, the Project officers section, and the assign/change
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
 * @property-read Collection<int, array{id: int, name: string, city: string|null, officer: array{name: string, inactive: bool}|null, inherited: bool}> $projectOfficers  #[Computed] projectOfficers()
 * @property-read array{name: string, inactiveCity: bool}|null $mealTarget  #[Computed] mealTarget()
 */
trait ManagesMealCoverage
{
    /**
     * How many projects the Project officers section lists at once; the
     * search narrows it down.
     */
    private const ProjectOfficerLimit = 12;

    public string $projectSearch = '';

    public bool $showMealModal = false;

    /**
     * 'city' or 'project' — see {@see MealCoverageScope}.
     */
    public string $mealScope = 'city';

    public ?int $mealTargetId = null;

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
     * per city and per project).
     *
     * @return Collection<int, MealCoverageAssignment>
     */
    #[Computed]
    public function mealCoverages(): Collection
    {
        return MealCoverageAssignment::query()
            ->select(['id', 'scope_type', 'city_id', 'project_id', 'employee_id'])
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

    /**
     * The projects matching the project search, each with its own officer or
     * the one it inherits from its governorate (its center's city).
     *
     * @return Collection<int, array{id: int, name: string, city: string|null, officer: array{name: string, inactive: bool}|null, inherited: bool}>
     */
    #[Computed]
    public function projectOfficers(): Collection
    {
        if (! auth()->user()?->can('projects.view')) {
            return collect();
        }

        $search = trim($this->projectSearch);
        $projectOfficerIds = $this->mealCoverages->where('scope_type', MealCoverageScope::Project)->pluck('employee_id', 'project_id');
        $cityOfficerIds = $this->mealCoverages->where('scope_type', MealCoverageScope::City)->pluck('employee_id', 'city_id');
        $cities = $this->allCities->keyBy('id');

        return Project::query()
            ->select(['id', 'name', 'city_id', 'center_id'])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', Like::contains($search)))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::ProjectOfficerLimit)
            ->get()
            ->map(function (Project $project) use ($projectOfficerIds, $cityOfficerIds, $cities): array {
                $cityId = MealCoverage::cityIdOf($project);
                $ownOfficerId = $projectOfficerIds->get($project->id);

                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'city' => $cityId === null ? null : $cities->get($cityId)?->name,
                    'officer' => $this->officerCard($ownOfficerId ?? ($cityId === null ? null : $cityOfficerIds->get($cityId))),
                    'inherited' => $ownOfficerId === null,
                ];
            })
            ->values();
    }

    /**
     * The governorate or project the open dialog assigns an officer to.
     *
     * @return array{name: string, inactiveCity: bool}|null
     */
    #[Computed]
    public function mealTarget(): ?array
    {
        if ($this->mealTargetId === null) {
            return null;
        }

        if ($this->mealScope === MealCoverageScope::Project->value) {
            $name = Project::query()->whereKey($this->mealTargetId)->value('name');

            return $name === null ? null : ['name' => (string) $name, 'inactiveCity' => false];
        }

        $city = HrCity::query()->select(['id', 'name_en', 'name_ar', 'is_active'])->find($this->mealTargetId);

        return $city === null ? null : ['name' => $city->name, 'inactiveCity' => ! $city->is_active];
    }

    public function updatedProjectSearch(): void
    {
        unset($this->projectOfficers);
    }

    /**
     * Open the assign/change dialog for a governorate ('city') or a project,
     * preselecting its current officer.
     */
    public function openMealModal(string $scope, int $targetId): void
    {
        Gate::authorize('manage', MealCoverageAssignment::class);

        $coverageScope = MealCoverageScope::from($scope);
        $current = $this->mealCoverages->first(fn (MealCoverageAssignment $coverage): bool => $coverage->scope_type === $coverageScope
            && match ($coverageScope) {
                MealCoverageScope::City => $coverage->city_id === $targetId,
                MealCoverageScope::Project => $coverage->project_id === $targetId,
            });

        $this->resetErrorBag();
        $this->mealScope = $coverageScope->value;
        $this->mealTargetId = $targetId;
        $this->mealEmployeeId = $current === null ? null : (string) $current->employee_id;
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
            'mealTargetId' => ['required', 'integer'],
            'mealEmployeeId' => ['required', 'integer'],
        ], [
            'mealEmployeeId.required' => __('cities.choose_an_active_employee'),
            'mealEmployeeId.integer' => __('cities.choose_an_active_employee'),
        ]);

        $assignMealCoverage->handle(MealCoverageScope::from($this->mealScope), (int) $this->mealTargetId, (int) $this->mealEmployeeId);

        $this->closeMealModal();
        unset($this->mealCoverages, $this->mealOfficers, $this->cityOfficers, $this->projectOfficers, $this->mapCities);

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
