<?php

namespace Database\Seeders;

use App\Enums\ArStatus;
use App\Enums\DeliverableKind;
use App\Enums\DeliveryState;
use App\Enums\FileType;
use App\Enums\IndicatorCollector;
use App\Enums\LineSchedule;
use App\Enums\MediaCollection;
use App\Enums\MilestoneStatus;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReportStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Enums\ReportTemplateStatus;
use App\Enums\ResultType;
use App\Enums\ReviewerSlot;
use App\Enums\ReviewStage;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\FundingAgreementInstallment;
use App\Models\HrDepartment;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\Media;
use App\Models\Milestone;
use App\Models\MonthlyReport;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\ProposalStatusNote;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\Result;
use App\Models\Risk;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Fills "Al-Wadi Training Center" and its GIZ proposal with fake data for
 * every section of the project page and the proposal it embeds: the
 * project's story fields, milestones, tasks, risks, monthly reports,
 * report template, targets and files; the proposal's reviewers, status
 * history, logical framework, indicators, targets, reports, plan
 * (milestones, media, finance and department tasks), documents, funding
 * agreement installments and tranches; and a short activity trail.
 *
 * Needs the project and proposal from RealProjectsSeeder and
 * RealProposalsSeeder, and HR-synced users. It creates no HR people and is
 * safe to run again: every row is looked up before it is written.
 *
 * Run: php artisan db:seed --class=AlWadiShowcaseSeeder
 */
class AlWadiShowcaseSeeder extends Seeder
{
    private const PROJECT_SLUG = 'al-wadi-training-center';

    private CarbonImmutable $today;

    /** @var Collection<int, User> */
    private Collection $users;

    public function run(): void
    {
        $project = Project::query()->where('slug', self::PROJECT_SLUG)->first();
        $proposal = $project?->proposals()->orderBy('proposals.id')->first();
        $this->users = User::query()->whereNotNull('employee_id')->orderBy('id')->limit(6)->get();

        if ($project === null || $proposal === null || $this->users->isEmpty()) {
            $this->command?->warn('Al-Wadi Training Center, its proposal or HR-synced users are missing: run RealDataSeeder and hr:sync-users first.');

            return;
        }

        $this->today = CarbonImmutable::today();

        Model::unguarded(fn () => Activity::withoutRecording(function () use ($project, $proposal): void {
            $this->fillProject($project);
            $this->seedProjectMilestones($project);
            $this->seedMonthlyReports($project);
            $this->seedRisks($project);
            $this->seedTasks('project', $project->id, [
                ['Book the hall for the graduation event', TaskKind::General, TaskStatus::Todo, TaskPriority::Medium, 20],
                ['Collect toolkit receipts from the supplier', TaskKind::General, TaskStatus::InProgress, TaskPriority::High, -3],
                ['Upload attendance sheets for cohort 2', TaskKind::General, TaskStatus::UnderReview, TaskPriority::Medium, -1],
                ['Print certificates for cohort 1', TaskKind::General, TaskStatus::Done, TaskPriority::Low, -30],
            ]);
            $this->seedFiles('project', $project->id, [
                ['Training curriculum.pdf', MediaCollection::Other],
                ['Attendance cohort 1.xlsx', MediaCollection::Evidence],
                ['Graduation photos.jpg', MediaCollection::Photo],
            ]);

            $outputs = $this->seedFramework($proposal);
            $targets = $this->seedTargets($project, $proposal, $outputs);
            $this->seedReports($targets);
            $this->seedReportTemplate($project, $targets);

            $this->seedReviewers($proposal);
            $this->seedStatusHistory($proposal);
            $this->seedProposalPlan($proposal, $outputs);
            $this->seedTasks('proposal', $proposal->id, [
                ['Photos of the tailoring class', TaskKind::MediaDeliverable, TaskStatus::UnderReview, TaskPriority::Medium, 10],
                ['Short video of the graduation event', TaskKind::MediaDeliverable, TaskStatus::Todo, TaskPriority::Medium, 75],
                ['Prepare the interim financial report for GIZ', TaskKind::Financial, TaskStatus::InProgress, TaskPriority::High, 20],
                ['Reconcile tranche 1 expenses', TaskKind::Financial, TaskStatus::Done, TaskPriority::Medium, -15],
                ['Order sewing machines for cohort 2', TaskKind::General, TaskStatus::Todo, TaskPriority::Medium, 12],
            ]);
            $this->seedFiles('proposal', $proposal->id, [
                ['Need assessment - rural Homs.pdf', MediaCollection::NeedAssessment],
                ['Concept note.pdf', MediaCollection::Proposal],
                ['Full proposal and budget.xlsx', MediaCollection::Proposal],
                ['Signed grant agreement.pdf', MediaCollection::Agreement],
                ['MOU with the municipality.pdf', MediaCollection::Mou],
            ]);
            $this->seedFunding($proposal);
        }));

        $this->seedActivity($proposal);
    }

    private function fillProject(Project $project): void
    {
        $project->forceFill([
            'start_date' => $project->start_date ?? $this->today->subMonths(7)->startOfMonth()->toDateString(),
            'end_date' => $project->end_date ?? $this->today->addMonths(5)->endOfMonth()->toDateString(),
            'description' => 'Al-Wadi runs vocational training for young people in rural Homs: tailoring, solar installation and mobile repair, each with a 3-month course, a toolkit on graduation and six months of business coaching. The centre follows every graduate until they earn a first income.',
            'target_beneficiaries' => 450,
            'target_men' => 95,
            'target_women' => 160,
            'target_boys' => 85,
            'target_girls' => 110,
        ])->saveQuietly();
    }

    /**
     * Seven weighted milestones: three reached, two ongoing, two planned.
     */
    private function seedProjectMilestones(Project $project): void
    {
        $plan = [
            ['Baseline survey and participant selection', -7, -6, MilestoneStatus::Reached, 10],
            ['Tailoring course, cohort 1', -6, -3, MilestoneStatus::Reached, 15],
            ['Solar installation course, cohort 1', -5, -2, MilestoneStatus::Reached, 15],
            ['Toolkit distribution to graduates', -2, -1, MilestoneStatus::Ongoing, 15],
            ['Mobile repair course, cohort 2', -1, 1, MilestoneStatus::Ongoing, 15],
            ['Business coaching round 1', 1, 3, MilestoneStatus::Planned, 15],
            ['Endline survey and graduation event', 3, 5, MilestoneStatus::Planned, 15],
        ];

        foreach ($plan as $index => [$name, $start, $end, $status, $weight]) {
            $reached = $status === MilestoneStatus::Reached;

            Milestone::query()->firstOrNew(['project_id' => $project->id, 'name' => $name])->forceFill([
                'description' => 'Showcase milestone.',
                'planned_start_date' => $this->today->addMonths($start)->toDateString(),
                'planned_end_date' => $this->today->addMonths($end)->toDateString(),
                'actual_start_date' => $reached ? $this->today->addMonths($start)->toDateString() : null,
                'actual_end_date' => $reached ? $this->today->addMonths($end)->subDays(4)->toDateString() : null,
                'status' => $status,
                'sequence' => $index + 1,
                'weight_percentage' => $weight,
                'responsible_user_id' => $this->user($index)->id,
            ])->saveQuietly();
        }
    }

    /**
     * Six monthly reports: four validated, one sent back, one waiting for MEAL.
     */
    private function seedMonthlyReports(Project $project): void
    {
        $user = $this->user(0);

        foreach ([6 => 'validated', 5 => 'validated', 4 => 'validated', 3 => 'validated', 2 => 'returned', 1 => 'submitted'] as $monthsAgo => $state) {
            $period = $this->today->startOfMonth()->subMonths($monthsAgo);

            MonthlyReport::query()->firstOrNew(['project_id' => $project->id, 'period' => $period->toDateString()])->forceFill([
                'status' => match ($state) {
                    'validated' => MonthlyReportStatus::Validated,
                    'submitted' => MonthlyReportStatus::Submitted,
                    default => MonthlyReportStatus::Draft,
                },
                'prepared_by' => $user->id,
                'submitted_at' => $state === 'returned' ? null : $period->endOfMonth(),
                'submitted_by' => $state === 'returned' ? null : $user->id,
                'validated_at' => $state === 'validated' ? $period->endOfMonth()->addDays(3) : null,
                'validated_by' => $state === 'validated' ? $user->id : null,
                'returned_at' => $state === 'returned' ? $period->endOfMonth()->addDays(2) : null,
                'returned_by' => $state === 'returned' ? $user->id : null,
                'return_reason' => $state === 'returned' ? 'Attendance figures for cohort 2 are missing.' : null,
            ])->saveQuietly();
        }
    }

    private function seedRisks(Project $project): void
    {
        foreach ([
            ['Fuel shortage stops the generator during classes', 3, 2, true, 'Solar backup installed in the main workshop.'],
            ['Trainees drop out for seasonal farm work', 2, 2, false, 'Evening classes during the harvest months.'],
            ['Toolkit prices rise after a currency drop', 2, 3, false, 'Fixed-price contract with two suppliers.'],
        ] as [$name, $likelihood, $severity, $happened, $solution]) {
            Risk::query()->firstOrNew(['project_id' => $project->id, 'name' => $name])->forceFill([
                'likelihood' => $likelihood,
                'severity' => $severity,
                'impact' => 'Classes or graduation would slip.',
                'happened' => $happened,
                'solution' => $solution,
            ])->saveQuietly();
        }
    }

    /**
     * Impact → outcome → three outputs → activities, on the proposal.
     *
     * @return list<Result> the three outputs, the outcome, the impact, then the three activities
     */
    private function seedFramework(Proposal $proposal): array
    {
        $node = fn (string $statement, ResultType $type, ?int $parent): Result => Result::query()->firstOrCreate(
            ['proposal_id' => $proposal->id, 'statement' => $statement],
            ['type' => $type, 'parent_id' => $parent],
        );

        $impact = $node('Young people in rural Homs earn a stable income.', ResultType::Impact, null);
        $outcome = $node('Graduates start a trade or find paid work within six months.', ResultType::Outcome, $impact->id);
        $outputs = [
            $node('Vocational courses delivered in three trades.', ResultType::Output, $outcome->id),
            $node('Graduates equipped with a starter toolkit.', ResultType::Output, $outcome->id),
            $node('Graduates coached to launch a small business.', ResultType::Output, $outcome->id),
        ];
        $activities = [
            $node('Run tailoring, solar and mobile-repair courses.', ResultType::Activity, $outputs[0]->id),
            $node('Procure and hand over toolkits at graduation.', ResultType::Activity, $outputs[1]->id),
            $node('Hold monthly coaching circles and market visits.', ResultType::Activity, $outputs[2]->id),
        ];

        return [...$outputs, $outcome, $impact, ...$activities];
    }

    /**
     * The proposal's targets on Al-Wadi, each indicator inside the framework.
     *
     * @param  list<Result>  $outputs
     * @return Collection<int, PlanIndicator>
     */
    private function seedTargets(Project $project, Proposal $proposal, array $outputs): Collection
    {
        $framework = Result::query()->proposalFramework($proposal->id)->pluck('id')->all();

        // Targets saved before the framework existed: give each its own indicator inside it.
        $saved = PlanIndicator::query()->where('source_type', 'proposal')->where('source_id', $proposal->id)->with('indicator')->get();

        foreach ($saved as $index => $target) {
            if ($target->indicator !== null && ! in_array($target->indicator->result_id, $framework, true)) {
                $copy = $target->indicator->replicate();
                $copy->result_id = $outputs[$index % 3]->id;
                $copy->saveQuietly();
                $target->forceFill(['indicator_id' => $copy->id, 'project_id' => $project->id])->saveQuietly();
            }
        }

        foreach ([
            ['Number of young people trained', 0, 450, 'numeric', 'quarterly'],
            ['Number of graduates receiving a toolkit', 1, 380, 'numeric', 'quarterly'],
            ['Percentage of graduates earning an income', 2, 60, 'percentage', 'annual'],
            ['Number of small businesses launched', 2, 90, 'numeric', 'annual'],
            ['Percentage of graduates still working after six months', 3, 55, 'percentage', 'annual'],
            ['Average monthly income of graduates (USD)', 4, 120, 'average', 'annual'],
            ['Number of training sessions held', 5, 360, 'numeric', 'quarterly'],
            ['Number of toolkits handed over', 6, 380, 'numeric', 'quarterly'],
            ['Number of coaching circles held', 7, 48, 'numeric', 'quarterly'],
        ] as [$name, $output, $target, $measure, $period]) {
            $indicator = Indicator::query()->firstOrCreate(
                ['name' => $name, 'result_id' => $outputs[$output]->id],
                ['measure' => $measure, 'baseline' => 0, 'target' => $target, 'frequency' => $period, 'project_id' => $project->id],
            );

            PlanIndicator::query()->firstOrCreate(
                ['source_type' => 'proposal', 'source_id' => $proposal->id, 'indicator_id' => $indicator->id, 'project_id' => $project->id],
                ['target' => $target, 'target_population' => $measure === 'numeric' ? $target : null, 'period' => $period],
            );

            IndicatorProgress::query()->firstOrCreate(
                ['indicator_id' => $indicator->id, 'date' => $this->today->subMonth()->endOfMonth()->toDateString()],
                ['value' => round($target * 0.45, 2), 'notes' => 'Showcase: mid-term reading', 'recorded_by' => $this->user(0)->id],
            );
        }

        return PlanIndicator::query()->where('source_type', 'proposal')->where('source_id', $proposal->id)->get();
    }

    /**
     * Two reports per target: one approved, one in review.
     *
     * @param  Collection<int, PlanIndicator>  $targets
     */
    private function seedReports(Collection $targets): void
    {
        foreach ($targets as $index => $target) {
            foreach ([[3, ReportStatus::Approved, 0.3], [1, ReportStatus::Reviewed, 0.55]] as [$monthsAgo, $status, $share]) {
                $period = $this->today->subMonths($monthsAgo)->startOfMonth();

                Report::query()->firstOrCreate(
                    ['plan_indicator_id' => $target->id, 'planned_date' => $period->endOfMonth()->toDateString()],
                    [
                        'user_id' => $this->user($index)->id,
                        'status' => $status,
                        'period_start' => $period->toDateString(),
                        'period_end' => $period->endOfMonth()->toDateString(),
                        'value' => round((float) $target->target * $share, 2),
                        'narrative_report_notes' => 'Courses ran as planned; attendance held steady.',
                        'key_achievements' => 'Cohort finished on time with high attendance.',
                        'challenges' => 'Power cuts shortened two afternoon sessions.',
                        'submit_date' => $period->endOfMonth()->addDays(3)->toDateString(),
                    ],
                );
            }
        }
    }

    /**
     * A confirmed monthly report template, one monthly line per target.
     *
     * @param  Collection<int, PlanIndicator>  $targets
     */
    private function seedReportTemplate(Project $project, Collection $targets): void
    {
        $confirmedBy = $this->user(1)->id;
        $template = ReportTemplate::query()->firstOrCreate(['project_id' => $project->id]);
        $template->forceFill(['status' => ReportTemplateStatus::Confirmed, 'confirmed_at' => $this->today->subMonths(6), 'confirmed_by' => $confirmedBy])->saveQuietly();

        foreach ($targets as $target) {
            ReportTemplateLine::query()->firstOrCreate(
                ['report_template_id' => $template->id, 'plan_indicator_id' => $target->id],
                [
                    'status' => ReportTemplateLineStatus::Confirmed,
                    'schedule' => LineSchedule::Monthly,
                    'entered_by' => IndicatorCollector::ProjectTeam,
                    'effective_from' => $this->today->subMonths(7)->startOfMonth()->toDateString(),
                    'effective_to' => $this->today->addMonths(5)->endOfMonth()->toDateString(),
                    'confirmed_at' => $this->today->subMonths(6),
                    'confirmed_by' => $confirmedBy,
                ],
            );
        }
    }

    private function seedReviewers(Proposal $proposal): void
    {
        foreach ([
            [ReviewStage::ConceptNote, ReviewerSlot::ProgramManager, true],
            [ReviewStage::ConceptNote, ReviewerSlot::MealManager, true],
            [ReviewStage::Proposal, ReviewerSlot::FinanceManager, true],
            [ReviewStage::Proposal, ReviewerSlot::Ceo, false],
        ] as $index => [$stage, $slot, $seen]) {
            // One seat per person per stage (unique key), so a small HR roster gives fewer reviewers.
            ProposalReviewer::query()->firstOrCreate(
                ['proposal_id' => $proposal->id, 'stage' => $stage, 'employee_id' => $this->user($index)->employee_id],
                [
                    'slot' => $slot,
                    'seen_at' => $seen ? $this->today->subMonths(10) : null,
                    'note' => $seen ? 'Budget lines look right; go ahead.' : null,
                    'added_by' => $this->user(0)->id,
                ],
            );
        }
    }

    private function seedStatusHistory(Proposal $proposal): void
    {
        foreach ([
            [ProposalStatus::ConceptNote, 'Concept note drafted with the livelihoods team.', 13],
            [ProposalStatus::ConceptNoteReview, 'Sent to the programme and MEAL managers for review.', 12],
            [ProposalStatus::ConceptNoteSubmitted, 'Concept note sent to GIZ.', 12],
            [ProposalStatus::ProposalDraft, 'GIZ asked for a full proposal; drafting started.', 11],
            [ProposalStatus::ProposalReview, 'Budget and logframe reviewed internally.', 10],
            [ProposalStatus::Submitted, 'Full proposal submitted to the donor portal.', 10],
            [ProposalStatus::Approved, 'GIZ approved the proposal; agreement signed.', 9],
        ] as [$status, $context, $monthsAgo]) {
            ProposalStatusNote::query()->firstOrCreate(
                ['proposal_id' => $proposal->id, 'status' => $status],
                ['context' => $context, 'date' => $this->today->subMonths($monthsAgo)->toDateString(), 'changed_by' => $this->user(0)->id],
            );
        }
    }

    /**
     * @param  list<Result>  $outputs
     */
    private function seedProposalPlan(Proposal $proposal, array $outputs): void
    {
        foreach ([
            ['Recruit trainers and select participants', -8, -7, MilestoneStatus::Reached, 20],
            ['Courses for cohort 1', -7, -3, MilestoneStatus::Reached, 30],
            ['Toolkits and coaching for cohort 1', -3, 1, MilestoneStatus::Ongoing, 25],
            ['Endline survey and final report', 2, 4, MilestoneStatus::Planned, 25],
        ] as $index => [$name, $start, $end, $status, $weight]) {
            Milestone::query()->firstOrNew(['proposal_id' => $proposal->id, 'name' => $name])->forceFill([
                'description' => 'Showcase milestone.',
                'planned_start_date' => $this->today->addMonths($start)->toDateString(),
                'planned_end_date' => $this->today->addMonths($end)->toDateString(),
                'status' => $status,
                'sequence' => $index + 1,
                'weight_percentage' => $weight,
                'responsible_user_id' => $this->user($index)->id,
                'result_id' => $outputs[$index % 3]->id,
            ])->saveQuietly();
        }
    }

    /**
     * @param  list<array{0: string, 1: TaskKind, 2: TaskStatus, 3: TaskPriority, 4: int}>  $tasks
     */
    private function seedTasks(string $sourceType, int $sourceId, array $tasks): void
    {
        $departmentId = HrDepartment::query()->value('id');

        foreach ($tasks as $index => [$title, $kind, $status, $priority, $dueIn]) {
            if (Task::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->where('title', $title)->exists()) {
                continue;
            }

            $isMedia = $kind === TaskKind::MediaDeliverable;
            $delivered = $isMedia && $status !== TaskStatus::Todo;
            $task = new Task;
            $task->forceFill([
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'department_id' => $kind === TaskKind::General && $sourceType === 'proposal' ? $departmentId : null,
                'kind' => $kind,
                'media_kinds' => $isMedia ? [str_contains($title, 'video') ? DeliverableKind::Video : DeliverableKind::Photos] : null,
                'delivery_state' => $isMedia ? ($delivered ? DeliveryState::Delivered : DeliveryState::Requested) : null,
                'requested_on' => $isMedia ? $this->today->subDays(20)->toDateString() : null,
                'delivered_on' => $delivered ? $this->today->subDays(2)->toDateString() : null,
                'title' => $title,
                'description' => 'Showcase task.',
                'status' => $status,
                'priority' => $priority,
                'start_date' => $this->today->addDays($dueIn - 14)->toDateString(),
                'due_date' => $this->today->addDays($dueIn)->toDateString(),
                'completed_date' => $status === TaskStatus::Done ? $this->today->addDays($dueIn)->toDateString() : null,
                'progress_percentage' => match ($status) {
                    TaskStatus::Done => 100,
                    TaskStatus::Todo => 0,
                    default => 50,
                },
                'created_by' => $this->user(0)->id,
            ])->saveQuietly();
            $task->taskAssignees()->create(['employee_id' => $this->user($index + 1)->employee_id]);
        }
    }

    /**
     * File records only: the bytes are not stored, so a download returns 404.
     *
     * @param  list<array{0: string, 1: MediaCollection}>  $files
     */
    private function seedFiles(string $mediableType, int $mediableId, array $files): void
    {
        foreach ($files as $index => [$fileName, $collection]) {
            $type = FileType::from(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)));

            Media::query()->firstOrCreate(
                ['mediable_type' => $mediableType, 'mediable_id' => $mediableId, 'original_name' => $fileName],
                [
                    'collection' => $collection,
                    'path' => 'media/showcase-'.$mediableType.'-'.$mediableId.'-'.$index.'.'.$type->value,
                    'mime_type' => match ($type) {
                        FileType::Pdf => 'application/pdf',
                        FileType::Docx => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        FileType::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        FileType::Jpg => 'image/jpeg',
                        FileType::Png => 'image/png',
                    },
                    'size' => 240_000 + $index * 180_000,
                    'type' => $type,
                    'uploaded_by' => $this->user($index)->id,
                    'uploaded_at' => $this->today->subDays(30 - $index),
                ],
            );
        }
    }

    /**
     * Three installments on each agreement and their tranches: one received,
     * one in transit, one upcoming.
     */
    private function seedFunding(Proposal $proposal): void
    {
        $accountId = Account::query()->value('id');

        foreach (DonorProposal::query()->where('proposal_id', $proposal->id)->get() as $agreement) {
            foreach ([[0.4, -4, 'received'], [0.35, -1, 'transit'], [0.25, 2, 'upcoming']] as $sequence => [$share, $monthsFromNow, $state]) {
                $amount = round((float) $agreement->budget * $share, 2);
                $scheduled = $this->today->addMonths($monthsFromNow)->toDateString();

                $installment = FundingAgreementInstallment::query()->firstOrCreate(
                    ['funding_agreement_id' => $agreement->id, 'sequence' => $sequence + 1],
                    ['amount' => $amount, 'currency' => $agreement->currency, 'scheduled_date' => $scheduled],
                );

                $received = $state === 'received' && $accountId !== null;
                $fees = $received ? round($amount * 0.01, 2) : null;

                TransfersTracking::query()->firstOrCreate(
                    ['donor_proposal_id' => $agreement->id, 'installment_id' => $installment->id],
                    // Columns left null keep their database default (fees, exchange rate…).
                    array_filter([
                        'account_id' => $received ? $accountId : null,
                        'payment_amount' => $amount,
                        'amount_transfered_currency' => $agreement->currency,
                        'scheduled_date' => $scheduled,
                        'transfer_date' => $state === 'upcoming' ? null : $this->today->addMonths($monthsFromNow)->addDays(2)->toDateString(),
                        'amount_received' => $received ? $amount : null,
                        'fees' => $fees,
                        'fees_currency' => $received ? $agreement->currency : null,
                        'net_amount_received' => $received ? $amount - $fees : null,
                        'amount_received_currency' => $received ? $agreement->currency : null,
                        'receive_date' => $received ? $this->today->addMonths($monthsFromNow)->addDays(5)->toDateString() : null,
                        'ar_status' => $received ? ArStatus::Received : ArStatus::Pending,
                        'source' => 'manual',
                    ], fn (mixed $value): bool => $value !== null),
                );
            }
        }
    }

    /**
     * A short trail on the proposal, written as plain rows (no notifications).
     */
    private function seedActivity(Proposal $proposal): void
    {
        foreach ([
            ['proposal.status_changed', ['status' => ['proposal review', 'submitted']], 300],
            ['proposal.approved', ['status' => ['submitted', 'approved']], 270],
        ] as [$event, $changes, $daysAgo]) {
            if (Activity::query()->where('subject_type', 'proposal')->where('subject_id', $proposal->id)->where('event', $event)->exists()) {
                continue;
            }

            $activity = new Activity;
            $activity->forceFill([
                'actor_id' => $this->user(0)->id,
                'subject_type' => 'proposal',
                'subject_id' => $proposal->id,
                'module' => 'proposals',
                'event' => $event,
                'changes' => $changes,
                'properties' => ['label' => $proposal->name, 'notify' => false, 'field' => 'status'],
                'created_at' => $this->today->subDays($daysAgo),
                'updated_at' => $this->today->subDays($daysAgo),
            ])->saveQuietly();
        }
    }

    private function user(int $index): User
    {
        return $this->users[$index % $this->users->count()];
    }
}
