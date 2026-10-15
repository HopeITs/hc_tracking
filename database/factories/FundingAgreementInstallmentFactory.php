<?php

namespace Database\Factories;

use App\Models\DonorProposal;
use App\Models\FundingAgreementInstallment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FundingAgreementInstallment>
 */
class FundingAgreementInstallmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The currency is the agreement's own, as the schedule requires.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'funding_agreement_id' => DonorProposal::factory(),
            'sequence' => 1,
            'amount' => fake()->randomFloat(2, 1000, 50000),
            'currency' => fn (array $attributes) => DonorProposal::query()->findOrFail($attributes['funding_agreement_id'])->currency,
            'scheduled_date' => fake()->dateTimeBetween('now', '+6 months')->format('Y-m-d'),
        ];
    }
}
