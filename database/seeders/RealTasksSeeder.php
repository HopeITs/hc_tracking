<?php

namespace Database\Seeders;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The organisation's real delivery tasks.
 *
 * Deliberately NOT wired into DatabaseSeeder::run() — this is real programme
 * data, not demo data every fresh dev/test database should get. Depends on
 * RealMilestonesSeeder having already run, since every task here sources
 * from a milestone (see convert_tasks_to_polymorphic_source — a milestone
 * wins over a proposal when both are known, and every row in the source
 * sheet gives both). Run it explicitly:
 *
 *     php artisan db:seed --class=RealTasksSeeder
 *
 * or with every other real seeder, in dependency order, via RealDataSeeder.
 *
 * The source sheet gives no assignee or creator per task (task_assignees is
 * a separate multi-assignee table with no source data here), so tasks are
 * seeded with no assignees and a random existing user as creator.
 *
 * `firstOrCreate` on `title` + `source_id` makes re-running it a no-op for
 * tasks already inserted.
 */
class RealTasksSeeder extends Seeder
{
    public function run(): void
    {
        $milestoneIds = Milestone::query()->pluck('id', 'name');
        $creatorId = User::query()->inRandomOrder()->value('id');

        foreach ($this->tasks() as $task) {
            $milestoneId = $milestoneIds->get($task['milestone']);

            if ($milestoneId === null) {
                throw new RuntimeException("Unknown milestone \"{$task['milestone']}\" — run RealMilestonesSeeder first.");
            }

            Task::query()->firstOrCreate(
                ['title' => $task['title'], 'source_type' => 'milestone', 'source_id' => $milestoneId],
                [
                    'description' => $task['description'],
                    'status' => $task['status'],
                    'priority' => $task['priority'],
                    'start_date' => $task['start_date'],
                    'due_date' => $task['due_date'],
                    'completed_date' => $task['completed_date'],
                    'progress_percentage' => $task['progress_percentage'],
                    'created_by' => $creatorId,
                ],
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function tasks(): array
    {
        return [
            ['milestone' => 'Trainee Cohort Selection & Needs Assessment', 'title' => 'Publish LEAP Cohort 2 application call and interview shortlisted candidates', 'description' => 'Announce youth leadership coaching cycle across local universities, screen 85 applicants, and select 26 participants.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'start_date' => '2024-02-01', 'due_date' => '2024-03-25', 'completed_date' => '2024-03-25', 'progress_percentage' => 100],
            ['milestone' => 'Core Leadership & Theoretical Modules Delivery', 'title' => 'Execute 12 foundational leadership, communication, and emotional intelligence modules', 'description' => 'Deliver interactive weekly classroom sessions and workshops facilitated by senior leadership coaches.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'start_date' => '2024-04-01', 'due_date' => '2024-10-20', 'completed_date' => '2024-10-20', 'progress_percentage' => 100],
            ['milestone' => 'Participant Community Initiative Design & Mentorship', 'title' => 'Mentor youth teams to formulate detailed community impact initiative proposals', 'description' => 'Coach 3 participant groups through community needs assessment, budgeting, and activity planning.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'start_date' => '2025-07-01', 'due_date' => '2025-12-20', 'completed_date' => '2025-12-20', 'progress_percentage' => 100],
            ['milestone' => 'Community Initiatives Launch & Final Evaluation', 'title' => 'Conduct public launch and community execution of Dakkakeen, Notice, and Memory initiatives', 'description' => 'Facilitate event logistics, public exhibition, and stakeholder coordination reaching 586 community members.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'start_date' => '2026-01-05', 'due_date' => '2026-03-30', 'completed_date' => '2026-03-30', 'progress_percentage' => 100],
            ['milestone' => 'Vulnerability Assessment & Beneficiary Verification', 'title' => 'Conduct field socioeconomic surveys across registered vulnerable families in Aleppo', 'description' => 'Deploy field enumerators to verify living standards, monthly income, displacement status, and chronic illness.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'start_date' => '2025-01-02', 'due_date' => '2025-01-25', 'completed_date' => '2025-01-25', 'progress_percentage' => 100],
            ['milestone' => 'Wave 1 Monthly Cash Distribution Cycle', 'title' => 'Execute monthly MPCA cash transfer tranches 1 through 6 for 3,200 households', 'description' => 'Disburse monthly cash assistance through contracted financial service providers with SMS verification.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'start_date' => '2025-02-01', 'due_date' => '2025-06-30', 'completed_date' => '2025-06-30', 'progress_percentage' => 100],
            ['milestone' => 'Mid-Term Post-Distribution Monitoring (PDM) & Re-validation', 'title' => 'Administer randomized PDM surveys measuring food expenditure and coping mechanisms', 'description' => 'Sample 350 recipient households to evaluate transfer utilization, dietary diversity, and protection outcomes.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'start_date' => '2025-11-01', 'due_date' => '2025-12-28', 'completed_date' => '2025-12-28', 'progress_percentage' => 100],
            ['milestone' => 'Business Plan Screening & Technical Scoring', 'title' => 'Review micro-grant applications and conduct technical on-site feasibility visits', 'description' => 'Technical committee evaluates 400 applicant business plans for financial viability, skill level, and market demand.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'start_date' => '2024-07-15', 'due_date' => '2024-09-25', 'completed_date' => '2024-09-25', 'progress_percentage' => 100],
            ['milestone' => 'Entrepreneurship & Financial Literacy Bootcamps', 'title' => 'Deliver 30-hour business management and financial bookkeeping training', 'description' => 'Train 250 selected entrepreneurs on cost accounting, marketing, inventory management, and risk mitigation.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'start_date' => '2024-10-01', 'due_date' => '2024-11-28', 'completed_date' => '2024-11-28', 'progress_percentage' => 100],
            ['milestone' => 'Asset Procurement & Grant Tranche Disbursement', 'title' => 'Procure production equipment, tools, and inventory kits for approved entrepreneurs', 'description' => 'Execute direct vendor purchases of workshop machines, solar power setups, and trade inventory.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'start_date' => '2024-12-01', 'due_date' => '2025-03-30', 'completed_date' => '2025-03-30', 'progress_percentage' => 100],
            ['milestone' => '6-Month Business Sustainability & Profitability Audit', 'title' => 'Conduct 6-month post-launch field audits on business revenue and job creation', 'description' => 'Visit funded micro-enterprises to verify ongoing commercial operations, net income, and sustained jobs.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'start_date' => '2026-01-10', 'due_date' => '2026-03-25', 'completed_date' => '2026-03-25', 'progress_percentage' => 100],
            ['milestone' => 'Labor Market Skills Assessment & Curriculum Modernization', 'title' => 'Survey local IT and industrial employers to align technical training curricula', 'description' => 'Interview 35 enterprise directors to identify hiring deficits in programming, graphics, and office administration.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'start_date' => '2024-07-01', 'due_date' => '2024-09-20', 'completed_date' => '2024-09-20', 'progress_percentage' => 100],
            ['milestone' => 'Cohort 1 Digital & Professional Training Execution', 'title' => 'Deliver 12-week intensive IT networking, graphic design, and data tracks', 'description' => 'Instruct 300 trainees in practical computer labs with accredited instructor mentoring and capstone testing.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'start_date' => '2024-10-01', 'due_date' => '2025-03-25', 'completed_date' => '2025-03-25', 'progress_percentage' => 100],
            ['milestone' => 'Job Placement Fair & Employer Matchmaking Forum', 'title' => 'Organize annual employment forum connecting graduates with private companies', 'description' => 'Facilitate on-site interviews, resume reviews, and internship contracts for 140 graduates.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'start_date' => '2026-02-01', 'due_date' => '2026-04-20', 'completed_date' => '2026-04-20', 'progress_percentage' => 100],
            ['milestone' => 'Solar Array Installation & High-Speed Connectivity Setup', 'title' => 'Procure and install 15kVA solar inverter system and enterprise fiber routers', 'description' => 'Equip center with sustainable backup power ensuring zero downtime during municipal electrical outages.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'start_date' => '2024-03-01', 'due_date' => '2024-04-25', 'completed_date' => '2024-04-25', 'progress_percentage' => 100],
            ['milestone' => 'Digital Resource Library & Mobile Seat Reservation Rollout', 'title' => 'Deploy automated seat booking portal and digital academic repository', 'description' => 'Launch online portal enabling 7,000 registered students to reserve study slots and access digital books.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'start_date' => '2025-06-01', 'due_date' => '2025-09-25', 'completed_date' => '2025-09-25', 'progress_percentage' => 100],
            ['milestone' => 'STEM & Creative Curriculum Formulation & Facilitator Training', 'title' => 'Develop interactive experiential science modules and train youth educators', 'description' => 'Formulate child-centered syllabus covering robotics, basic physics experiments, drawing, and theatre.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'start_date' => '2025-04-01', 'due_date' => '2025-05-20', 'completed_date' => '2025-05-20', 'progress_percentage' => 100],
            ['milestone' => 'Engineering Survey & Bill of Quantities (BOQ) Validation', 'title' => 'Conduct structural assessments and approve technical engineering BOQs for 5 schools', 'description' => 'Licensed civil engineers prepare detailed BOQs for masonry, roofing, electrical, and WASH repairs.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'start_date' => '2025-03-01', 'due_date' => '2025-04-20', 'completed_date' => '2025-04-20', 'progress_percentage' => 100],
            ['milestone' => 'Rapid Structural Safety Screenings & Homeowner Verification', 'title' => 'Screen earthquake damage and verify ownership documentation for 180 housing units', 'description' => 'Conduct structural integrity checks and verify property ownership records across residential districts.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'start_date' => '2025-04-01', 'due_date' => '2025-06-20', 'completed_date' => '2025-06-20', 'progress_percentage' => 100],
            ['milestone' => 'Plumbing, Sanitary Restoration & Family Re-habitation', 'title' => 'Finalize internal plumbing, sanitaries, and electrical installations for 180 families', 'description' => 'Complete interior renovations, test water infrastructure, and hand over safe homes to returning families.', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'start_date' => '2025-12-01', 'due_date' => '2026-03-20', 'completed_date' => '2026-03-20', 'progress_percentage' => 100],
        ];
    }
}
