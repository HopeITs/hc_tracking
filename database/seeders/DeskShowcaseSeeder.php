<?php

namespace Database\Seeders;

use App\Enums\ArStatus;
use App\Enums\DeliveryState;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReviewerSlot;
use App\Enums\ReviewStage;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\FundingAgreementInstallment;
use App\Models\HrDepartment;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
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
use App\Models\TransfersTracking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

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

            $this->seedDecisionsAndWaiting($admins, $employees, $projects, $underReview, $today);
            $this->seedTransfers($today);
            $this->seedTargetsBehind($admins->first()?->id ?? $preparer, $today);

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

    /**
     * Per administrator: two projects they own (so their proposals' reviews
     * and deliverable requests wait on others), late tasks they gave others,
     * tasks sent for their review, and media / finance deliverables in every
     * delivery state.
     *
     * @param  Collection<int, User>  $admins
     * @param  list<int>  $employees
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, Proposal>  $underReview
     */
    private function seedDecisionsAndWaiting(Collection $admins, array $employees, Collection $projects, Collection $underReview, CarbonImmutable $today): void
    {
        if ($employees === [] || $projects->isEmpty()) {
            return;
        }

        foreach ($admins->values() as $n => $admin) {
            $owned = $projects->slice(6 + $n * 2, 2);
            Project::query()->whereKey($owned->modelKeys())->update(['user_id' => $admin->employee_id]);

            // Their proposal reviews still unread by colleagues.
            foreach ($owned as $i => $project) {
                $proposal = $underReview->get($i % max(1, $underReview->count()));

                if ($proposal === null) {
                    continue;
                }

                $project->proposals()->syncWithoutDetaching([$proposal->id]);
                ProposalReviewer::query()->firstOrCreate(
                    ['proposal_id' => $proposal->id, 'employee_id' => $employees[($i + 6) % count($employees)], 'stage' => $proposal->status === ReviewStage::ConceptNote->reviewStatus() ? ReviewStage::ConceptNote : ReviewStage::Proposal],
                    ['slot' => ReviewerSlot::Additional],
                );
            }

            $tasks = [
                ['Showcase: Collect the attendance sheets', TaskStatus::InProgress, -6, null, null],
                ['Showcase: Photograph the new classrooms', TaskStatus::Todo, -2, null, null],
                ['Showcase: Draft the beneficiary survey', TaskStatus::UnderReview, 1, null, null],
                ['Showcase: Update the distribution list', TaskStatus::UnderReview, -1, null, null],
                ['Showcase: Field visit video', TaskStatus::InProgress, 4, TaskKind::MediaDeliverable, DeliveryState::Requested],
                ['Showcase: Success story photos', TaskStatus::UnderReview, -1, TaskKind::MediaDeliverable, DeliveryState::Delivered],
                ['Showcase: Donor visit brochure', TaskStatus::InProgress, 9, TaskKind::MediaDeliverable, DeliveryState::Returned],
                ['Showcase: Budget variance note', TaskStatus::Todo, 6, TaskKind::Financial, null],
                ['Showcase: Bank statement reconciliation', TaskStatus::InProgress, -3, TaskKind::Financial, null],
            ];

            foreach ($tasks as $i => [$title, $status, $offset, $kind, $state]) {
                $title .= " ({$admin->id})";

                if (Task::query()->withoutGlobalScopes()->where('title', $title)->exists()) {
                    continue;
                }

                $project = $owned->values()->get($i % max(1, $owned->count())) ?? $projects->first();
                $milestone = Milestone::query()->where('project_id', $project->id)->value('id');
                $department = $kind === null ? null : HrDepartment::query()->where('name', $kind === TaskKind::Financial ? 'Finance' : 'Communication')->value('id');

                $task = Task::query()->create([
                    'title' => $title,
                    'description' => 'Seeded so Needs you, Waiting on others and Department deliverables have rows.',
                    'status' => $status,
                    'due_date' => $today->addDays($offset)->toDateString(),
                    'source_type' => $milestone === null || $kind === null ? 'project' : 'milestone',
                    'source_id' => $milestone === null || $kind === null ? $project->id : $milestone,
                    'kind' => $kind ?? TaskKind::General,
                    'department_id' => $department,
                    'delivery_state' => $state,
                    'requested_on' => $kind === null ? null : $today->subDays(10)->toDateString(),
                    'delivered_on' => $state === DeliveryState::Delivered ? $today->subDays(2)->toDateString() : null,
                    'returned_reason' => $state === DeliveryState::Returned ? 'Please use the new logo.' : null,
                    'created_by' => $admin->id,
                ]);
                $task->taskAssignees()->create(['employee_id' => $employees[$i % count($employees)]]);
            }
        }
    }

    /**
     * Pending tranches on existing agreements: two late, one in transit, two upcoming.
     */
    private function seedTransfers(CarbonImmutable $today): void
    {
        $agreements = DonorProposal::query()->orderBy('id')->limit(5)->get();

        foreach ($agreements as $i => $agreement) {
            [$scheduled, $sent] = [[-20, null], [-6, null], [-4, -2], [7, null], [25, null]][$i];
            $date = $today->addDays($scheduled)->toDateString();

            TransfersTracking::query()->firstOrCreate(
                ['donor_proposal_id' => $agreement->id, 'scheduled_date' => $date, 'source' => 'manual'],
                [
                    'payment_amount' => round(max(1000.0, (float) $agreement->budget * 0.2), 2),
                    'amount_transfered_currency' => $agreement->currency,
                    'transfer_date' => $sent === null ? null : $today->addDays($sent)->toDateString(),
                    'ar_status' => ArStatus::Pending,
                ],
            );
        }
    }

    /**
     * Four indicators recorded well below where they should be this year.
     */
    private function seedTargetsBehind(?int $recordedBy, CarbonImmutable $today): void
    {
        if ($recordedBy === null) {
            return;
        }

        $indicators = Indicator::query()->whereNotNull('target')->where('target', '>', 0)->orderBy('id')->limit(4)->get();

        foreach ($indicators as $i => $indicator) {
            IndicatorProgress::query()->firstOrCreate(
                ['indicator_id' => $indicator->id, 'date' => $today->subDays(3)->toDateString(), 'notes' => 'Showcase: behind pace'],
                ['value' => round((float) $indicator->target * [0.08, 0.15, 0.3, 0.45][$i], 2), 'recorded_by' => $recordedBy],
            );
        }
    }
}
