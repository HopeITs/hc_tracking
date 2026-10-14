<?php

use App\Enums\ArStatus;
use App\Enums\NotificationPriority;
use App\Enums\ProposalStatus;
use App\Models\Account;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Risk;
use App\Models\Role;
use App\Models\TransfersTracking;
use App\Models\UserNotification;

test('a rejected proposal is critical for finance, the pr manager and the top roles', function () {
    $financial = userWithRole('Financial');
    $prManager = userWithRole('Public Relations Manager');
    $generalManager = userWithRole('General Manager');
    $proposal = Activity::withoutRecording(fn () => Proposal::factory()->create());

    $this->actingAs(userWithRole('Financial'));
    $proposal->forceFill(['status' => ProposalStatus::Rejected])->save();

    foreach ([$financial, $prManager, $generalManager] as $recipient) {
        expect(UserNotification::query()->forUser($recipient)->sole()->priority)->toBe(NotificationPriority::Critical);
    }
});

test('an approved proposal is important; a status note is recorded on the proposal', function () {
    $financial = userWithRole('Financial');
    $proposal = Activity::withoutRecording(fn () => Proposal::factory()->create());

    $this->actingAs(userWithRole('Financial'));
    $proposal->forceFill(['status' => ProposalStatus::Approved])->save();
    ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'context' => 'Signed by donor']);

    expect(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['proposal.status_changed', 'proposal.status_note_added'])
        ->and(UserNotification::query()->forUser($financial)->sole()->priority)->toBe(NotificationPriority::Important);
});

test('a failed transfer is critical and a received one is not key', function () {
    $financial = userWithRole('Financial');
    $generalManager = userWithRole('General Manager');
    [$failing, $landing] = Activity::withoutRecording(fn () => TransfersTracking::factory()->count(2)->create());

    $this->actingAs(userWithRole('Financial'));
    $failing->update(['ar_status' => ArStatus::Failed]);
    $landing->update(['ar_status' => ArStatus::Received, 'amount_received' => '10', 'net_amount_received' => '10', 'receive_date' => now()->toDateString()]);

    expect(UserNotification::query()->forUser($generalManager)->sole()->subject_id)->toBe($failing->id)
        ->and(UserNotification::query()->forUser($financial)->count())->toBe(2);
});

test('scheduling a tranche, submitting a donor report and closing an account are recorded', function () {
    $agreement = Activity::withoutRecording(fn () => DonorProposal::factory()->create());
    $donorReport = Activity::withoutRecording(fn () => DonorReport::factory()->create(['donor_proposal_id' => $agreement->id]));
    $account = Activity::withoutRecording(fn () => Account::factory()->create());

    $this->actingAs(userWithRole('Financial'));
    TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id]);
    $donorReport->update(['submitted_on' => now()->toDateString()]);
    $account->close();

    expect(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['transfer.scheduled', 'donor_report.submitted', 'account.closed']);
});

test('a user holding several roles hears every stream they qualify for, whatever their active role', function () {
    $both = userWithRole('Financial');
    $both->assignRole('MEAL');
    $both->setActiveRole(Role::findByName('Financial')->id);
    [$risk, $proposal] = Activity::withoutRecording(fn () => [Risk::factory()->create(), Proposal::factory()->create()]);

    $this->actingAs(userWithRole('Managers'));
    $risk->update(['name' => 'Renamed risk']);
    $proposal->update(['name' => 'Renamed proposal']);

    expect(UserNotification::query()->forUser($both)->pluck('module')->sort()->values()->all())->toBe(['proposals', 'risks']);
});

test('media team members never hear about finance modules', function () {
    $media = userWithRole('Media Team');

    $this->actingAs(userWithRole('Financial'));
    Proposal::factory()->create();

    expect(UserNotification::query()->forUser($media)->count())->toBe(0);
});

test('a tranche is named with its amount formatted like the rest of the app', function () {
    $transfer = Activity::withoutRecording(fn () => TransfersTracking::factory()->create(['payment_amount' => '923100.12']));

    expect($transfer->activityLabel())->toContain(' · 923,100.12 ');
});
