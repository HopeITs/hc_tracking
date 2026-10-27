<?php

namespace Database\Seeders;

use App\Enums\MonthlyReportStatus;
use App\Enums\ReviewerSlot;
use App\Enums\ReviewStage;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\FundingAgreementInstallment;
use App\Models\Milestone;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\ReportTemplate;
use App\Models\StrategicPillar;
use App\Models\Strategy;
use App\Models\Supervision;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Fills the parts of the Desk the demo data leaves empty, so every page and
 * panel has something to show: reviews waiting on each administrator, tasks
 * of theirs due today / this week / late, milestones due this week, monthly
 * reports waiting for validation, report templates, strategic pillars,
 * supervisions and agreement installments. It reuses existing projects,
 * proposals and HR employees (never creates HR people) and is safe to run
 * again: each block tops up to its target count.
 *
 * Run: php artisan db:seed --class=DeskShowcaseSeeder
 */
class DeskShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        Model::unguarded(fn () => Activity::withoutRecording(function (): void {
            $today = CarbonImmutable::today();
            $admins = User::query()->role(config('access-control.administrator_roles', []))->whereNotNull('employee_id')->get();
            $employees = User::query()->whereNotNull('employee_id')->whereNotIn('id', $admins->pluck('id'))->limit(12)->pluck('employee_id')->map(fn ($id): int => (int) $id)->all();
            $projects = Project::query()->limit(12)->get();
            $proposals = Proposal::query()->limit(8)->get();

            // Four proposals under review (two at concept note, two at proposal) so reviews have somewhere to wait.
            $donorIds = Donor::query()->orderBy('id')->limit(4)->pluck('id')->all();
            $underReview = collect(['Showcase: School meals pilot', 'Showcase: Youth skills hub', 'Showcase: Winter shelter repairs', 'Showcase: Mobile health clinic'])
                ->map(function (string $name, int $i) use ($donorIds, $today): Proposal {
                    $stage = $i < 2 ? ReviewStage::ConceptNote : ReviewStage::Proposal;

                    return Proposal::query()->firstOrCreate(['name' => $name], [
                        'donor_id' => $donorIds[$i % max(1, count($donorIds))] ?? null,
                        'internal_project_number' => 'HC-SHOW-'.($i + 1),
                        'planned_date_begin' => $today->addMonth()->toDateString(),
                        'planned_date_end' => $today->addMonths(13)->toDateString(),
                        'status' => $stage->reviewStatus(),
                    ]);
                });

            foreach ($admins as $admin) {
                $employeeId = (int) $admin->employee_id;

                // Reviews waiting on the administrator.
                foreach ($underReview as $i => $proposal) {
                    ProposalReviewer::query()->firstOrCreate(
                        ['proposal_id' => $proposal->id, 'employee_id' => $employeeId, 'stage' => $i < 2 ? ReviewStage::ConceptNote : ReviewStage::Proposal],
                        ['slot' => ReviewerSlot::Additional],
                    );
                }

                // Their own tasks: late, due today, this week, later.
                foreach ([-3, 0, 2, 5, 12] as $offset) {
                    $title = "Showcase task for {$admin->id} (".($offset < 0 ? 'late' : "+{$offset}d").')';

                    if (Task::query()->where('title', $title)->exists()) {
                        continue;
                    }

                    $task = Task::query()->create([
                        'description' => 'Seeded so the Desk has work due on every horizon.',
                        'title' => $title,
                        'status' => TaskStatus::Todo,
                        'due_date' => $today->addDays($offset)->toDateString(),
                        'source_type' => 'project',
                        'source_id' => $projects->random()->id,
                        'created_by' => $admin->id,
                    ]);
                    $task->taskAssignees()->create(['employee_id' => $employeeId]);
                }

                // People reporting to them, for My team.
                foreach (array_slice($employees, 0, 5) as $supervisee) {
                    Supervision::query()->firstOrCreate(['supervisor_employee_id' => $employeeId, 'supervisee_employee_id' => $supervisee]);
                }
            }

            // Milestones due this week.
            foreach ($projects->take(4) as $i => $project) {
                Milestone::query()->where('project_id', $project->id)->orderBy('id')->limit(1)
                    ->update(['planned_end_date' => $today->addDays($i + 1)->toDateString()]);
            }

            // Monthly reports waiting for validation, by people other than the administrators.
            $preparer = User::query()->whereNotIn('id', $admins->pluck('id'))->value('id');

            foreach ($projects->take(5) as $i => $project) {
                $period = $today->startOfMonth()->subMonths($i + 1)->toDateString();

                MonthlyReport::query()->firstOrCreate(
                    ['project_id' => $project->id, 'period' => $period],
                    ['status' => MonthlyReportStatus::Submitted, 'prepared_by' => $preparer, 'submitted_by' => $preparer, 'submitted_at' => now()->subDays($i + 1)],
                );
            }

            // Report templates for MEAL to confirm.
            foreach ($projects->take(3) as $project) {
                ReportTemplate::query()->firstOrCreate(['project_id' => $project->id]);
            }

            // Strategic pillars on every strategy.
            foreach (Strategy::query()->get() as $strategy) {
                foreach (['P1' => ['المحور الأول', 'Access to education'], 'P2' => ['المحور الثاني', 'Livelihoods'], 'P3' => ['المحور الثالث', 'Protection']] as $code => [$arabic, $english]) {
                    StrategicPillar::query()->firstOrCreate(['strategy_id' => $strategy->id, 'code' => $code], ['name_ar' => $arabic, 'name_en' => $english]);
                }
            }

            // Installments on agreements that have none.
            foreach (DonorProposal::query()->whereDoesntHave('installments')->limit(6)->get() as $agreement) {
                foreach ([0.4, 0.35, 0.25] as $n => $share) {
                    FundingAgreementInstallment::query()->create([
                        'funding_agreement_id' => $agreement->id,
                        'sequence' => $n + 1,
                        'amount' => round((float) $agreement->budget * $share, 2),
                        'currency' => $agreement->currency,
                        'scheduled_date' => $today->addMonths($n * 3 - 1)->toDateString(),
                    ]);
                }
            }
        }));
    }
}
