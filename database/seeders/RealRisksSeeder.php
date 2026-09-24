<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Project;
use App\Models\Risk;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The organisation's real project risks.
 *
 * Deliberately NOT wired into DatabaseSeeder::run() — this is real
 * programme data, not demo data every fresh dev/test database should get.
 * Depends on RealProjectsSeeder having already run, since projects are
 * resolved by name rather than a hardcoded id (ids depend on insertion
 * order). Run it explicitly:
 *
 *     php artisan db:seed --class=RealRisksSeeder
 *
 * `firstOrCreate` on name + project_id makes re-running it a no-op for
 * risks already inserted. Runs with activity recording off, same as
 * DatabaseSeeder — see .ai/rules/database-seeders.md.
 */
class RealRisksSeeder extends Seeder
{
    public function run(): void
    {
        Activity::withoutRecording(function (): void {
            $projectIds = Project::query()->pluck('id', 'name');

            foreach ($this->risks() as $risk) {
                $projectId = $projectIds->get($risk['project']);

                if ($projectId === null) {
                    throw new RuntimeException("Unknown project \"{$risk['project']}\" — run RealProjectsSeeder first.");
                }

                Risk::query()->firstOrCreate(
                    ['name' => $risk['name'], 'project_id' => $projectId],
                    [
                        'impact' => $risk['impact'],
                        'happened' => $risk['happened'],
                        'solution' => $risk['solution'],
                    ],
                );
            }
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function risks(): array
    {
        return [
            [
                'project' => 'LEAP Aleppo',
                'impact' => 'high',
                'name' => 'Urban security escalations and checkpoints disrupting youth movement and in-person sessions',
                'happened' => true,
                'solution' => 'Adjusted session timings, adopted a condensed 3-day weekly module format, and arranged secure group transportation during tense periods.',
            ],
            [
                'project' => 'LEAP Aleppo',
                'impact' => 'medium',
                'name' => 'Heavy academic schedules and university examination conflicts reducing participant attendance',
                'happened' => true,
                'solution' => 'Coordinated flexible coaching schedules around university midterm and final exam calendars, providing catch-up mentorship sessions.',
            ],
            [
                'project' => 'LEAP Aleppo',
                'impact' => 'medium',
                'name' => 'Psychological distress and emotional burnout among youth due to protracted economic hardship',
                'happened' => true,
                'solution' => 'Integrated dedicated psychosocial support, spiritual accompaniment, and retreat days led by certified counselors into the curriculum.',
            ],
            [
                'project' => 'Family to Family Aleppo',
                'impact' => 'high',
                'name' => 'Rapid local currency depreciation and market inflation eroding purchasing power of cash grants',
                'happened' => true,
                'solution' => 'Conducted bi-monthly market price monitoring and adjusted cash transfer values in coordination with donor and cash working group standards.',
            ],
            [
                'project' => 'Family to Family Aleppo',
                'impact' => 'high',
                'name' => 'Banking liquidity bottlenecks and cash withdrawal delays at financial service providers',
                'happened' => true,
                'solution' => 'Diversified disbursement tranches across multiple verified local money transfer agents and staggered payout schedules.',
            ],
            [
                'project' => 'Micro Projects Aleppo',
                'impact' => 'high',
                'name' => 'Severe electricity outages disrupting small workshop and artisanal production',
                'happened' => true,
                'solution' => 'Budgeted solar power systems and generator co-financing within business equipment grants for energy-dependent micro-enterprises.',
            ],
            [
                'project' => 'Micro Projects Homs',
                'impact' => 'medium',
                'name' => 'Price spikes in raw materials and machinery imports between proposal budget and procurement',
                'happened' => true,
                'solution' => 'Fast-tracked procurement committees upon grant approval and encouraged local sourcing of equipment and inventory.',
            ],
            [
                'project' => 'Micro Projects Damascus',
                'impact' => 'medium',
                'name' => 'New business default or closure due to weak bookkeeping and marketing skills',
                'happened' => true,
                'solution' => 'Mandated 30 hours of pre-disbursement financial management training and assigned experienced business mentors for 6 months.',
            ],
            [
                'project' => 'Study Zone Aleppo',
                'impact' => 'high',
                'name' => 'Grid power failure and internet outages during university exam peaks',
                'happened' => true,
                'solution' => 'Installed heavy-duty solar backup system, dual generator sets, and redundant fiber internet connections.',
            ],
            [
                'project' => 'Study Zone Aleppo',
                'impact' => 'medium',
                'name' => 'Center capacity saturation and overcrowding during midterm and final exam weeks',
                'happened' => true,
                'solution' => 'Implemented an automated online seat booking system with timed shift rotations to maximize access for all registered students.',
            ],
            [
                'project' => 'Children University Aleppo',
                'impact' => 'high',
                'name' => 'Child fatigue, lack of transport, and harsh weather conditions affecting attendance',
                'happened' => true,
                'solution' => 'Scheduled activities during optimal daytime hours, provided seasonal transport stipends, and maintained warm, heated activity halls.',
            ],
            [
                'project' => 'School to School Aleppo',
                'impact' => 'high',
                'name' => 'Structural complications and hidden damage discovered during school wall rehabilitation',
                'happened' => true,
                'solution' => 'Engaged structural engineers for immediate on-site safety assessment and adapted BOQ contingency budgets.',
            ],
            [
                'project' => 'Houses & Facility Rehabilitation',
                'impact' => 'high',
                'name' => 'Delays in building materials delivery due to transport restrictions and supplier backlogs',
                'happened' => true,
                'solution' => 'Established framework agreements with multiple pre-vetted local construction suppliers and pre-stocked essential building materials.',
            ],
        ];
    }
}
