<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Enums\ProjectStatus;
use App\Models\Indicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Result;
use App\Models\Role;
use App\Models\User;
use App\Notifications\EmployeeRecipients;
use App\Notifications\EventCatalog;
use App\Notifications\MonthlyDigestMail;
use App\Notifications\NotificationPayload;
use App\Support\MealCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The monthly digest (reports:monthly-digest): on the first working day of a
 * month, one summary of the month before per person. Program managers see
 * their programs; General Management, the MEAL Manager slot holder and the
 * Public Relations Manager see every program. A section shows only with its
 * module's view permission, and nothing carries an amount.
 *
 * In-app and mail are claimed under separate ledger rules, so each channel is
 * idempotent on its own and a retried mail never repeats the in-app part.
 *
 * Waiting on their owning tasks: monthly reports (T17) and overdue
 * department tasks (T31).
 *
 * @phpstan-type Sections array{projects?: array{started: int, ended: int}, indicators?: array{off_track: int}, donor_reports?: array{due: int, overdue: int}}
 * @phpstan-type Fact array{id: int, program_id: int|null, bucket: string}
 * @phpstan-type Digest array{user: User, all_programs: bool, program_ids: list<int>, sections: Sections}
 */
final class MonthlyDigestScanner implements Scanner
{
    public const Rule = 'monthly_digest';

    public const MailRule = 'monthly_digest_mail';

    /**
     * Each section, its count lines (in order) and the permission that shows it.
     */
    private const Sections = [
        'projects' => ['lines' => ['started', 'ended'], 'permission' => 'projects.view'],
        'indicators' => ['lines' => ['off_track'], 'permission' => 'indicators.view'],
        'donor_reports' => ['lines' => ['due', 'overdue'], 'permission' => 'funding-agreements.view'],
    ];

    /**
     * Roles that see every program.
     */
    private const OrganisationRoles = ['General Manager', 'Public Relations Manager'];

    public function __construct(
        private WorkingCalendar $calendar,
        private AlertDispatcher $dispatcher,
        private AlertLedger $ledger,
        private EmployeeRecipients $employees,
        private OffTrackIndicatorScanner $offTrack,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $today = $now->startOfDay();

        if (! $this->calendar->firstWorkingDayOnOrAfter($today->startOfMonth())->isSameDay($today)) {
            return 0;
        }

        return $this->send($today->startOfMonth()->subMonth());
    }

    /**
     * Send the digest of one month to everyone who should get it. Returns how
     * many in-app digests went out.
     */
    public function send(CarbonImmutable $month): int
    {
        $stage = $month->format('Y-m');
        $sent = 0;

        foreach ($this->preview($month) as $digest) {
            $user = $digest['user'];
            $sent += $this->dispatcher->send(self::Rule, $stage, $this->payload($user, $stage, $digest['sections']), [$user->id], [], [$user->id]);

            if ($user->email !== '' && $this->ledger->claim(self::MailRule, 'user', $user->id, $stage, $user->id)) {
                $user->notify(new MonthlyDigestMail($stage, $digest['all_programs'], $digest['sections']));
            }
        }

        return $sent;
    }

    /**
     * What each recipient would get for the month, without sending or claiming anything.
     *
     * @return list<Digest>
     */
    public function preview(CarbonImmutable $month): array
    {
        $month = $month->startOfMonth();
        $scopes = $this->recipientScopes();

        if ($scopes === []) {
            return [];
        }

        $facts = [
            'projects' => $this->projectFacts($month),
            'indicators' => $this->indicatorFacts($month),
            'donor_reports' => $this->donorReportFacts($month),
        ];
        $digests = [];

        foreach (User::query()->with(['roles', 'permissions'])->whereIn('id', array_keys($scopes))->orderBy('id')->get() as $user) {
            $scope = $scopes[$user->id];
            $sections = [];

            foreach (self::Sections as $section => $definition) {
                if ($user->can($definition['permission'])) {
                    $sections[$section] = $this->count($facts[$section], $definition['lines'], $scope);
                }
            }

            /** @var Sections $sections */
            $digests[] = [
                'user' => $user,
                'all_programs' => $scope === null,
                'program_ids' => $scope ?? [],
                'sections' => $sections,
            ];
        }

        return $digests;
    }

    /**
     * user id => null (every program) or the program ids they manage. Several
     * roles widen to every program; Super Admin is never a recipient (D-20a).
     *
     * @return array<int, list<int>|null>
     */
    private function recipientScopes(): array
    {
        $scopes = [];
        $programs = Program::query()->whereNotNull('manager_id')->get(['id', 'manager_id']);
        $managers = $this->employees->usersFor(array_values($programs->pluck('manager_id')->map(fn (mixed $id): int => (int) $id)->unique()->all()));

        foreach ($programs as $program) {
            $userId = $managers[(int) $program->manager_id] ?? null;

            if ($userId !== null) {
                $scopes[$userId] ??= [];
                $scopes[$userId][] = $program->id;
            }
        }

        $existingRoles = Role::query()->whereIn('name', self::OrganisationRoles)->pluck('name')->all();
        $organisation = $existingRoles === [] ? [] : User::role($existingRoles)->pluck('users.id')->map(fn (mixed $id): int => (int) $id)->all();
        $mealManager = MealCoverage::mealManagerEmployeeId();

        if ($mealManager !== null) {
            $organisation = [...$organisation, ...array_values($this->employees->usersFor([$mealManager]))];
        }

        foreach ($organisation as $userId) {
            $scopes[$userId] = null;
        }

        $superAdmins = $scopes === [] ? [] : User::query()
            ->whereIn('users.id', array_keys($scopes))
            ->whereHas('roles', fn ($query) => $query->where('name', EventCatalog::SuperAdminRole))
            ->pluck('users.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_map(
            fn (?array $programIds): ?array => $programIds === null ? null : array_values(array_unique($programIds)),
            array_diff_key($scopes, array_flip($superAdmins)),
        );
    }

    /**
     * Projects that started or ended in the month and actually ran.
     *
     * @return list<Fact>
     */
    private function projectFacts(CarbonImmutable $month): array
    {
        $from = $month->toDateString();
        $to = $month->endOfMonth()->toDateString();
        $facts = [];

        Project::query()
            ->whereIn('status', [ProjectStatus::Active, ProjectStatus::Closed, ProjectStatus::Archived])
            ->where(fn ($query) => $query->whereBetween('start_date', [$from, $to])->orWhereBetween('end_date', [$from, $to]))
            ->toBase()
            ->get(['id', 'program_id', 'start_date', 'end_date'])
            ->each(function (object $project) use (&$facts, $from, $to): void {
                foreach (['started' => $project->start_date, 'ended' => $project->end_date] as $bucket => $date) {
                    if ($date !== null && $date >= $from && $date <= $to) {
                        $facts[] = ['id' => (int) $project->id, 'program_id' => $project->program_id === null ? null : (int) $project->program_id, 'bucket' => $bucket];
                    }
                }
            });

        return $facts;
    }

    /**
     * Indicators behind pace at the end of the month (or now, for the current month).
     *
     * @return list<Fact>
     */
    private function indicatorFacts(CarbonImmutable $month): array
    {
        $asOf = $month->endOfMonth()->min(CarbonImmutable::now());
        $indicators = Indicator::query()->with('progress')->get();
        $resultPrograms = Result::query()->whereIn('id', $indicators->pluck('result_id')->filter()->unique()->all())->pluck('program_id', 'id');
        $projectPrograms = Project::query()->whereIn('id', $indicators->pluck('project_id')->filter()->unique()->all())->pluck('program_id', 'id');
        $facts = [];

        foreach ($indicators as $indicator) {
            if (! $this->offTrack->isOffPace($indicator, $asOf)) {
                continue;
            }

            $programId = $resultPrograms[$indicator->result_id] ?? $projectPrograms[$indicator->project_id] ?? null;
            $facts[] = ['id' => $indicator->id, 'program_id' => $programId === null ? null : (int) $programId, 'bucket' => 'off_track'];
        }

        return $facts;
    }

    /**
     * Unsent donor reports: due during the next month, or overdue before it.
     * Names and dates only: no money column is read.
     *
     * @return list<Fact>
     */
    private function donorReportFacts(CarbonImmutable $month): array
    {
        $next = $month->addMonth()->startOfMonth();

        return array_values(DB::table('donor_reports')
            ->join('donor_proposal', 'donor_proposal.id', '=', 'donor_reports.donor_proposal_id')
            ->leftJoin('project_proposal', 'project_proposal.proposal_id', '=', 'donor_proposal.proposal_id')
            ->leftJoin('projects', 'projects.id', '=', 'project_proposal.project_id')
            ->whereNull('donor_reports.submitted_on')
            ->where('donor_reports.due_on', '<', $next->addMonth()->toDateString())
            ->get(['donor_reports.id', 'projects.program_id', 'donor_reports.due_on'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'program_id' => $row->program_id === null ? null : (int) $row->program_id,
                'bucket' => $row->due_on >= $next->toDateString() ? 'due' : 'overdue',
            ])
            ->all());
    }

    /**
     * Distinct records per line inside the recipient's scope (null = every program).
     *
     * @param  list<Fact>  $facts
     * @param  list<string>  $lines
     * @param  list<int>|null  $programIds
     * @return array<string, int>
     */
    private function count(array $facts, array $lines, ?array $programIds): array
    {
        $counts = [];

        foreach ($lines as $line) {
            $ids = [];

            foreach ($facts as $fact) {
                if ($fact['bucket'] === $line && ($programIds === null || in_array($fact['program_id'], $programIds, true))) {
                    $ids[$fact['id']] = true;
                }
            }

            $counts[$line] = count($ids);
        }

        return $counts;
    }

    /**
     * @param  Sections  $sections
     */
    private function payload(User $user, string $stage, array $sections): NotificationPayload
    {
        $counts = [];

        foreach ($sections as $section => $lines) {
            foreach ($lines as $line => $count) {
                $counts["{$section}_{$line}"] = $count;
            }
        }

        return new NotificationPayload(
            kind: NotificationKind::Digest,
            module: 'system',
            priority: NotificationPriority::Info,
            key: 'notifications.digests.monthly.summary',
            params: ['month' => ['month' => $stage], 'sections' => ['digest_sections' => $counts]],
            subjectType: 'user',
            subjectId: $user->id,
            link: ['route' => 'dashboard'],
        );
    }
}
