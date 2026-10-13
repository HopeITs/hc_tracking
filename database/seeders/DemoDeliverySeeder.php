<?php

namespace Database\Seeders;

use App\Enums\ProjectStatus;
use App\Models\AnnualPlan;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\HrProgram;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Strategy;
use Database\Seeders\Concerns\PicksHrRecords;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

/**
 * Demo delivery under the first four programs: annual plans, projects,
 * proposals and the links between them.
 *
 * Programs, their managers and strategies are not touched here: programs come
 * from HR (ProgrammeSeeder) and strategies from StrategySeeder. A program with
 * no HR manager gets no demo delivery (a stand-in manager is used only when
 * HR is empty, i.e. in tests).
 *
 * Depends on StrategySeeder and ProgrammeSeeder.
 */
class DemoDeliverySeeder extends Seeder
{
    use PicksHrRecords;

    /**
     * Seed the programme tables.
     */
    public function run(): void
    {
        $strategy = Strategy::query()->latest('id')->firstOrFail();
        $employeeIds = $this->hrEmployeeIds();
        $cityIds = $this->hrCityIds();
        $donors = Donor::query()->where('is_internal', false)->get();

        foreach ($this->programsToAssign() as $index => $program) {
            $name = $program->name;

            foreach ([$strategy->start_year, $strategy->start_year + 1] as $year) {
                AnnualPlan::factory()->create([
                    'program_id' => $program->id,
                    'manager_id' => $program->manager_id ?? $employeeIds[$index % $employeeIds->count()],
                    'year' => $year,
                ]);
            }

            // One active project and one closed one per program, so every
            // lifecycle filter in the UI has something on both sides of it.
            $projects = Project::factory(2)
                ->sequence(
                    ['status' => ProjectStatus::Active],
                    ['status' => ProjectStatus::Closed],
                )
                ->create([
                    'name' => fn (): string => $name.' Community Response '.fake()->unique()->numerify('###'),
                    'program_id' => $program->id,
                    'city_id' => fn (): int => $cityIds->random(),
                    'user_id' => fn (): int => $employeeIds->random(),
                ]);

            $proposal = Proposal::factory()->running()->create([
                'donor_id' => $donors->random()->id,
                'name' => $name.' Emergency Response',
                'internal_project_number' => 'HC-2026-'.(901 + $index),
                'planned_date_begin' => $strategy->start_year.'-01-01',
                'planned_date_end' => $strategy->start_year.'-12-31',
            ]);

            $proposal->projects()->attach($projects->pluck('id'));

            DonorProposal::factory()->create([
                'proposal_id' => $proposal->id,
                'external_project_number' => 'PRJ-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
            ]);
        }
    }

    /**
     * The mirrored HR programs to build demo delivery under.
     *
     * Only programs HR gives a manager are used, so no plan carries a manager
     * HR does not know. An empty HR database only happens in the test suite,
     * whose in-memory HR connection accepts the factory's HR insert.
     *
     * @return Collection<int, Program>
     */
    protected function programsToAssign(): Collection
    {
        if (HrProgram::query()->doesntExist()) {
            return Program::factory()->unassigned()->count(4)->sequence(
                ['name' => 'WASH'],
                ['name' => 'Shelter'],
                ['name' => 'Protection'],
                ['name' => 'Livelihoods'],
            )->create();
        }

        return Program::query()->whereNotNull('manager_id')->orderBy('id')->limit(4)->get();
    }
}
