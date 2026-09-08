<?php

namespace Database\Factories;

use App\Enums\ArStatus;
use App\Models\Account;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransfersTracking>
 */
class TransfersTrackingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The three currency columns, `fees`, `exchange_rate` and `ar_status` are
     * left to the column defaults, so a tranche starts as a pending USD payment
     * at par with no fees. `account_id` stays null until an account is assigned.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $paymentAmount = fake()->randomFloat(2, 10000, 500000);

        return [
            'donor_proposal_id' => DonorProposal::factory(),
            'payment_amount' => $paymentAmount,
            'scheduled_date' => fake()->dateTimeBetween('-1 year', '+1 year')->format('Y-m-d'),
            'amount_received' => $paymentAmount,
            'net_amount_received' => $paymentAmount,
        ];
    }

    /**
     * Indicate that the tranche has landed in an account.
     *
     * Every derived column is a closure rather than a value read from
     * `$attributes` directly. Laravel resolves these after all states AND the
     * array passed to create() have been merged, so the fee and the net stay
     * consistent with whatever payment_amount the caller supplies. Reading
     * `$attributes` in a plain state closure would see only the definition and
     * silently compute the fee from a throwaway random figure.
     */
    public function received(Account|int|null $account = null): static
    {
        return $this->state([
            'account_id' => $account instanceof Account ? $account->getKey() : ($account ?? Account::factory()),
            'transfer_date' => fn (array $attributes): string => $attributes['scheduled_date'],
            'fees' => fn (array $attributes): float => $this->feeFor($attributes),
            'net_amount_received' => fn (array $attributes): float => round(
                (float) $attributes['amount_received'] - $this->feeFor($attributes),
                2
            ),
            'receive_date' => fn (array $attributes): string => $attributes['scheduled_date'],
            'ar_status' => ArStatus::Received,
        ]);
    }

    /**
     * The transfer fee charged on a tranche: one percent of the payment.
     *
     * Recomputed rather than read back from the `fees` key, because that key is
     * introduced by the state and is resolved after `net_amount_received`.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function feeFor(array $attributes): float
    {
        return round((float) $attributes['payment_amount'] * 0.01, 2);
    }
}
