<?php

use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\UserNotification;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\Alerts\OnHoldReminderScanner;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;

// 2026-10-14 is a Wednesday; 2026-10-17/18 is a weekend (Mon–Fri default).
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->ownerEmployee = insertHrEmployee();
    $this->owner = userWithRole('Team Member', $this->ownerEmployee);
    Activity::withoutRecording(fn () => $this->owner->givePermissionTo('proposals.view'));
    $this->finance = userWithRole('Financial');
    $this->prManager = userWithRole('Public Relations Manager');
    $this->generalManager = userWithRole('General Manager');
    $this->meal = userWithRole('MEAL');
    $this->superAdmin = userWithRole('Super Admin');
});

function heldProposal(string $remindOn, ProposalStatus $status = ProposalStatus::OnHold): Proposal
{
    return Activity::withoutRecording(function () use ($remindOn, $status): Proposal {
        $proposal = Proposal::factory()->create(['name' => 'Winter Kits', 'status' => $status, 'status_remind_on' => $remindOn]);
        $proposal->projects()->attach(Project::factory()->create(['user_id' => test()->ownerEmployee])->id);

        return $proposal;
    });
}

function scanOnHold(string $at): int
{
    return app(OnHoldReminderScanner::class)->scan(CarbonImmutable::parse($at));
}

/**
 * @return list<int>
 */
function onHoldRecipients(Proposal $proposal): array
{
    $ids = UserNotification::query()
        ->where('data->key', 'notifications.alerts.on_hold_reminder')
        ->where('subject_type', 'proposal')
        ->where('subject_id', $proposal->id)
        ->pluck('notifiable_id')
        ->map(fn (int|string $id): int => (int) $id)
        ->all();
    sort($ids);

    return $ids;
}

/**
 * @param  list<int>  $ids
 * @return list<int>
 */
function sortedOnHoldIds(array $ids): array
{
    sort($ids);

    return $ids;
}

test('a held proposal reminds its project owner, its editors and General Management on the remind date', function () {
    $proposal = heldProposal('2026-10-14');

    scanOnHold('2026-10-14 06:00');

    expect(onHoldRecipients($proposal))
        ->toBe(sortedOnHoldIds([$this->owner->id, $this->finance->id, $this->prManager->id, $this->generalManager->id]))
        ->and(sentencesFor($this->owner))->toBe(['The proposal “Winter Kits” is on hold and its review date (Oct 14, 2026) has come']);
});

test('MEAL oversight does not bypass the proposals view permission, and Super Admin is never told', function () {
    $proposal = heldProposal('2026-10-14');

    scanOnHold('2026-10-14 06:00');

    expect(onHoldRecipients($proposal))->not->toContain($this->meal->id)
        ->and(onHoldRecipients($proposal))->not->toContain($this->superAdmin->id);
});

test('a future remind date sends nothing yet, a missed one is caught up', function () {
    $future = heldProposal('2026-10-15');
    $missed = heldProposal('2026-10-09');

    scanOnHold('2026-10-14 06:00');

    expect(onHoldRecipients($future))->toBe([])
        ->and(onHoldRecipients($missed))->toHaveCount(4);
});

test('the reminder goes once per remind date and a new date re-arms it', function () {
    $proposal = heldProposal('2026-10-14');

    expect(scanOnHold('2026-10-14 06:00'))->toBe(4)
        ->and(scanOnHold('2026-10-15 06:00'))->toBe(0);

    Activity::withoutRecording(fn () => $proposal->forceFill(['status_remind_on' => '2026-10-20'])->save());

    expect(scanOnHold('2026-10-20 06:00'))->toBe(4);
});

test('a proposal that left hold first gets no reminder', function () {
    $proposal = heldProposal('2026-10-14', ProposalStatus::Submitted);

    expect(scanOnHold('2026-10-14 06:00'))->toBe(0)
        ->and(onHoldRecipients($proposal))->toBe([]);
});

test('a weekend remind date goes out on the next working day', function () {
    $proposal = heldProposal('2026-10-17');

    expect(scanOnHold('2026-10-17 06:00'))->toBe(0)
        ->and(scanOnHold('2026-10-19 06:00'))->toBe(4)
        ->and(onHoldRecipients($proposal))->toHaveCount(4);
});

test('a disabled event sends nothing', function () {
    app(NotificationSettings::class)->save(['event_overrides' => ['proposal.on_hold_reminder' => ['enabled' => false]]]);
    $proposal = heldProposal('2026-10-14');

    expect(scanOnHold('2026-10-14 06:00'))->toBe(0)
        ->and(onHoldRecipients($proposal))->toBe([]);
});
