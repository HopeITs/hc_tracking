<?php

use App\Actions\FundingAgreements\GenerateExpectedTransfers;
use App\Actions\FundingAgreements\SaveAgreementInstallments;
use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Events\FundingAgreementRecorded;
use App\Jobs\GenerateExpectedTransfersJob;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\FundingAgreementInstallment;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use App\Notifications\RecipientResolver;
use App\Support\Tranches;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * An approved-by-donor agreement with a stored schedule and no transfers yet:
 * rows are inserted below the action so the generator is the only writer.
 *
 * @param  array<string, mixed>  $agreement
 */
function scheduledAgreement(int $installments = 3, array $agreement = []): DonorProposal
{
    $proposal = Proposal::factory()->running()->create(['planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31']);
    $created = DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'budget' => '3000.00', 'currency' => Currency::Eur, ...$agreement]);

    foreach (range(1, $installments) as $sequence) {
        FundingAgreementInstallment::factory()->create([
            'funding_agreement_id' => $created->id,
            'sequence' => $sequence,
            'amount' => '1000.00',
            'scheduled_date' => sprintf('2026-%02d-15', $sequence * 2),
        ]);
    }

    return $created;
}

test('three installments produce three auto transfers with empty actuals', function () {
    $agreement = scheduledAgreement();

    expect(app(GenerateExpectedTransfers::class)->handle($agreement->id))->toBe(3);

    $transfers = TransfersTracking::query()->orderBy('scheduled_date')->get();

    expect($transfers)->toHaveCount(3)
        ->and($transfers->pluck('source')->unique()->all())->toBe(['auto'])
        ->and($transfers->pluck('payment_amount')->all())->toBe(['1000.00', '1000.00', '1000.00'])
        ->and($transfers->pluck('amount_transfered_currency')->map->value->unique()->all())->toBe(['EUR'])
        ->and($transfers->pluck('scheduled_date')->map->toDateString()->all())->toBe(['2026-02-15', '2026-04-15', '2026-06-15'])
        ->and($transfers->pluck('installment_id')->sort()->values()->all())->toBe($agreement->installments()->pluck('id')->sort()->values()->all());

    foreach ($transfers as $transfer) {
        expect($transfer->transfer_date)->toBeNull()
            ->and($transfer->arrival_date)->toBeNull()
            ->and($transfer->receive_date)->toBeNull()
            ->and($transfer->amount_received)->toBeNull()
            ->and($transfer->net_amount_received)->toBeNull()
            ->and($transfer->account_id)->toBeNull()
            ->and($transfer->ar_status)->toBe(ArStatus::Pending)
            ->and((float) $transfer->fees)->toBe(0.0)
            ->and((float) $transfer->exchange_rate)->toBe(1.0)
            ->and($transfer->state())->not->toBe('received');
    }
});

test('running again, or receiving the event twice, creates nothing new', function () {
    $agreement = scheduledAgreement();
    $generate = app(GenerateExpectedTransfers::class);

    $generate->handle($agreement->id);

    expect($generate->handle($agreement->id))->toBe(0);

    event(new FundingAgreementRecorded($agreement->id, $agreement->proposal_id, null));
    event(new FundingAgreementRecorded($agreement->id, $agreement->proposal_id, null));
    GenerateExpectedTransfersJob::dispatch($agreement->id);

    expect(TransfersTracking::query()->count())->toBe(3);
});

test('adding a fourth installment adds exactly one transfer', function () {
    $agreement = scheduledAgreement();
    app(GenerateExpectedTransfers::class)->handle($agreement->id);
    $this->actingAs(userWithRole('Financial'));

    $rows = $agreement->installments()->get()->map(fn (FundingAgreementInstallment $installment): array => [
        'sequence' => $installment->sequence,
        'amount' => $installment->amount,
        'currency' => 'EUR',
        'scheduled_date' => $installment->scheduled_date->toDateString(),
    ])->all();
    $rows[] = ['sequence' => 4, 'amount' => '500.00', 'currency' => 'EUR', 'scheduled_date' => '2026-08-15'];

    app(SaveAgreementInstallments::class)->handle($agreement, $rows);

    expect(TransfersTracking::query()->count())->toBe(4)
        ->and(TransfersTracking::query()->where('payment_amount', '500.00')->count())->toBe(1);
});

test('nothing is generated for a proposal no donor approved', function () {
    $agreement = scheduledAgreement();
    $agreement->proposal->forceFill(['status' => 'submitted'])->save();

    expect(app(GenerateExpectedTransfers::class)->handle($agreement->id))->toBe(0)
        ->and(TransfersTracking::query()->count())->toBe(0);
});

test('nothing is generated for a self-funded agreement', function () {
    $internal = Donor::factory()->create(['is_internal' => true]);
    $proposal = Proposal::factory()->running()->create(['donor_id' => $internal->id]);
    $agreement = DonorProposal::factory()->create(['proposal_id' => $proposal->id]);
    FundingAgreementInstallment::factory()->create(['funding_agreement_id' => $agreement->id]);

    expect(app(GenerateExpectedTransfers::class)->handle($agreement->id))->toBe(0)
        ->and(TransfersTracking::query()->count())->toBe(0);
});

test('an agreement with manual transfers and no schedule generates nothing and keeps its rows', function () {
    $agreement = DonorProposal::factory()->create();
    $agreement->proposal->forceFill(['status' => 'approved'])->save();
    $manual = TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id]);

    expect(app(GenerateExpectedTransfers::class)->handle($agreement->id))->toBe(0)
        ->and(TransfersTracking::query()->sole()->is($manual))->toBeTrue()
        ->and($manual->fresh()->source)->toBe('manual')
        ->and($manual->fresh()->installment_id)->toBeNull();
});

test('manual transfers beside a schedule are never duplicated or touched', function () {
    $agreement = scheduledAgreement(2);
    $manual = TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'payment_amount' => '1000.00', 'scheduled_date' => '2026-02-15']);

    app(GenerateExpectedTransfersJob::class, ['agreementId' => $agreement->id])->handle(app(GenerateExpectedTransfers::class));

    expect(TransfersTracking::query()->count())->toBe(3)
        ->and($manual->fresh()->source)->toBe('manual');
});

test('an installment can never have two transfers', function () {
    $agreement = scheduledAgreement(1);
    app(GenerateExpectedTransfers::class)->handle($agreement->id);
    $installmentId = $agreement->installments()->value('id');

    expect(fn () => TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'installment_id' => $installmentId, 'source' => 'auto']))
        ->toThrow(QueryException::class);
});

test('the generator works with nobody signed in and records one amount-free movement', function () {
    $agreement = scheduledAgreement();
    Auth::logout();

    app(GenerateExpectedTransfers::class)->handle($agreement->id);

    $activities = Activity::query()->where('event', 'transfer.generated')->get();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->actor_id)->toBeNull()
        ->and($activities->first()->module)->toBe('transfers')
        ->and(json_encode($activities->first()->properties))->not->toContain('1000')
        ->and(json_encode($activities->first()->properties))->toContain('"count":3');
    expect(Activity::query()->where('event', 'transfer.scheduled')->count())->toBe(0);
});

test('transfer.generated reaches Finance and General Management and carries no amount', function () {
    $finance = userWithRole('Financial');
    $general = userWithRole('General Manager');
    $outsider = userWithRole('Media Team');
    $agreement = scheduledAgreement();

    app(GenerateExpectedTransfers::class)->handle($agreement->id);

    $activity = Activity::query()->where('event', 'transfer.generated')->sole();
    $recipients = array_keys(app(RecipientResolver::class)->resolve($activity)->priorities);

    expect($recipients)->toContain($finance->id, $general->id)->not->toContain($outsider->id)
        ->and(json_encode($activity->toArray()))->not->toContain('1000.00');
});

test('state and timing agree that a sent transfer is in transit until it has arrived', function () {
    $this->travelTo('2026-07-20 10:00:00');
    $agreement = scheduledAgreement(0);
    $make = fn (array $attributes) => TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'amount_received' => null,
        'net_amount_received' => null,
        ...$attributes,
    ]);

    $make(['scheduled_date' => '2026-07-10', 'transfer_date' => '2026-07-10']);                                  // sent, on its way
    $make(['scheduled_date' => '2026-07-10', 'transfer_date' => '2026-07-10', 'arrival_date' => '2026-07-14']);  // arrived at the bank, not yet received
    $make(['scheduled_date' => '2026-07-01']);                                                                   // unsent and past due
    $make(['scheduled_date' => '2026-08-01']);                                                                   // unsent and ahead
    $make(['scheduled_date' => '2026-06-01', 'transfer_date' => '2026-06-01', 'arrival_date' => '2026-06-03', 'receive_date' => '2026-06-05', 'ar_status' => ArStatus::Received, 'net_amount_received' => '900.00']);
    $make(['scheduled_date' => '2026-06-01', 'ar_status' => ArStatus::Failed]);

    $inPhp = TransfersTracking::query()->get()->groupBy(fn (TransfersTracking $transfer): string => $transfer->state())->map->count()->sortKeys()->all();
    $inSql = collect(TransfersTracking::STATES)
        ->mapWithKeys(fn (string $state): array => [$state => TransfersTracking::query()->whereRaw(TransfersTracking::stateSql($state))->count()])
        ->filter()->sortKeys()->all();

    expect($inSql)->toBe($inPhp)->toBe(['failed' => 1, 'late' => 1, 'received' => 1, 'transit' => 2, 'upcoming' => 1]);

    $timing = fn (?string $arrival = null): ?string => Tranches::timing(
        TransfersTracking::query()->where('transfer_date', '2026-07-10')->where('arrival_date', $arrival)->sole(),
    );

    expect($timing())->toBe(trans_choice('transfers.in_transit_for_count_days', 10, ['count' => 10]))
        ->and($timing('2026-07-14'))->toBe(trans_choice('transfers.in_transit_for_count_days', 4, ['count' => 4]));
});

test('the transfer form validates sent <= arrived <= received on the field that breaks the order', function () {
    $agreement = scheduledAgreement(0);
    $user = userWithRole('Financial');
    $form = fn () => Livewire::actingAs($user)->test('pages::transfers.index')
        ->call('openTransferCreate', $agreement->id)
        ->set('paymentAmount', '100')
        ->set('transferArStatus', 'received')
        ->set('netAmountReceived', '90');

    $form()->set('transferDate', '2026-07-10')->set('arrivalDate', '2026-07-05')->set('receiveDate', '2026-07-12')
        ->call('saveTransfer')
        ->assertHasErrors(['arrivalDate'])
        ->assertSee(__('funding_agreements.arrival_before_transfer'));

    $form()->set('transferDate', '2026-07-10')->set('arrivalDate', '2026-07-14')->set('receiveDate', '2026-07-12')
        ->call('saveTransfer')
        ->assertHasErrors(['receiveDate'])
        ->assertSee(__('funding_agreements.transfer_dates_order'));

    $form()->set('arrivalDate', '2026-07-14')->set('receiveDate', '2026-07-15')
        ->call('saveTransfer')
        ->assertHasErrors(['transferDate']);

    $form()->set('transferDate', '2026-07-10')->set('arrivalDate', '2026-07-14')->set('receiveDate', '2026-07-15')
        ->call('saveTransfer')
        ->assertHasNoErrors();

    expect(TransfersTracking::query()->sole()->arrival_date->toDateString())->toBe('2026-07-14');
});
