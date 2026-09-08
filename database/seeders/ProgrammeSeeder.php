<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Strategy;
use App\Models\User;
use App\Models\YearlyPlan;
use Illuminate\Database\Seeder;

/**
 * The programme layer: programs, annual plans, projects, proposals and the
 * links between them.
 *
 * Depends on CoreDataSeeder.
 */
class ProgrammeSeeder extends Seeder
{
    /**
     * Seed the programme tables.
     */
    public function run(): void
    {
        $strategy = Strategy::query()->latest('id')->firstOrFail();
        $managers = User::query()->inRandomOrder()->take(4)->get();
        $cities = City::query()->get();
        $donors = Donor::query()->get();

        foreach (['WASH', 'Shelter', 'Protection', 'Livelihoods'] as $index => $name) {
            $program = Program::factory()->create([
                'name' => $name,
                'strategy_id' => $strategy->id,
                'manager_id' => $managers[$index % $managers->count()]->id,
            ]);

            foreach ([$strategy->start_year, $strategy->start_year + 1] as $year) {
                YearlyPlan::factory()->create([
                    'program_id' => $program->id,
                    'manager_id' => $program->manager_id,
                    'year' => $year,
                ]);
            }

            $projects = Project::factory(2)->create([
                'program_id' => $program->id,
                'city_id' => fn (): int => $cities->random()->id,
                'user_id' => fn (): int => $managers->random()->id,
            ]);

            $proposal = Proposal::factory()->running()->create([
                'name' => $name.' Emergency Response',
            ]);

            $proposal->projects()->attach($projects->pluck('id'));

            DonorProposal::factory()->create([
                'donor_id' => $donors->random()->id,
                'proposal_id' => $proposal->id,
                'project_num' => 'PRJ-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
            ]);
        }
    }
}
