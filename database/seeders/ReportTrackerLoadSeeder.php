<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MonthlyReportStatus;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\HrCenter;
use App\Models\MonthlyReport;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Support\Reporting\ReportPlace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Loads the Report Tracker under pressure, on top of the data already there:
 * up to 30 places (one per HR center) with twelve months of monthly reports in
 * every state (validated, waiting for MEAL, returned, late and current drafts,
 * some places starting mid-year), and 60 donor reports with long titles spread
 * from two months overdue to five months ahead. A third of the places and
 * donor reports are the MEAL user's own (else the first user's), so "My reporting duties" fills up.
 *
 * Not part of DatabaseSeeder. Run it by hand on demo data only:
 *
 *     php artisan db:seed --class=ReportTrackerLoadSeeder
 *
 * Runs once: every record it makes hangs off a proposal named "Load · …", and
 * it does nothing when one exists. No activity or notification is recorded.
 */
class ReportTrackerLoadSeeder extends Seeder
{
    public const string Prefix = 'Load · ';

    private const int Places = 30;

    private const int DonorReports = 60;

    public function run(): void
    {
        if (Proposal::query()->where('name', 'like', self::Prefix.'%')->exists()) {
            $this->command?->info('The Report Tracker load is already seeded.');

            return;
        }

        $actor = User::query()->orderBy('id')->first();
        $programIds = Program::query()->pluck('id')->all();
        $donorIds = Donor::query()->pluck('id')->all();

        if ($actor === null || $programIds === [] || $donorIds === []) {
            $this->command?->warn('No user, program or donor to build on: nothing was seeded.');

            return;
        }

        $meal = User::query()->role('MEAL')->whereNotNull('employee_id')->first();
        $mine = $meal?->employee_id ?? $actor->employee_id;
        $submitter = User::query()->whereKeyNot($actor->id)->orderBy('id')->value('id') ?? $actor->id;
        $others = User::query()->whereNotNull('employee_id')->where('employee_id', '!=', $mine ?? 0)->limit(12)->pluck('employee_id')->all();
        $today = CarbonImmutable::today();

        mt_srand(2611);

        Model::unguarded(fn () => Activity::withoutRecording(fn () => DB::transaction(function () use ($actor, $programIds, $donorIds, $mine, $others, $today, $submitter): void {
            $proposals = $this->places($actor, $submitter, $programIds, $donorIds, $mine, $others, $today);
            $this->donorReports($proposals, $mine, $others, $today);
        })));
    }

    /**
     * @param  list<int>  $programIds
     * @param  list<int>  $donorIds
     * @param  list<int>  $others
     * @return list<Proposal>
     */
    private function places(User $actor, int $submitter, array $programIds, array $donorIds, ?int $mine, array $others, CarbonImmutable $today): array
    {
        $names = [
            'Psychosocial Support and Child Protection Services for Displaced Families',
            'Emergency Winterization and Shelter Rehabilitation',
            'Water, Sanitation & Hygiene in Collective Shelters',
            'Vocational Training and Livelihoods for Youth',
            'Education Continuity and School Rehabilitation',
            'Community Health Outreach',
        ];
        $proposals = [];

        foreach (HrCenter::query()->orderBy('id')->limit(self::Places)->get(['id', 'city_id']) as $index => $center) {
            $proposal = Proposal::factory()->running()->create([
                'name' => self::Prefix.$names[$index % count($names)].' '.($index + 1),
                'donor_id' => $donorIds[$index % count($donorIds)],
                'internal_project_number' => 'HC-LOAD-'.($index + 1),
                'planned_date_begin' => $today->subMonths(13)->startOfMonth()->toDateString(),
                'planned_date_end' => $today->addYear()->toDateString(),
            ]);
            $proposals[] = $proposal;

            $place = new ReportPlace((int) $center->city_id, $programIds[$index % count($programIds)], (int) $center->id, false);
            $responsible = $index % 3 === 0 ? $mine : ($others[$index % max(1, count($others))] ?? null);
            $location = ProposalLocation::factory()->create([
                'proposal_id' => $proposal->id,
                'city_id' => $place->cityId,
                'program_id' => $place->programId,
                'responsible_employee_id' => $responsible,
            ]);
            $location->centers()->create(['center_id' => $place->centerId]);

            $template = ReportTemplate::factory()->confirmed()->forPlace($place, $proposal)->create(['confirmed_by' => $actor->id]);
            $this->months($template, $submitter, $index, $today);
        }

        return $proposals;
    }

    /**
     * Twelve months up to this one; some places start mid-year.
     */
    private function months(ReportTemplate $template, int $submitter, int $index, CarbonImmutable $today): void
    {
        $start = $index % 6 === 5 ? 5 : 11;

        for ($back = $start; $back >= 0; $back--) {
            $period = $today->startOfMonth()->subMonths($back);
            $roll = mt_rand(1, 10);
            $status = match (true) {
                $back === 0 => MonthlyReportStatus::Draft,
                $index % 5 === 0 && $back <= 4 => MonthlyReportStatus::Draft,
                $roll <= 5 => MonthlyReportStatus::Validated,
                $roll <= 7 => MonthlyReportStatus::Submitted,
                default => MonthlyReportStatus::Draft,
            };

            MonthlyReport::query()->create([
                'project_id' => null,
                'report_template_id' => $template->id,
                'period' => $period->toDateString(),
                'status' => $status,
                'submitted_at' => $status === MonthlyReportStatus::Draft ? null : $period->endOfMonth()->addDays(3),
                'submitted_by' => $status === MonthlyReportStatus::Draft ? null : $submitter,
                'validated_at' => $status === MonthlyReportStatus::Validated ? $period->endOfMonth()->addDays(8) : null,
                'validated_by' => $status === MonthlyReportStatus::Validated ? $submitter : null,
                'returned_at' => $status === MonthlyReportStatus::Draft && $back > 0 && $roll === 10 ? $period->endOfMonth()->addDays(10) : null,
            ]);
        }
    }

    /**
     * @param  list<Proposal>  $proposals
     * @param  list<int>  $others
     */
    private function donorReports(array $proposals, ?int $mine, array $others, CarbonImmutable $today): void
    {
        $titles = [
            'Interim narrative report',
            'Interim financial report',
            'Quarterly narrative report',
            'Final narrative and financial report with annexes, beneficiary lists and photo documentation',
        ];

        for ($n = 0; $n < self::DonorReports; $n++) {
            $proposal = $proposals[$n % count($proposals)];
            $agreement = DonorProposal::query()->where('proposal_id', $proposal->id)->first()
                ?? DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'donor_id' => $proposal->donor_id]);
            $submitted = $n % 4 === 3;
            $due = $submitted ? $today->subDays(mt_rand(5, 300)) : $today->addDays(mt_rand(-60, 150));

            DonorReport::query()->create([
                'donor_proposal_id' => $agreement->id,
                'title' => $titles[$n % count($titles)].' '.($n + 1),
                'due_on' => $due->toDateString(),
                'submitted_on' => $submitted ? $due->toDateString() : null,
                'responsible_employee_id' => $n % 3 === 0 ? $mine : ($others[$n % max(1, count($others))] ?? null),
            ]);
        }
    }
}
