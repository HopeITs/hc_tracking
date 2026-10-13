<?php

use App\Enums\Currency;
use App\Enums\DonorType;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Proposal;

test('a donor carries a contactable profile', function () {
    $donor = Donor::factory()->ofType(DonorType::Bilateral)->create([
        'name' => 'GIZ',
        'contact_person' => 'Layla Haddad',
        'email' => 'grants@giz.example',
        'phone' => '+49 30 123 4567',
        'country' => 'Germany',
        'notes' => 'Framework agreement renewed annually.',
    ])->fresh();

    expect($donor->type)->toBe(DonorType::Bilateral)
        ->and($donor->contact_person)->toBe('Layla Haddad')
        ->and($donor->email)->toBe('grants@giz.example')
        ->and($donor->phone)->toBe('+49 30 123 4567')
        ->and($donor->country)->toBe('Germany')
        ->and($donor->notes)->toBe('Framework agreement renewed annually.');
});

test('a donor known only by name is a valid record', function () {
    $donor = Donor::factory()->unprofiled()->create(['name' => 'Anonymous Trust'])->fresh();

    // A funder recorded from a grant letter alone genuinely has no
    // classification yet, and that state must stay reachable.
    expect($donor->type)->toBeNull()
        ->and($donor->contact_person)->toBeNull()
        ->and($donor->country)->toBeNull();
});

test('two donors may share a name because donors name is not unique', function () {
    Donor::factory()->create(['name' => 'Country Office']);
    Donor::factory()->create(['name' => 'Country Office']);

    expect(Donor::query()->where('name', 'Country Office')->count())->toBe(2);
});

test('a donor commitment is totalled per currency and never across them', function () {
    $donor = Donor::factory()->create();

    DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['donor_id' => $donor->id])->id,
        'budget' => '500000.00',
        'currency' => Currency::Usd,
    ]);
    DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['donor_id' => $donor->id])->id,
        'budget' => '250000.00',
        'currency' => Currency::Eur,
    ]);

    $byCurrency = $donor->donorProposals
        ->groupBy(fn (DonorProposal $agreement): string => $agreement->currency->value)
        ->map(fn ($group): float => (float) $group->sum(fn (DonorProposal $agreement): float => (float) $agreement->budget));

    // USD and EUR have no conversion rate anywhere in this system, so a single
    // 750,000 total would be a fabricated number.
    expect($byCurrency->all())->toBe(['USD' => 500000.0, 'EUR' => 250000.0]);
});

test('a donor agreement currency is a case here and a raw string through the pivot', function () {
    $donor = Donor::factory()->create();
    $proposal = Proposal::factory()->create();

    Proposal::query()->whereKey($proposal->id)->update(['donor_id' => $donor->id]);
    DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => $proposal->id,
        'currency' => Currency::Usd,
    ]);

    // belongsToMany builds a generic Pivot that carries no casts, so the two
    // paths return different types for one column. Never compare the pivot
    // value to a Currency case with ===.
    expect($donor->donorProposals->first()->currency)->toBe(Currency::Usd)
        ->and($donor->proposals()->first()->pivot->currency)->toBe('USD');
});
