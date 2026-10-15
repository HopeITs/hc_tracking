<?php

use App\Actions\FundingAgreements\SaveAgreementInstallments;
use App\Enums\Currency;
use App\Events\FundingAgreementRecorded;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\FundingAgreementInstallment;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * A donor-approved proposal with a fixed 2026 term and its agreement.
 *
 * @param  array<string, mixed>  $agreement
 */
function termAgreement(array $agreement = []): DonorProposal
{
    $proposal = Proposal::factory()->running()->create([
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
    ]);

    return DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'budget' => '3000.00', 'currency' => Currency::Usd, ...$agreement]);
}

/**
 * @return list<array{sequence: int, amount: string, currency: string, scheduled_date: string}>
 */
function schedule(int $count = 3, string $amount = '1000.00', string $currency = 'USD'): array
{
    return collect(range(1, $count))->map(fn (int $sequence): array => [
        'sequence' => $sequence,
        'amount' => $amount,
        'currency' => $currency,
        'scheduled_date' => sprintf('2026-%02d-15', $sequence * 2),
    ])->all();
}

test('Finance and the Public Relations Manager save a schedule, MEAL cannot', function () {
    $agreement = termAgreement();

    foreach (['Financial', 'Public Relations Manager'] as $role) {
        $this->actingAs(userWithRole($role));
        app(SaveAgreementInstallments::class)->handle($agreement, schedule(2));
    }

    expect($agreement->installments()->count())->toBe(2);

    $this->actingAs(userWithRole('MEAL'));

    expect(fn () => app(SaveAgreementInstallments::class)->handle($agreement, schedule(3)))->toThrow(AuthorizationException::class)
        ->and($agreement->installments()->count())->toBe(2);
});

test('three installments are stored in sequence in the agreement currency', function () {
    $agreement = termAgreement(['currency' => Currency::Eur]);
    $this->actingAs(userWithRole('Financial'));

    app(SaveAgreementInstallments::class)->handle($agreement, schedule(3, '1000.00', 'EUR'));

    $stored = $agreement->installments()->get();

    expect($stored->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($stored->pluck('amount')->all())->toBe(['1000.00', '1000.00', '1000.00'])
        ->and($stored->pluck('currency')->map->value->unique()->all())->toBe(['EUR'])
        ->and($stored->first()->scheduled_date->toDateString())->toBe('2026-02-15');
});

test('an empty schedule is refused', function () {
    $agreement = termAgreement();
    $this->actingAs(userWithRole('Financial'));

    try {
        app(SaveAgreementInstallments::class)->handle($agreement, []);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['installments'][0])->toBe(__('funding_agreements.installments_required'));
    }
});

test('a date outside the agreement term, a wrong currency and a bad amount are refused together', function () {
    $agreement = termAgreement();
    $this->actingAs(userWithRole('Financial'));

    $rows = schedule(3);
    $rows[0]['scheduled_date'] = '2027-03-01';
    $rows[1]['currency'] = 'EUR';
    $rows[2]['amount'] = '0';

    try {
        app(SaveAgreementInstallments::class)->handle($agreement, $rows);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKeys(['installments.0.scheduled_date', 'installments.1.currency', 'installments.2.amount'])
            ->and($exception->errors()['installments.0.scheduled_date'][0])->toBe(__('funding_agreements.installment_outside_term'));
    }

    expect(FundingAgreementInstallment::query()->count())->toBe(0);
});

test('the sequence must run 1 to n', function () {
    $agreement = termAgreement();
    $this->actingAs(userWithRole('Financial'));
    $rows = schedule(2);
    $rows[1]['sequence'] = 4;

    expect(fn () => app(SaveAgreementInstallments::class)->handle($agreement, $rows))->toThrow(ValidationException::class);
});

test('removing an installment deletes its untouched generated transfer', function () {
    $agreement = termAgreement();
    $this->actingAs(userWithRole('Financial'));
    app(SaveAgreementInstallments::class)->handle($agreement, schedule(3));
    expect(TransfersTracking::query()->count())->toBe(3);

    app(SaveAgreementInstallments::class)->handle($agreement, schedule(2));

    expect($agreement->installments()->count())->toBe(2)
        ->and(TransfersTracking::query()->count())->toBe(2);
});

test('removing an installment whose transfer has actual data is refused', function (string $column, mixed $value) {
    $agreement = termAgreement();
    $this->actingAs(userWithRole('Financial'));
    app(SaveAgreementInstallments::class)->handle($agreement, schedule(3));

    TransfersTracking::query()->where('installment_id', $agreement->installments()->where('sequence', 3)->value('id'))->update([$column => $value]);

    try {
        app(SaveAgreementInstallments::class)->handle($agreement, schedule(2));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['installments'][0])->toBe(__('funding_agreements.installment_has_transfer_data', ['sequence' => 3]));
    }

    expect($agreement->installments()->count())->toBe(3)
        ->and(TransfersTracking::query()->count())->toBe(3);
})->with([
    'transfer date' => ['transfer_date', '2026-06-01'],
    'arrival date' => ['arrival_date', '2026-06-05'],
    'receive date' => ['receive_date', '2026-06-09'],
    'received amount' => ['amount_received', '900.00'],
]);

test('editing an existing installment leaves its generated transfer alone', function () {
    $agreement = termAgreement();
    $this->actingAs(userWithRole('Financial'));
    app(SaveAgreementInstallments::class)->handle($agreement, schedule(2));

    $rows = schedule(2);
    $rows[0]['amount'] = '1500.00';
    app(SaveAgreementInstallments::class)->handle($agreement, $rows);

    expect($agreement->installments()->where('sequence', 1)->value('amount'))->toBe('1500.00')
        ->and(TransfersTracking::query()->where('installment_id', $agreement->installments()->where('sequence', 1)->value('id'))->value('payment_amount'))->toBe('1000.00');
});

test('the form saves the agreement and its schedule together and generates the transfers', function () {
    $user = userWithRole('Financial');
    $proposal = Proposal::factory()->running()->create(['planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31']);

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->call('openAgreementCreate')
        ->set('agreementProposalId', (string) $proposal->id)
        ->set('budget', '3000.00')
        ->set('agreementCurrency', 'USD')
        ->call('addInstallment')->call('addInstallment')->call('addInstallment')
        ->set('installments.0', ['amount' => '1000', 'scheduled_date' => '2026-02-15'])
        ->set('installments.1', ['amount' => '1000', 'scheduled_date' => '2026-04-15'])
        ->set('installments.2', ['amount' => '1000', 'scheduled_date' => '2026-06-15'])
        ->call('saveAgreement')
        ->assertHasNoErrors();

    $agreement = DonorProposal::query()->sole();

    expect($agreement->installments()->count())->toBe(3)
        ->and($agreement->transfers()->where('source', 'auto')->count())->toBe(3);
});

test('a schedule that does not add up warns and saves only after an explicit yes', function () {
    $user = userWithRole('Financial');
    $proposal = Proposal::factory()->running()->create(['planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31']);

    $page = Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->call('openAgreementCreate')
        ->set('agreementProposalId', (string) $proposal->id)
        ->set('budget', '3000.00')
        ->set('agreementCurrency', 'USD')
        ->call('addInstallment')
        ->set('installments.0', ['amount' => '1000', 'scheduled_date' => '2026-02-15'])
        ->assertSee(__('funding_agreements.installments_sum_mismatch', ['sum' => '1,000.00']), false)
        ->call('saveAgreement')
        ->assertHasErrors(['confirmInstallmentSum']);

    expect(DonorProposal::query()->count())->toBe(0);

    $page->set('confirmInstallmentSum', true)->call('saveAgreement')->assertHasNoErrors();

    expect(DonorProposal::query()->sole()->installments()->count())->toBe(1);
});

test('changing an amount after confirming asks again', function () {
    $user = userWithRole('Financial');
    $proposal = Proposal::factory()->running()->create(['planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31']);

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->call('openAgreementCreate')
        ->set('agreementProposalId', (string) $proposal->id)
        ->set('budget', '3000.00')
        ->call('addInstallment')
        ->set('confirmInstallmentSum', true)
        ->set('installments.0.amount', '5')
        ->assertSet('confirmInstallmentSum', false);
});

test('the form refuses a date outside the term on the offending row', function () {
    $user = userWithRole('Financial');
    $proposal = Proposal::factory()->running()->create(['planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31']);

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->call('openAgreementCreate')
        ->set('agreementProposalId', (string) $proposal->id)
        ->set('budget', '1000.00')
        ->set('agreementCurrency', 'USD')
        ->call('addInstallment')
        ->set('installments.0', ['amount' => '1000', 'scheduled_date' => '2030-01-01'])
        ->call('saveAgreement')
        ->assertHasErrors(['installments.0.scheduled_date'])
        ->assertSee(__('funding_agreements.installment_outside_term'));

    expect(DonorProposal::query()->count())->toBe(0);
});

test('clearing an existing schedule through the form is refused', function () {
    $agreement = termAgreement();
    $user = userWithRole('Financial');
    $this->actingAs($user);
    app(SaveAgreementInstallments::class)->handle($agreement, schedule(2));

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->call('openAgreementEdit', $agreement->id)
        ->set('installments', [])
        ->call('saveAgreement')
        ->assertHasErrors(['installments']);

    expect($agreement->installments()->count())->toBe(2);
});

test('a legacy agreement with manual transfers is saved without a schedule and its rows are untouched', function () {
    $agreement = termAgreement();
    $manual = TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'payment_amount' => '700.00']);

    Livewire::actingAs(userWithRole('Financial'))
        ->test('pages::funding-agreements.index')
        ->call('openAgreementEdit', $agreement->id)
        ->set('budget', '3500.00')
        ->call('saveAgreement')
        ->assertHasNoErrors();

    expect($agreement->installments()->count())->toBe(0)
        ->and(TransfersTracking::query()->sole()->is($manual))->toBeTrue()
        ->and($manual->fresh()->source)->toBe('manual')
        ->and($manual->fresh()->payment_amount)->toBe('700.00');
});

test('the edit form lists the stored schedule', function () {
    $agreement = termAgreement();
    $this->actingAs(userWithRole('Financial'));
    app(SaveAgreementInstallments::class)->handle($agreement, schedule(2));

    Livewire::actingAs(userWithRole('Financial'))
        ->test('pages::funding-agreements.index')
        ->call('openAgreementEdit', $agreement->id)
        ->assertSet('installments', [
            ['amount' => '1000.00', 'scheduled_date' => '2026-02-15'],
            ['amount' => '1000.00', 'scheduled_date' => '2026-04-15'],
        ]);
});

test('FundingAgreementRecorded fires once after a successful save and never for a refused one', function () {
    $fired = [];
    Event::listen(FundingAgreementRecorded::class, function (FundingAgreementRecorded $event) use (&$fired): void {
        $fired[] = $event->proposalId;
    });
    $user = userWithRole('Financial');
    $approved = Proposal::factory()->running()->create();
    $concept = Proposal::factory()->create();

    Livewire::actingAs($user)->test('pages::funding-agreements.index')
        ->call('openAgreementCreate')->set('agreementProposalId', (string) $concept->id)->set('budget', '10')->call('saveAgreement')
        ->assertHasErrors(['agreementProposalId']);

    expect($fired)->toBe([]);

    Livewire::actingAs($user)->test('pages::funding-agreements.index')
        ->call('openAgreementCreate')->set('agreementProposalId', (string) $approved->id)->set('budget', '10')->call('saveAgreement')
        ->assertHasNoErrors();

    expect($fired)->toBe([$approved->id]);
});

test('an agreement saved with a schedule that fails validation leaves nothing behind', function () {
    $user = userWithRole('Financial');
    $proposal = Proposal::factory()->running()->create(['planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31']);

    Livewire::actingAs($user)
        ->test('pages::funding-agreements.index')
        ->call('openAgreementCreate')
        ->set('agreementProposalId', (string) $proposal->id)
        ->set('budget', '100')
        ->call('addInstallment')
        ->set('installments.0', ['amount' => '100', 'scheduled_date' => ''])
        ->call('saveAgreement')
        ->assertHasErrors(['installments.0.scheduled_date']);

    expect(DonorProposal::query()->count())->toBe(0);
});

test('a user without update rights cannot reach the schedule through the form', function () {
    $viewer = User::factory()->role('Managers')->create()->givePermissionTo(['funding-agreements.view', 'funding-agreements.create']);
    $proposal = Proposal::factory()->running()->create(['planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31']);

    Livewire::actingAs($viewer)
        ->test('pages::funding-agreements.index')
        ->call('openAgreementCreate')
        ->set('agreementProposalId', (string) $proposal->id)
        ->set('budget', '100')
        ->call('addInstallment')
        ->set('installments.0', ['amount' => '100', 'scheduled_date' => '2026-03-01'])
        ->call('saveAgreement')
        ->assertForbidden();

    expect(DonorProposal::query()->count())->toBe(0);
});

test('a schedule can be kept on a self-funded agreement but never produces transfers', function () {
    $internal = Donor::factory()->create(['is_internal' => true]);
    $proposal = Proposal::factory()->running()->create(['donor_id' => $internal->id, 'planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31']);
    $agreement = DonorProposal::factory()->create(['proposal_id' => $proposal->id]);
    $this->actingAs(userWithRole('Financial'));

    app(SaveAgreementInstallments::class)->handle($agreement, schedule(2));

    expect($agreement->installments()->count())->toBe(2)
        ->and(TransfersTracking::query()->count())->toBe(0);
});
