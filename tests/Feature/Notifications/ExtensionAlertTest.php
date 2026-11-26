<?php

use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Alerts\DeadlineCatalog;
use App\Notifications\Alerts\DeadlineItem;
use App\Notifications\Alerts\DueSoonScanner;
use App\Notifications\QuickActions;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/*
| W-33 (D-60): an active proposal whose agreement still has funds, ending
| within the lead window, tells Finance — and offers Public Relations the
| way into W-08's extension modal. The notification never carries an amount.
*/

const EXTENSION_KEY = 'notifications.alerts.ending_with_funds';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->finance = userWithRole('Financial');
    $this->prManager = userWithRole('Public Relations Manager');
    $this->meal = userWithRole('MEAL');
    $this->superAdmin = userWithRole('Super Admin');
});

/**
 * An active proposal ending on the given date, with an agreement. Without a
 * received tranche the whole budget is still outstanding, which is what
 * «funds remain» means (AgreementMoney::OUTSTANDING_SQL).
 */
function endingProposal(string $endsOn, bool $fullyReceived = false, bool $withAgreement = true): Proposal
{
    return Activity::withoutRecording(function () use ($endsOn, $fullyReceived, $withAgreement): Proposal {
        $proposal = Proposal::factory()->create([
            'name' => 'Winter Shelter',
            'status' => ProposalStatus::Active,
            'actual_date_begin' => '2026-01-01',
            'actual_date_end' => $endsOn,
        ]);

        if (! $withAgreement) {
            return $proposal;
        }

        $agreement = DonorProposal::factory()->create([
            'proposal_id' => $proposal->id,
            'donor_id' => $proposal->donor_id,
            'budget' => 100000.00,
            'currency' => Currency::Usd,
        ]);

        if ($fullyReceived) {
            TransfersTracking::factory()->received()->create([
                'donor_proposal_id' => $agreement->id,
                'payment_amount' => 100000.00,
                'amount_received' => 100000.00,
                'amount_transfered_currency' => Currency::Usd,
                'scheduled_date' => '2026-02-01',
            ]);
        }

        return $proposal;
    });
}

/**
 * The catalog items raised for the extension alert on a given day.
 *
 * @return list<DeadlineItem>
 */
function extensionItems(string $at): array
{
    test()->travelTo(CarbonImmutable::parse($at));

    return array_values(array_filter(
        app(DeadlineCatalog::class)->items(CarbonImmutable::parse($at)->addYears(2))->all(),
        fn (DeadlineItem $item): bool => $item->eventKey === 'proposal.ending_with_funds',
    ));
}

/**
 * Who was told, sorted.
 *
 * @return list<int>
 */
function extensionRecipients(Proposal $proposal): array
{
    $ids = UserNotification::query()
        ->where('data->key', EXTENSION_KEY)
        ->where('subject_type', 'proposal')
        ->where('subject_id', $proposal->id)
        ->pluck('notifiable_id')
        ->map(fn (int|string $id): int => (int) $id)
        ->unique()
        ->all();
    sort($ids);

    return array_values($ids);
}

function scanExtension(string $at): int
{
    test()->travelTo(CarbonImmutable::parse($at));

    return app(DueSoonScanner::class)->scan(CarbonImmutable::now());
}

test('an active proposal with funds left is raised as an extension item', function () {
    $proposal = endingProposal('2026-12-31');

    $items = extensionItems('2026-11-05 09:00');

    expect($items)->toHaveCount(1)
        ->and($items[0]->subjectType)->toBe('proposal')
        ->and($items[0]->subjectId)->toBe($proposal->id)
        ->and($items[0]->dueDate->toDateString())->toBe('2026-12-31');
});

test('an agreement whose money has all arrived raises nothing', function () {
    endingProposal('2026-12-31', fullyReceived: true);

    expect(extensionItems('2026-11-05 09:00'))->toBe([]);
});

test('a proposal with no agreement raises nothing', function () {
    endingProposal('2026-12-31', withAgreement: false);

    expect(extensionItems('2026-11-05 09:00'))->toBe([]);
});

test('a proposal that is not active raises nothing', function (ProposalStatus $status) {
    $proposal = endingProposal('2026-12-31');
    Activity::withoutRecording(fn () => $proposal->forceFill(['status' => $status])->save());

    expect(extensionItems('2026-11-05 09:00'))->toBe([]);
})->with([
    'completed' => ProposalStatus::Completed,
    'approved' => ProposalStatus::Approved,
    'rejected' => ProposalStatus::Rejected,
]);

test('the extended end date is what the alert counts from', function () {
    $proposal = endingProposal('2026-12-31');
    Activity::withoutRecording(fn () => $proposal->forceFill(['extended_date_end' => '2027-06-30'])->save());

    expect(extensionItems('2026-11-05 09:00')[0]->dueDate->toDateString())->toBe('2027-06-30');
});

test('Finance and the PR Manager are told, and nobody else', function () {
    $proposal = endingProposal('2026-11-06');

    scanExtension('2026-11-06 09:00');

    expect(extensionRecipients($proposal))->toBe(collect([$this->finance->id, $this->prManager->id])->sort()->values()->all());
});

test('the message names the proposal and its date and carries no amount', function () {
    $proposal = endingProposal('2026-11-06');

    scanExtension('2026-11-06 09:00');

    $sentence = sentencesFor($this->finance)[0] ?? '';

    expect($sentence)->not->toBe('')
        ->and($sentence)->toContain('still has funds')
        ->and($sentence)->toContain('Winter Shelter')
        // Every digit run in the sentence belongs to the date, never to money.
        ->and(array_values(array_diff(
            preg_split('/\D+/', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            ['2026', '6', '11', '06'],
        )))->toBe([]);
});

test('the alert goes once per stage', function () {
    endingProposal('2026-11-06');

    expect(scanExtension('2026-11-06 09:00'))->toBeGreaterThan(0)
        ->and(scanExtension('2026-11-06 10:00'))->toBe(0);
});

test('the quick action opens the extension modal for someone who may extend', function () {
    $proposal = endingProposal('2026-12-31');

    $offered = fn (User $user): array => array_column(app(QuickActions::class)->forSubject('proposal', $proposal->id, $user), 'type');

    expect($offered($this->prManager))->toContain('proposal.request_extension')
        ->and($offered($this->meal))->not->toContain('proposal.request_extension');
});

test('the quick action is not offered on a proposal that cannot be extended', function () {
    $proposal = endingProposal('2026-12-31');
    Activity::withoutRecording(fn () => $proposal->forceFill(['status' => ProposalStatus::Completed])->save());

    $offered = array_column(app(QuickActions::class)->forSubject('proposal', $proposal->id, $this->prManager), 'type');

    expect($offered)->not->toContain('proposal.request_extension');
});

test('the catalog reads the money once, not once per proposal', function () {
    foreach (range(1, 5) as $index) {
        endingProposal('2026-12-0'.$index);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    extensionItems('2026-11-05 09:00');
    $queries = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'donor_proposal'))->count();
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(2);
});
