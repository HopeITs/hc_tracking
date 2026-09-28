<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Project;
use Database\Seeders\Concerns\PicksHrRecords;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The organisation's real project milestones.
 *
 * Deliberately NOT wired into DatabaseSeeder::run() — this is real
 * programme data, not demo data every fresh dev/test database should get.
 * Depends on RealProjectsSeeder having already run, since projects are
 * resolved by name rather than a hardcoded id (ids depend on insertion
 * order). Run it explicitly:
 *
 *     php artisan db:seed --class=RealMilestonesSeeder
 *
 * or with every other real seeder, in dependency order, via RealDataSeeder.
 *
 * The source data carries no responsible person per milestone, so each one
 * is assigned a random active HR employee, the same as DeliverySeeder does
 * for demo milestones. `firstOrCreate` on project_id + sequence makes
 * re-running it a no-op for milestones already inserted. Runs with
 * activity recording off, same as DatabaseSeeder — see
 * .ai/rules/database-seeders.md.
 */
class RealMilestonesSeeder extends Seeder
{
    use PicksHrRecords;

    public function run(): void
    {
        Activity::withoutRecording(function (): void {
            $projectIds = Project::query()->pluck('id', 'name');
            $employeeIds = $this->hrEmployeeIds();

            foreach ($this->milestones() as $milestone) {
                $projectId = $projectIds->get($milestone['project']);

                if ($projectId === null) {
                    throw new RuntimeException("Unknown project \"{$milestone['project']}\" — run RealProjectsSeeder first.");
                }

                Milestone::query()->firstOrCreate(
                    ['project_id' => $projectId, 'sequence' => $milestone['sequence']],
                    [
                        'name' => $milestone['name'],
                        'description' => $milestone['description'],
                        'planned_start_date' => $milestone['planned_start_date'],
                        'actual_start_date' => $milestone['actual_start_date'],
                        'status' => $milestone['status'],
                        'weight_percentage' => $milestone['weight_percentage'],
                        'responsible_user_id' => $employeeIds->random(),
                        'parent_milestone_id' => null,
                    ],
                );
            }
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function milestones(): array
    {
        return [
            [
                'project' => 'LEAP Aleppo',
                'name' => 'Trainee Cohort Selection & Needs Assessment',
                'description' => 'Interview, screen, and enroll 26 youth leaders for Cohort 2',
                'planned_start_date' => '2024-03-31',
                'actual_start_date' => '2024-03-25',
                'status' => 'reached',
                'sequence' => 1,
                'weight_percentage' => 15,
            ],
            [
                'project' => 'LEAP Aleppo',
                'name' => 'Core Leadership & Theoretical Modules Delivery',
                'description' => 'Complete foundational courses in leadership, communication, and emotional intelligence',
                'planned_start_date' => '2024-10-31',
                'actual_start_date' => '2024-10-20',
                'status' => 'reached',
                'sequence' => 2,
                'weight_percentage' => 25,
            ],
            [
                'project' => 'LEAP Aleppo',
                'name' => 'Specialized Thematic Workshops & Study Trips',
                'description' => 'Conduct deep-dive workshops, spiritual retreats, and community cultural excursions',
                'planned_start_date' => '2025-06-30',
                'actual_start_date' => '2025-06-28',
                'status' => 'reached',
                'sequence' => 3,
                'weight_percentage' => 20,
            ],
            [
                'project' => 'LEAP Aleppo',
                'name' => 'Participant Community Initiative Design & Mentorship',
                'description' => 'Trainee teams formulate and refine three detailed community action initiatives',
                'planned_start_date' => '2025-12-31',
                'actual_start_date' => '2025-12-20',
                'status' => 'reached',
                'sequence' => 4,
                'weight_percentage' => 20,
            ],
            [
                'project' => 'LEAP Aleppo',
                'name' => 'Community Initiatives Launch & Final Evaluation',
                'description' => 'Public launch of Dakkakeen, Notice, and Memory of the Eye initiatives with final graduation',
                'planned_start_date' => '2026-03-31',
                'actual_start_date' => '2026-03-30',
                'status' => 'reached',
                'sequence' => 5,
                'weight_percentage' => 20,
            ],
            [
                'project' => 'Family to Family Aleppo',
                'name' => 'Vulnerability Assessment & Beneficiary Verification',
                'description' => 'Comprehensive household visits and scoring of 3,200 vulnerable families',
                'planned_start_date' => '2025-01-31',
                'actual_start_date' => '2025-01-25',
                'status' => 'reached',
                'sequence' => 1,
                'weight_percentage' => 25,
            ],
            [
                'project' => 'Family to Family Aleppo',
                'name' => 'Wave 1 Monthly Cash Distribution Cycle',
                'description' => 'Execute first 6-month cycle of direct unconditional multi-purpose cash transfers',
                'planned_start_date' => '2025-06-30',
                'actual_start_date' => '2025-06-30',
                'status' => 'reached',
                'sequence' => 2,
                'weight_percentage' => 25,
            ],
            [
                'project' => 'Family to Family Aleppo',
                'name' => 'Mid-Term Post-Distribution Monitoring (PDM) & Re-validation',
                'description' => 'Survey recipient sample to assess food security, expenditure patterns, and transfer effectiveness',
                'planned_start_date' => '2025-12-31',
                'actual_start_date' => '2025-12-28',
                'status' => 'reached',
                'sequence' => 3,
                'weight_percentage' => 25,
            ],
            [
                'project' => 'Family to Family Aleppo',
                'name' => 'Wave 2 Monthly Cash Distribution & Final PDM',
                'description' => 'Complete second 6-month cash distribution cycle and publish final outcome evaluation',
                'planned_start_date' => '2026-06-30',
                'actual_start_date' => '2026-06-30',
                'status' => 'reached',
                'sequence' => 4,
                'weight_percentage' => 25,
            ],
            [
                'project' => 'Micro Projects Aleppo',
                'name' => 'Business Plan Screening & Technical Scoring',
                'description' => 'Evaluate applicant feasibility studies, financial viability, and market demand',
                'planned_start_date' => '2024-09-30',
                'actual_start_date' => '2024-09-25',
                'status' => 'reached',
                'sequence' => 1,
                'weight_percentage' => 20,
            ],
            [
                'project' => 'Micro Projects Aleppo',
                'name' => 'Entrepreneurship & Financial Literacy Bootcamps',
                'description' => 'Deliver mandatory 30-hour business management and accounting training to approved entrepreneurs',
                'planned_start_date' => '2024-11-30',
                'actual_start_date' => '2024-11-28',
                'status' => 'reached',
                'sequence' => 2,
                'weight_percentage' => 20,
            ],
            [
                'project' => 'Micro Projects Aleppo',
                'name' => 'Asset Procurement & Grant Tranche Disbursement',
                'description' => 'Procure production machinery, solar kits, and inventory directly for 250 micro-businesses',
                'planned_start_date' => '2025-03-31',
                'actual_start_date' => '2025-03-30',
                'status' => 'reached',
                'sequence' => 3,
                'weight_percentage' => 25,
            ],
            [
                'project' => 'Micro Projects Aleppo',
                'name' => 'Business Inauguration & Field Launch Verification',
                'description' => 'Conduct on-site inspections verifying active commercial operations and asset commissioning',
                'planned_start_date' => '2025-06-30',
                'actual_start_date' => '2025-06-25',
                'status' => 'reached',
                'sequence' => 4,
                'weight_percentage' => 20,
            ],
            [
                'project' => 'Micro Projects Aleppo',
                'name' => '6-Month Business Sustainability & Profitability Audit',
                'description' => 'Field audit measuring revenue retention, loan repayments (if revolving), and job creation',
                'planned_start_date' => '2026-03-31',
                'actual_start_date' => '2026-03-25',
                'status' => 'reached',
                'sequence' => 5,
                'weight_percentage' => 15,
            ],
            [
                'project' => 'Micro Projects Homs',
                'name' => 'Agricultural & Rural Small Enterprise Scoring',
                'description' => 'Screen and select rural small business and agro-production proposals',
                'planned_start_date' => '2024-10-31',
                'actual_start_date' => '2024-10-25',
                'status' => 'reached',
                'sequence' => 1,
                'weight_percentage' => 25,
            ],
            [
                'project' => 'Micro Projects Homs',
                'name' => 'Agricultural Equipment & Capital Asset Disbursement',
                'description' => 'Deliver irrigation pumps, tools, and seed capital to approved farmers and artisans',
                'planned_start_date' => '2025-04-30',
                'actual_start_date' => '2025-04-28',
                'status' => 'reached',
                'sequence' => 2,
                'weight_percentage' => 35,
            ],
            [
                'project' => 'Micro Projects Homs',
                'name' => 'Mid-term Agricultural Extension & Mentoring Support',
                'description' => 'Provide agronomy consultation and veterinary support to micro-grant recipients',
                'planned_start_date' => '2025-10-31',
                'actual_start_date' => '2025-10-30',
                'status' => 'reached',
                'sequence' => 3,
                'weight_percentage' => 25,
            ],
            [
                'project' => 'Micro Projects Homs',
                'name' => 'Annual Farm & Enterprise Income Review',
                'description' => 'Assess crop yield, small business turnover, and household economic resilience',
                'planned_start_date' => '2026-06-30',
                'actual_start_date' => '2026-06-28',
                'status' => 'reached',
                'sequence' => 4,
                'weight_percentage' => 15,
            ],
            [
                'project' => 'DISC Aleppo',
                'name' => 'Labor Market Skills Assessment & Curriculum Modernization',
                'description' => 'Survey private sector employers to map high-demand digital and vocational skills',
                'planned_start_date' => '2024-09-30',
                'actual_start_date' => '2024-09-20',
                'status' => 'reached',
                'sequence' => 1,
                'weight_percentage' => 20,
            ],
            [
                'project' => 'DISC Aleppo',
                'name' => 'Cohort 1 Digital & Professional Training Execution',
                'description' => 'Conduct intensive IT networking, graphic design, and business English tracks for 300 trainees',
                'planned_start_date' => '2025-03-31',
                'actual_start_date' => '2025-03-25',
                'status' => 'reached',
                'sequence' => 2,
                'weight_percentage' => 30,
            ],
            [
                'project' => 'DISC Aleppo',
                'name' => 'Cohort 2 Advanced Vocational & Freelancing Track',
                'description' => 'Deliver advanced programming, accounting software, and remote freelance bootcamps',
                'planned_start_date' => '2025-11-30',
                'actual_start_date' => '2025-11-25',
                'status' => 'reached',
                'sequence' => 3,
                'weight_percentage' => 30,
            ],
            [
                'project' => 'DISC Aleppo',
                'name' => 'Job Placement Fair & Employer Matchmaking Forum',
                'description' => 'Host annual job exhibition connecting 300 graduates with over 40 local enterprises',
                'planned_start_date' => '2026-04-30',
                'actual_start_date' => '2026-04-20',
                'status' => 'reached',
                'sequence' => 4,
                'weight_percentage' => 20,
            ],
            [
                'project' => 'Study Zone Aleppo',
                'name' => 'Solar Array Installation & High-Speed Connectivity Setup',
                'description' => 'Commission 15kVA solar inverter system and redundant fiber optic connections',
                'planned_start_date' => '2024-04-30',
                'actual_start_date' => '2024-04-25',
                'status' => 'reached',
                'sequence' => 1,
                'weight_percentage' => 35,
            ],
            [
                'project' => 'Study Zone Aleppo',
                'name' => 'Center Inauguration & Student Registration Wave 1',
                'description' => 'Open facility for daily student study, research, and quiet academic co-working',
                'planned_start_date' => '2024-06-30',
                'actual_start_date' => '2024-06-15',
                'status' => 'reached',
                'sequence' => 2,
                'weight_percentage' => 25,
            ],
            [
                'project' => 'Study Zone Aleppo',
                'name' => 'Examination Peak Operations & 24/7 Energy Continuity',
                'description' => 'Provide extended hours study facilities during university semester finals',
                'planned_start_date' => '2025-02-28',
                'actual_start_date' => '2025-02-20',
                'status' => 'reached',
                'sequence' => 3,
                'weight_percentage' => 20,
            ],
            [
                'project' => 'Study Zone Aleppo',
                'name' => 'Digital Resource Library & Mobile Seat Reservation Rollout',
                'description' => 'Launch digital desk booking app to manage daily student flow across all desks',
                'planned_start_date' => '2025-09-30',
                'actual_start_date' => '2025-09-25',
                'status' => 'reached',
                'sequence' => 4,
                'weight_percentage' => 20,
            ],
            [
                'project' => 'Children University Aleppo',
                'name' => 'STEM & Creative Curriculum Formulation & Facilitator Training',
                'description' => 'Design child-friendly interactive science and art modules and train educators',
                'planned_start_date' => '2025-05-31',
                'actual_start_date' => '2025-05-20',
                'status' => 'reached',
                'sequence' => 1,
                'weight_percentage' => 25,
            ],
            [
                'project' => 'Children University Aleppo',
                'name' => 'Cohort 1 Hands-On Science & Arts Implementation',
                'description' => 'Enroll and instruct 300 children across weekly experiential learning tracks',
                'planned_start_date' => '2025-09-30',
                'actual_start_date' => '2025-09-25',
                'status' => 'reached',
                'sequence' => 2,
                'weight_percentage' => 25,
            ],
            [
                'project' => 'Children University Aleppo',
                'name' => 'Parent Psychosocial Support & Child Emotional Health Workshops',
                'description' => 'Host monthly parental guidance sessions on trauma coping and positive parenting',
                'planned_start_date' => '2026-01-31',
                'actual_start_date' => '2026-01-28',
                'status' => 'reached',
                'sequence' => 3,
                'weight_percentage' => 25,
            ],
            [
                'project' => 'Children University Aleppo',
                'name' => 'Children Annual Science Fair & Art Exhibition',
                'description' => 'Organize public exhibition displaying child scientific models, paintings, and drama skits',
                'planned_start_date' => '2026-05-31',
                'actual_start_date' => '2026-05-25',
                'status' => 'reached',
                'sequence' => 4,
                'weight_percentage' => 25,
            ],
            [
                'project' => 'School to School Aleppo',
                'name' => 'Engineering Survey & Bill of Quantities (BOQ) Validation',
                'description' => 'Inspect 5 damaged schools and certify structural rehabilitation work plans',
                'planned_start_date' => '2025-04-30',
                'actual_start_date' => '2025-04-20',
                'status' => 'reached',
                'sequence' => 1,
                'weight_percentage' => 20,
            ],
            [
                'project' => 'School to School Aleppo',
                'name' => 'Classroom Masonry, Roof Insulation & Electrical Restoration',
                'description' => 'Execute civil repairs, window glazing, plastering, and LED lighting installation',
                'planned_start_date' => '2025-08-31',
                'actual_start_date' => '2025-08-25',
                'status' => 'reached',
                'sequence' => 2,
                'weight_percentage' => 30,
            ],
            [
                'project' => 'School to School Aleppo',
                'name' => 'Sanitary Facilities & Clean Water Network Overhaul',
                'description' => 'Rebuild school toilets, drinking fountains, and install overhead water storage tanks',
                'planned_start_date' => '2025-11-30',
                'actual_start_date' => '2025-11-20',
                'status' => 'reached',
                'sequence' => 3,
                'weight_percentage' => 30,
            ],
            [
                'project' => 'School to School Aleppo',
                'name' => 'School Recommissioning & Administrative Handover',
                'description' => 'Sign joint commissioning handover protocols with Directorate of Education',
                'planned_start_date' => '2026-03-31',
                'actual_start_date' => '2026-03-25',
                'status' => 'reached',
                'sequence' => 4,
                'weight_percentage' => 20,
            ],
            [
                'project' => 'Houses & Facility Rehabilitation',
                'name' => 'Rapid Structural Safety Screenings & Homeowner Verification',
                'description' => 'Assess 180 damaged residential properties for structural stability and ownership deeds',
                'planned_start_date' => '2025-06-30',
                'actual_start_date' => '2025-06-20',
                'status' => 'reached',
                'sequence' => 1,
                'weight_percentage' => 25,
            ],
            [
                'project' => 'Houses & Facility Rehabilitation',
                'name' => 'External Enclosure & Weatherproofing Rehabilitation',
                'description' => 'Install exterior doors, double-glazed windows, and repair damaged perimeter masonry',
                'planned_start_date' => '2025-11-30',
                'actual_start_date' => '2025-11-25',
                'status' => 'reached',
                'sequence' => 2,
                'weight_percentage' => 35,
            ],
            [
                'project' => 'Houses & Facility Rehabilitation',
                'name' => 'Plumbing, Sanitary Restoration & Family Re-habitation',
                'description' => 'Restore domestic water, wastewater piping, and complete handover for 180 households',
                'planned_start_date' => '2026-03-31',
                'actual_start_date' => '2026-03-20',
                'status' => 'reached',
                'sequence' => 3,
                'weight_percentage' => 40,
            ],
        ];
    }
}
