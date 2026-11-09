<?php

use App\Enums\ArStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use App\Support\Desk\Panels;
use App\Support\Desk\ReadyLayoutSignals;
use Illuminate\Support\Arr;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->travelTo('2026-09-15 09:00:00');
});

/**
 * One composed layout, by key, as the viewer is offered it.
 *
 * @return array{key: string, title: string, purpose: string, entries: list<array{panel: string, size: string, cols: int, rows: int}>}|null
 */
function readyLayout(User $user, string $key): ?array
{
    return collect(app(Panels::class)->readyLayoutsFor($user))->firstWhere('key', $key);
}

/**
 * The viewer's signals, with no decisions waiting on them.
 *
 * @return array<string, array{text: string, tone: string, count: int}>
 */
function readySignalsFor(User $user): array
{
    test()->actingAs($user);

    return app(ReadyLayoutSignals::class)->for($user, ['total' => 0, 'late' => 0]);
}

/**
 * A top-level task due on the date, created by one user and assigned to one employee.
 */
function readyTask(User $creator, int $assigneeEmployeeId, string $due, TaskStatus $status = TaskStatus::Todo): Task
{
    return Activity::withoutRecording(function () use ($creator, $assigneeEmployeeId, $due, $status): Task {
        $task = Task::factory()->create(['created_by' => $creator->id, 'due_date' => $due, 'status' => $status]);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $assigneeEmployeeId]);

        return $task;
    });
}

test('every panel in the catalogue exists, every row totals twelve columns and one height', function () {
    $panels = app(Panels::class);
    $known = array_keys($panels->availableTo(userWithRole('Super Admin', insertHrEmployee())));

    expect(array_keys($panels->readyCatalogue()))->toBe(['today', 'money', 'donors', 'tasks', 'delivery', 'impact', 'fundraising', 'team', 'overview']);

    foreach ($panels->readyCatalogue() as $layout) {
        expect($known)->toContain($layout['core']);

        foreach ($layout['rows'] as $row) {
            expect(array_sum(array_column($row, 1)))->toBe(12)
                ->and(array_unique(array_column($row, 2)))->toHaveCount(1)
                ->and(array_diff(array_column($row, 0), $known))->toBe([]);
        }
    }
});

test('an administrator gets the whole money layout, cell for cell', function () {
    $entries = readyLayout(userWithRole('Super Admin', insertHrEmployee()), 'money')['entries'];

    expect(array_map(fn (array $entry): array => [$entry['panel'], $entry['cols'], $entry['rows']], $entries))->toBe([
        ['money', 8, 5], ['transfers', 4, 5],
        ['overdue', 5, 4], ['duties', 7, 4],
        ['pipeline', 12, 4],
    ]);
});

test('a viewer lacking a panel gets its row widened, with no panel moving to another row and no empty row', function () {
    $entries = readyLayout(userWithRole('Team Member', insertHrEmployee()), 'donors');
    expect($entries)->toBeNull();

    $roleless = User::factory()->create();
    $roleless->givePermissionTo(Permission::findOrCreate('donors.view', 'web'));
    $roleless->givePermissionTo(Permission::findOrCreate('calendar.view', 'web'));

    $entries = readyLayout($roleless->fresh(), 'donors')['entries'];

    // The pipeline leaves row one, duties and money leave row two (so it disappears), coming keeps its own row.
    expect(array_map(fn (array $entry): array => [$entry['panel'], $entry['cols'], $entry['rows']], $entries))->toBe([
        ['donors', 12, 5],
        ['coming', 12, 3],
    ]);
});

test('every role gets full rows of one height, and no panel it may not see', function (string $role) {
    $user = userWithRole($role, insertHrEmployee());
    $panels = app(Panels::class);
    $available = array_keys($panels->availableTo($user));

    foreach ($panels->readyLayoutsFor($user) as $layout) {
        $used = 0;
        $height = null;

        foreach ($layout['entries'] as $entry) {
            expect($available)->toContain($entry['panel']);

            $height = $used === 0 ? $entry['rows'] : $height;
            expect($entry['rows'])->toBe($height);

            $used += $entry['cols'];
            expect($used)->toBeLessThanOrEqual(12);
            $used %= 12;
        }

        expect($used)->toBe(0);
    }
})->with(['General Manager', 'Public Relations Manager', 'Public Relations', 'MEAL', 'Financial', 'Managers', 'Media Team', 'Team Member']);

test('a layout whose core panel the viewer may not see is not offered', function () {
    $keys = array_column(app(Panels::class)->readyLayoutsFor(User::factory()->create()), 'key');

    expect($keys)->toContain('today')
        ->not->toContain('money')
        ->not->toContain('donors');
});

test('a layout left with a single panel is not offered', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::findOrCreate('targets.view', 'web'));

    expect(readyLayout($user->fresh(), 'impact'))->toBeNull();
});

test('below 1280px a true row keeps together: two cards halve, a third goes under them, four make two lines, and an uneven row is left alone', function () {
    $panels = app(Panels::class);
    $cell = fn (string $panel, int $cols, int $rows): array => ['panel' => $panel, 'size' => 'L', 'cols' => $cols, 'rows' => $rows];

    expect($panels->narrowSpansOf([$cell('a', 8, 5), $cell('b', 4, 5)]))->toBe(['a' => 6, 'b' => 6])
        ->and($panels->narrowSpansOf([$cell('a', 4, 4), $cell('b', 4, 4), $cell('c', 4, 4)]))->toBe(['a' => 6, 'b' => 6, 'c' => 12])
        ->and($panels->narrowSpansOf([$cell('a', 3, 4), $cell('b', 3, 4), $cell('c', 3, 4), $cell('d', 3, 4)]))->toBe(['a' => 6, 'b' => 6, 'c' => 6, 'd' => 6])
        ->and($panels->narrowSpansOf([$cell('a', 12, 2)]))->toBe([])
        ->and($panels->narrowSpansOf([$cell('a', 8, 5), $cell('b', 4, 3)]))->toBe([]);

    $user = userWithRole('Financial', insertHrEmployee());
    $user->desk_layout = readyLayout($user, 'money')['entries'];
    $user->save();

    Livewire::actingAs($user->fresh())->test('pages::desk.index')
        ->assertSeeHtml('#dk-panel-money { --span-r: 6; }')
        ->assertSeeHtml('#dk-panel-transfers { --span-r: 6; }')
        ->assertDontSeeHtml('#dk-panel-pipeline { --span-r');
});

test('the money signal counts late transfers and what is still to arrive, per currency', function () {
    $agreement = DonorProposal::factory()->create();
    $tranche = fn (array $attributes): TransfersTracking => TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, ...$attributes]);

    $tranche(['payment_amount' => 1000, 'scheduled_date' => '2026-09-01']);
    $tranche(['payment_amount' => 2000, 'scheduled_date' => '2026-08-01']);
    $tranche(['payment_amount' => 5000, 'scheduled_date' => '2026-12-01']);
    $tranche(['payment_amount' => 700, 'scheduled_date' => '2026-12-01', 'amount_transfered_currency' => 'EUR']);
    // In transit past its date is not late; received is neither late nor still to arrive.
    $tranche(['payment_amount' => 400, 'scheduled_date' => '2026-08-01', 'transfer_date' => '2026-08-02']);
    $tranche(['payment_amount' => 9000, 'scheduled_date' => '2026-08-01', 'ar_status' => ArStatus::Received]);

    expect(readySignalsFor(userWithRole('Financial', insertHrEmployee()))['money'])->toBe([
        'text' => '2 transfers late · $8,400 + €700 still to arrive',
        'tone' => 'critical',
        'count' => 2,
    ]);
});

test('the money signal reads calmly when nothing is late', function () {
    expect(readySignalsFor(userWithRole('Financial', insertHrEmployee()))['money'])->toBe([
        'text' => 'Nothing late. All transfers on time.',
        'tone' => 'good',
        'count' => 0,
    ]);
});

test('a viewer who may not see money gets no money signal, and money stays out of their other signals', function () {
    TransfersTracking::factory()->create(['payment_amount' => 1000, 'scheduled_date' => '2026-09-01']);

    $signals = readySignalsFor(userWithRole('Team Member', insertHrEmployee()));

    expect($signals)->not->toHaveKey('money')
        ->and(collect($signals)->pluck('text')->implode(' '))->not->toContain('$');
});

test('the tasks signal counts the viewer\'s past due tasks and what waits on others, not another person\'s', function () {
    $me = userWithRole('Team Member', insertHrEmployee());
    $someone = userWithRole('Team Member', insertHrEmployee());

    readyTask($someone, $me->employee_id, '2026-09-01');
    readyTask($me, $someone->employee_id, '2026-09-02');
    readyTask($me, $someone->employee_id, '2026-09-20');
    readyTask($someone, $me->employee_id, '2026-09-03', TaskStatus::Done);
    readyTask($someone, $someone->employee_id, '2026-09-04');

    expect(readySignalsFor($me)['tasks'])->toBe([
        'text' => '2 tasks past due · 1 waiting on others',
        'tone' => 'critical',
        'count' => 2,
    ]);
});

test('the tasks signal reads calmly when nothing is past due', function () {
    $me = userWithRole('Team Member', insertHrEmployee());
    readyTask($me, $me->employee_id, '2026-09-20');

    expect(readySignalsFor($me)['tasks'])->toBe([
        'text' => 'Nothing past due. All tasks on time.',
        'tone' => 'good',
        'count' => 0,
    ]);
});

test('the donors signal counts the donors we work with and the newest version of open reports', function () {
    $viewer = userWithRole('General Manager', insertHrEmployee());
    $donor = Donor::factory()->create();
    Proposal::factory()->create(['donor_id' => $donor->id]);
    Donor::factory()->create();
    $agreement = DonorProposal::factory()->create(['donor_id' => $donor->id]);
    $report = ['donor_proposal_id' => $agreement->id, 'submitted_on' => null, 'period_start' => '2026-01-01', 'period_end' => '2026-03-31', 'due_on' => '2026-09-20'];
    DonorReport::factory()->create([...$report, 'version' => 1]);
    DonorReport::factory()->create([...$report, 'version' => 2]);

    expect(readySignalsFor($viewer)['donors'])->toBe([
        'text' => '1 donor · 1 report due this month',
        'tone' => 'warn',
        'count' => 1,
    ]);
});

test('the sheet asks for signals through a call that returns them, and the render never reads them', function () {
    $reader = $this->spy(ReadyLayoutSignals::class);
    $desk = Livewire::actingAs(userWithRole('Team Member', insertHrEmployee()))->test('pages::desk.index')
        ->assertSeeHtml('data-customize-ready');

    $reader->shouldNotHaveReceived('for');
    $reader->shouldNotHaveReceived('suggestionFor');

    $reader->shouldReceive('for')->andReturn(['today' => ['text' => 'All calm', 'tone' => 'good', 'count' => 0]]);
    $reader->shouldReceive('suggestionFor')->andReturn(['key' => 'today', 'reason' => null]);

    $desk->call('readySignals');

    expect($desk->effects['returns'][0]['signals']['today']['text'])->toBe('All calm');
});

test('the role\'s favourite is suggested', function (string $role, string $favourite) {
    $user = userWithRole($role, insertHrEmployee());
    $panels = app(Panels::class);

    expect($panels->favouriteReadyLayoutFor($user, array_column($panels->readyLayoutsFor($user), 'key')))->toBe($favourite);
})->with([
    ['General Manager', 'overview'],
    ['Super Admin', 'overview'],
    ['Public Relations Manager', 'fundraising'],
    ['MEAL', 'impact'],
    ['Financial', 'money'],
    ['Managers', 'team'],
    ['Media Team', 'tasks'],
    ['Team Member', 'today'],
]);

test('a favourite the viewer is not offered falls back to My day: Public Relations while its role lacks the donor list', function () {
    $user = userWithRole('Public Relations', insertHrEmployee());
    $user->roles->first()->revokePermissionTo('donors.view');
    $panels = app(Panels::class);
    $favourite = fn (User $viewer): ?string => $panels->favouriteReadyLayoutFor($viewer, array_column($panels->readyLayoutsFor($viewer), 'key'));

    expect($favourite($user->fresh()))->toBe('today');

    $user->roles->first()->givePermissionTo(Permission::findOrCreate('donors.view', 'web'));

    expect($favourite($user->fresh()))->toBe('donors');
});

test('someone with no role is suggested My day', function () {
    $user = User::factory()->create();
    $panels = app(Panels::class);

    expect($panels->favouriteReadyLayoutFor($user, array_column($panels->readyLayoutsFor($user), 'key')))->toBe('today');
});

test('a critical layout with three times the favourite\'s count takes the suggestion, and the reason says so', function () {
    $financial = userWithRole('Financial', insertHrEmployee());
    $signals = [
        'money' => ['text' => '', 'tone' => 'critical', 'count' => 2],
        'tasks' => ['text' => '', 'tone' => 'critical', 'count' => 6],
        'today' => ['text' => '', 'tone' => 'warn', 'count' => 50],
    ];

    expect(app(ReadyLayoutSignals::class)->suggestionFor($financial, $signals))->toBe([
        'key' => 'tasks',
        'reason' => 'Suggested over Money tracking: this needs you sooner.',
    ]);
});

test('the favourite keeps the suggestion below three times its count or against a tone that is not critical', function () {
    $financial = userWithRole('Financial', insertHrEmployee());
    $signals = [
        'money' => ['text' => '', 'tone' => 'critical', 'count' => 2],
        'tasks' => ['text' => '', 'tone' => 'critical', 'count' => 5],
        'today' => ['text' => '', 'tone' => 'warn', 'count' => 50],
    ];

    expect(app(ReadyLayoutSignals::class)->suggestionFor($financial, $signals))->toBe(['key' => 'money', 'reason' => null]);
});

test('saving a ready layout through saveLayout persists it, and null goes back to the role default', function () {
    $user = userWithRole('Financial', insertHrEmployee());
    $entries = readyLayout($user, 'money')['entries'];
    $desk = Livewire::actingAs($user)->test('pages::desk.index');

    $desk->call('saveLayout', $entries);

    expect($user->fresh()->desk_layout)->toBe($entries);

    $desk->call('saveLayout', null);

    expect($user->fresh()->desk_layout)->toBeNull();
});

test('a ready layout sent by a viewer who may not see its panels drops them on save', function () {
    $user = userWithRole('Team Member', insertHrEmployee());
    $money = readyLayout(userWithRole('Super Admin', insertHrEmployee()), 'money')['entries'];

    Livewire::actingAs($user)->test('pages::desk.index')->call('saveLayout', $money);

    expect(array_column($user->fresh()->desk_layout, 'panel'))->not->toContain('money')
        ->not->toContain('transfers')
        ->toContain('overdue');
});

test('the sheet offers only the viewer\'s layouts, favourite first, and the hero has no customize buttons', function () {
    $html = Livewire::actingAs(userWithRole('Financial', insertHrEmployee()))->test('pages::desk.index')
        ->assertDontSeeHtml('data-desk-layouts')
        ->assertDontSeeHtml('data-desk-edit')
        ->assertSeeHtml('data-customize-ready')
        ->assertSeeHtml('data-ready-layout="today"')
        ->html();

    expect(strpos($html, 'data-ready-layout="money"'))->toBeLessThan(strpos($html, 'data-ready-layout="today"'));

    Livewire::actingAs(User::factory()->create())->test('pages::desk.index')
        ->assertSeeHtml('data-ready-layout="today"')
        ->assertDontSeeHtml('data-ready-layout="money"');
});

/**
 * The figure in a panel's header, as this viewer first sees it.
 */
function deskCardCount(User $user, string $panel): ?int
{
    preg_match('/data-panel-count>\s*(\d+)/', Livewire::actingAs($user)->test("desk.panels.{$panel}", ['size' => 'XL'])->html(), $found);

    return isset($found[1]) ? (int) $found[1] : null;
}

/**
 * Targets rated this year: on pace (value 100) or Red (value 1).
 */
function readyTargets(int $red, int $onPace = 0): void
{
    foreach ([[1, $red], [100, $onPace]] as [$value, $times]) {
        foreach (range(1, max(1, $times)) as $i) {
            if ($times > 0) {
                $indicator = Indicator::factory()->create(['baseline' => 0, 'target' => 100]);
                IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => $value, 'date' => now()->toDateString()]);
            }
        }
    }
}

test('the fundraising signal states the pipeline card\'s count, and the next deadline of an open proposal as the card shows it', function () {
    $viewer = userWithRole('General Manager', insertHrEmployee());
    Proposal::factory()->create(['status' => ProposalStatus::ConceptNote]);
    $open = Proposal::factory()->create(['status' => ProposalStatus::Approved]);
    Milestone::factory()->create(['proposal_id' => $open->id, 'planned_end_date' => '2026-10-12', 'status' => MilestoneStatus::Ongoing]);
    $rejected = Proposal::factory()->create(['status' => ProposalStatus::Rejected]);
    Milestone::factory()->create(['proposal_id' => $rejected->id, 'planned_end_date' => '2026-09-20', 'status' => MilestoneStatus::Ongoing]);
    $completed = Proposal::factory()->create(['status' => ProposalStatus::Completed]);
    Milestone::factory()->create(['proposal_id' => $completed->id, 'planned_end_date' => '2026-08-01', 'status' => MilestoneStatus::Ongoing]);

    $signal = readySignalsFor($viewer)['fundraising'];

    expect($signal['text'])->toBe('4 in the pipeline · next deadline in 27 days')
        ->and(deskCardCount($viewer, 'pipeline'))->toBe(4)
        ->and($signal['tone'])->toBe('neutral');

    Livewire::actingAs($viewer)->test('desk.panels.pipeline', ['size' => 'XL'])->assertSee('27 days left');
});

test('passed deadlines are the fundraising role\'s to answer for: red for it, amber for anyone else', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::ProposalDraft]);
    Milestone::factory()->create(['proposal_id' => $proposal->id, 'planned_end_date' => '2026-09-01', 'status' => MilestoneStatus::Ongoing]);

    expect(readySignalsFor(userWithRole('General Manager', insertHrEmployee()))['fundraising'])
        ->toBe(['text' => '1 in the pipeline · 1 deadline passed', 'tone' => 'warn', 'count' => 1])
        ->and(readySignalsFor(userWithRole('Public Relations Manager', insertHrEmployee()))['fundraising']['tone'])->toBe('critical');
});

test('the delivery signal states what the projects card shows by default: every project, for an owner too', function () {
    $owner = userWithRole('Managers', insertHrEmployee());
    Project::factory()->count(2)->create(['user_id' => $owner->employee_id]);
    Project::factory()->count(3)->create();
    $bystander = userWithRole('General Manager', insertHrEmployee());

    expect(deskCardCount($owner, 'projects'))->toBe(5)
        ->and(readySignalsFor($owner)['delivery']['text'])->toStartWith('5 projects · ')
        ->and(deskCardCount($bystander, 'projects'))->toBe(5)
        ->and(readySignalsFor($bystander)['delivery']['text'])->toStartWith('5 projects · ');
});

test('a late project is critical only for the person who owns it', function () {
    $owner = userWithRole('Managers', insertHrEmployee());
    $project = Project::factory()->create(['user_id' => $owner->employee_id, 'status' => ProjectStatus::Active]);
    Project::factory()->create(['status' => ProjectStatus::Active]);
    Milestone::factory()->create(['project_id' => $project->id, 'proposal_id' => null, 'planned_end_date' => '2026-09-01', 'status' => MilestoneStatus::Ongoing]);

    expect(readySignalsFor($owner)['delivery'])->toMatchArray(['tone' => 'critical', 'count' => 1])
        ->and(readySignalsFor(userWithRole('General Manager', insertHrEmployee()))['delivery'])->toMatchArray(['tone' => 'warn', 'count' => 1]);
});

test('the money, waiting and donor figures match their cards', function () {
    $viewer = userWithRole('Financial', insertHrEmployee());
    TransfersTracking::factory()->count(3)->create(['scheduled_date' => '2026-09-01']);
    readyTask($viewer, insertHrEmployee(), '2026-09-02');

    $signals = readySignalsFor($viewer);

    expect($signals['money']['count'])->toBe(3)
        ->and(deskCardCount($viewer, 'transfers'))->toBe(3)
        ->and($signals['tasks']['text'])->toContain('1 waiting on others')
        ->and(deskCardCount($viewer, 'waiting'))->toBe(1);

    $admin = userWithRole('Super Admin', insertHrEmployee());
    Proposal::factory()->create(['donor_id' => Donor::factory()->create()->id]);
    $donorCount = deskCardCount($admin, 'donors');

    expect(readySignalsFor($admin)['donors']['text'])->toStartWith(trans_choice('desk.ready.signal.donors', $donorCount, ['count' => $donorCount]));
});

test('a tranche already sent is not overdue, so the money signal and Past the deadline agree', function () {
    $viewer = userWithRole('Financial', insertHrEmployee());
    TransfersTracking::factory()->create(['scheduled_date' => '2026-09-01']);
    TransfersTracking::factory()->create(['scheduled_date' => '2026-08-01', 'transfer_date' => '2026-08-02']);

    expect(readySignalsFor($viewer)['money']['count'])->toBe(1)
        ->and(deskCardCount($viewer, 'overdue'))->toBe(1);
});

test('a calm team member keeps My day however behind the organisation is, and no reason is claimed', function () {
    $me = userWithRole('Team Member', insertHrEmployee());
    readyTargets(red: 11);
    $reader = app(ReadyLayoutSignals::class);
    $signals = readySignalsFor($me);

    expect($signals)->toHaveKey('impact')
        ->and($signals['impact']['tone'])->toBe('warn')
        ->and(collect($signals)->where('tone', 'critical'))->toBeEmpty()
        ->and($reader->suggestionFor($me, $signals))->toBe(['key' => 'today', 'reason' => null]);
});

test('a team member\'s own late tasks do take the suggestion, and the reason is true', function () {
    $me = userWithRole('Team Member', insertHrEmployee());
    readyTask(userWithRole('Team Member', insertHrEmployee()), $me->employee_id, '2026-09-01');
    $signals = readySignalsFor($me);

    expect($signals['tasks']['tone'])->toBe('critical')
        ->and(app(ReadyLayoutSignals::class)->suggestionFor($me, $signals))->toBe([
            'key' => 'tasks',
            'reason' => 'Suggested over My day: this needs you sooner.',
        ]);
});

test('for a leader, red stays on their own late work and the suggestion keeps its own true tone', function () {
    $admin = userWithRole('Super Admin', insertHrEmployee());
    readyTargets(red: 11, onPace: 1);
    TransfersTracking::factory()->count(4)->create(['scheduled_date' => '2026-09-01']);
    Milestone::factory()->create(['project_id' => Project::factory()->create(['status' => ProjectStatus::Active])->id, 'proposal_id' => null, 'planned_end_date' => '2026-09-01', 'status' => MilestoneStatus::Ongoing]);
    Project::factory()->create(['status' => ProjectStatus::Active]);
    readyTask($admin, $admin->employee_id, '2026-09-01');

    $signals = app(ReadyLayoutSignals::class)->for(tap($admin, fn (User $user) => test()->actingAs($user)), ['total' => 9, 'late' => 5]);
    $suggestion = app(ReadyLayoutSignals::class)->suggestionFor($admin, $signals);

    expect(array_keys(array_filter($signals, fn (array $signal): bool => $signal['tone'] === 'critical')))->toEqualCanonicalizing(['today', 'tasks'])
        ->and($signals['money']['tone'])->toBe('warn')
        ->and($signals['impact']['tone'])->toBe('warn')
        ->and($signals['delivery']['tone'])->toBe('warn')
        ->and($suggestion)->toBe(['key' => 'overview', 'reason' => null])
        ->and($signals['overview']['tone'])->toBe('neutral');
});

test('Red targets are red for MEAL only past half of those rated', function () {
    $meal = userWithRole('MEAL', insertHrEmployee());

    readyTargets(red: 1, onPace: 2);
    expect(readySignalsFor($meal)['impact']['tone'])->toBe('warn');

    readyTargets(red: 2);
    expect(readySignalsFor($meal)['impact'])->toMatchArray(['tone' => 'critical', 'count' => 3]);
});

/*
| Desk page review, 6 Nov 2026: Results and MEAL said "All 39 targets on
| pace." while 22 more indicators with a target had no figures at all.
*/

test('indicators with no figures yet are counted, never hidden behind "all on pace"', function () {
    $meal = userWithRole('MEAL', insertHrEmployee());
    readyTargets(red: 0, onPace: 2);
    Indicator::factory()->create(['baseline' => 0, 'target' => 100]);

    expect(readySignalsFor($meal)['impact'])->toMatchArray([
        'text' => '2 of 3 targets on pace · 1 with no figures yet',
        'tone' => 'warn',
        'count' => 1,
    ]);
});

test('targets are all on pace only when every one of them is rated on pace', function () {
    $meal = userWithRole('MEAL', insertHrEmployee());
    readyTargets(red: 0, onPace: 2);

    expect(readySignalsFor($meal)['impact'])->toMatchArray(['text' => 'All 2 targets on pace.', 'tone' => 'good']);
});

test('a donor report is critical only for the person responsible for it', function () {
    $mine = userWithRole('General Manager', insertHrEmployee());
    $other = userWithRole('General Manager', insertHrEmployee());
    $donor = Donor::factory()->create();
    Proposal::factory()->create(['donor_id' => $donor->id]);
    DonorReport::factory()->create([
        'donor_proposal_id' => DonorProposal::factory()->create(['donor_id' => $donor->id])->id,
        'submitted_on' => null,
        'due_on' => '2026-09-01',
        'responsible_employee_id' => $mine->employee_id,
    ]);

    expect(readySignalsFor($mine)['donors'])->toMatchArray(['tone' => 'critical', 'count' => 1])
        ->and(readySignalsFor($other)['donors'])->toMatchArray(['tone' => 'warn', 'count' => 1]);
});

test('someone with no team has no Team lead signal, so the sheet drops it and the suggestion falls back', function () {
    $lonely = userWithRole('Managers', insertHrEmployee());
    $panels = app(Panels::class);

    expect(array_column($panels->readyLayoutsFor($lonely), 'key'))->toContain('team');

    $signals = readySignalsFor($lonely);

    expect($signals)->not->toHaveKey('team')
        ->and(app(ReadyLayoutSignals::class)->suggestionFor($lonely, $signals)['key'])->toBe('today');
});

test('arabic signals isolate each amount, and english text stays clean', function () {
    TransfersTracking::factory()->create(['payment_amount' => 1000, 'scheduled_date' => '2026-09-01']);
    $viewer = userWithRole('Financial', insertHrEmployee());

    expect(readySignalsFor($viewer)['money']['text'])->not->toContain("\u{2066}");

    app()->setLocale('ar');

    expect(readySignalsFor($viewer)['money']['text'])->toContain("\u{2066}$1,000\u{2069}")
        ->toContain('تحويل واحد متأخر');
});

test('undo restores the saved layout exactly, as the sheet sends it back', function () {
    $user = userWithRole('Financial', insertHrEmployee());
    $panels = app(Panels::class);
    $before = [$panels->sized('needs', 'L', 7, 4), $panels->sized('transfers', 'M', 5, 4)];
    $user->desk_layout = $before;
    $user->save();

    $desk = Livewire::actingAs($user->fresh())->test('pages::desk.index');

    preg_match('/data-layout="([^"]*)" data-layout-saved="(true|false)"/', $desk->html(), $sheet);
    $previous = json_decode(html_entity_decode($sheet[1]), true);

    expect($sheet[2])->toBe('true');

    $desk->call('saveLayout', readyLayout($user, 'money')['entries']);
    expect(array_column($user->fresh()->desk_layout, 'panel'))->toContain('money');

    $desk->call('saveLayout', $previous);
    expect($user->fresh()->desk_layout)->toBe($before);
});

test('undo puts someone still on the role default back on null', function () {
    $user = userWithRole('Financial', insertHrEmployee());
    $desk = Livewire::actingAs($user)->test('pages::desk.index')->assertSeeHtml('data-layout-saved="false"');

    $desk->call('saveLayout', readyLayout($user, 'money')['entries']);
    expect($user->fresh()->desk_layout)->not->toBeNull();

    $desk->call('saveLayout', null)->assertSeeHtml('data-layout-saved="false"');
    expect($user->fresh()->desk_layout)->toBeNull();
});

test('each choice can say its signal failed', function () {
    $html = Livewire::actingAs(userWithRole('Financial', insertHrEmployee()))->test('pages::desk.index')
        ->assertSeeHtml('data-ready-signal-failed')
        ->assertSee('Could not read this now')
        ->html();

    expect($html)->not->toContain('order: suggested');
});

test('the toast fits a phone: it spans the screen edges instead of half of it, on full lines', function () {
    $css = file_get_contents(resource_path('css/desk.css'));

    preg_match('/\.dk-undo\{[^}]*\}/', $css, $toast);

    expect($toast[0])->toContain('inset-inline:16px')
        ->toContain('width:fit-content')
        ->not->toContain('inset-inline-start:50%')
        ->not->toContain('999px')
        ->and($css)->not->toContain('[dir="rtl"] .dk-undo');
});

test('the customize sheet uses only the site palette: no status or category colours in its markup or styles', function () {
    $sources = [
        resource_path('views/pages/desk/_customize-sheet.blade.php'),
        resource_path('views/pages/desk/_ready-layout.blade.php'),
        resource_path('css/desk-customize.css'),
    ];

    foreach ($sources as $source) {
        expect(file_get_contents($source))
            ->not->toContain('--good')
            ->not->toContain('--warn')
            ->not->toContain('--money')
            ->not->toContain('--active')
            ->not->toContain('data-kind');
    }

    expect(file_get_contents(resource_path('css/app.css')))->toContain("@import './desk-customize.css';");
});

test('the sheet gives the hero the layouts pane, groups what can be added, and sizes a new panel by its default', function () {
    $user = userWithRole('Super Admin', insertHrEmployee());
    $html = Livewire::actingAs($user)->test('pages::desk.index')
        ->assertSeeHtml('data-customize-panes')
        ->assertSeeHtml('data-customize-map')
        ->assertSeeHtml('data-cz-resize')
        ->assertSeeHtml('data-customize-empty')
        ->assertSeeHtml('data-customize-search')
        ->assertSeeHtml('data-customize-group="money"')
        ->assertSee('Money and donors')
        ->assertSee('Suggested for you')
        ->html();

    expect($html)->toMatch('/data-customize-group="money".*?data-customize-add="donors".*?data-customize-group="work"/s')
        ->and(app(Panels::class)->sized('flow', 'XL'))->toMatchArray(['cols' => 12, 'rows' => 2])
        ->and(readyLayout($user, 'money')['core'])->toBe('money');
});

test('every ready string exists in english and arabic, with a title and purpose per layout', function () {
    $english = Arr::dot(require lang_path('en/desk.php'));
    $arabic = Arr::dot(require lang_path('ar/desk.php'));
    $ready = fn (array $lines): array => array_values(array_filter(array_keys($lines), fn (string $key): bool => str_starts_with($key, 'ready.')));

    expect($ready($arabic))->toEqualCanonicalizing($ready($english));

    foreach (array_keys(app(Panels::class)->readyCatalogue()) as $key) {
        expect($english)->toHaveKeys(["ready.layouts.{$key}.title", "ready.layouts.{$key}.purpose"]);
    }
});
