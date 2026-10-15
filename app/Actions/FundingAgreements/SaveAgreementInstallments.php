<?php

declare(strict_types=1);

namespace App\Actions\FundingAgreements;

use App\Jobs\GenerateExpectedTransfersJob;
use App\Models\DonorProposal;
use App\Models\FundingAgreementInstallment;
use App\Notifications\ActivityRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Replace an agreement's installment schedule (contracts.md §6, D-31).
 *
 * The schedule is matched to what is stored by `sequence`. A new sequence adds
 * an installment and, once the generator runs, one expected transfer. A
 * sequence that is gone is removed with its generated transfer, unless Finance
 * already recorded something on it. Existing installments keep their
 * generated rows untouched, and manual transfers are never looked at.
 */
final class SaveAgreementInstallments
{
    /** funding_agreement_installments.amount is DECIMAL(14,2). */
    public const MAX_AMOUNT = 999999999999.99;

    /**
     * @param  list<array{sequence: int, amount: string, currency: string, scheduled_date: string}>  $installments
     *
     * @throws ValidationException
     */
    public function handle(DonorProposal $agreement, array $installments): void
    {
        Gate::authorize('funding-agreements.update');

        DB::transaction(function () use ($agreement, $installments): void {
            $locked = DonorProposal::query()
                ->with('proposal:id,planned_date_begin,planned_date_end,actual_date_begin,actual_date_end')
                ->lockForUpdate()
                ->findOrFail($agreement->id);

            $rows = $this->validated($locked, $installments);
            $stored = $locked->installments()->with('transfer')->get()->keyBy('sequence');
            $removed = $stored->diffKeys($rows);

            $this->guardRemovals($removed);

            foreach ($removed as $installment) {
                $installment->transfer?->delete();
                $installment->delete();
            }

            foreach ($rows as $sequence => $row) {
                $installment = $stored->get($sequence) ?? new FundingAgreementInstallment(['funding_agreement_id' => $locked->id, 'sequence' => $sequence]);
                $installment->fill($row)->save();
            }

            app(ActivityRecorder::class)->record($locked, 'donor_proposal.updated', [], ['field' => 'installments', 'count' => count($rows)], notify: false);

            GenerateExpectedTransfersJob::dispatch($locked->id)->afterCommit();
        });
    }

    /**
     * The sum of the rows' amounts to two decimals.
     *
     * @param  list<array{amount?: mixed}>  $installments
     */
    public static function sumOf(array $installments): string
    {
        $cents = 0;

        foreach ($installments as $row) {
            $cents += (int) round((float) ($row['amount'] ?? 0) * 100);
        }

        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Whether the rows add up to something other than the budget: a warning
     * the user confirms, never a block.
     *
     * @param  list<array{amount?: mixed}>  $installments
     */
    public static function sumDiffersFromBudget(string $budget, array $installments): bool
    {
        return (int) round((float) $budget * 100) !== (int) round((float) self::sumOf($installments) * 100);
    }

    /**
     * Every rule of the schedule, applied before anything is written.
     *
     * @param  list<array<string, mixed>>  $installments
     * @return array<int, array{amount: string, currency: string, scheduled_date: string}> sequence => columns
     *
     * @throws ValidationException
     */
    private function validated(DonorProposal $agreement, array $installments): array
    {
        if ($installments === []) {
            throw ValidationException::withMessages(['installments' => __('funding_agreements.installments_required')]);
        }

        // The term is the proposal's: actual dates once approved, else the planned ones.
        $proposal = $agreement->proposal;
        $termStart = $proposal->actual_date_begin ?? $proposal->planned_date_begin;
        $termEnd = $proposal->actual_date_end ?? $proposal->planned_date_end;

        $errors = [];
        $rows = [];

        foreach ($installments as $index => $input) {
            if ((int) ($input['sequence'] ?? 0) !== $index + 1) {
                $errors['installments'] = __('funding_agreements.installment_sequence_invalid');
            }

            $amount = $input['amount'] ?? '';

            if (! is_numeric($amount) || (float) $amount <= 0 || (float) $amount > self::MAX_AMOUNT) {
                $errors["installments.{$index}.amount"] = __('funding_agreements.installment_amount_invalid');
            }

            if (($input['currency'] ?? null) !== $agreement->currency->value) {
                $errors["installments.{$index}.currency"] = __('funding_agreements.installment_currency_mismatch');
            }

            $date = $this->parseDate($input['scheduled_date'] ?? null);

            if ($date === null) {
                $errors["installments.{$index}.scheduled_date"] = __('funding_agreements.installment_date_invalid');
            } elseif ($date->lt($termStart) || $date->gt($termEnd)) {
                $errors["installments.{$index}.scheduled_date"] = __('funding_agreements.installment_outside_term');
            }

            $rows[$index + 1] = [
                'amount' => is_numeric($amount) ? number_format((float) $amount, 2, '.', '') : '0.00',
                'currency' => (string) ($input['currency'] ?? ''),
                'scheduled_date' => $date?->toDateString() ?? '',
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $date === null || $date->toDateString() !== $value ? null : $date;
    }

    /**
     * A removed installment whose transfer Finance already filled in stays.
     *
     * @param  Collection<int, FundingAgreementInstallment>  $removed
     *
     * @throws ValidationException
     */
    private function guardRemovals(Collection $removed): void
    {
        foreach ($removed as $installment) {
            if ($installment->transfer?->hasActualData()) {
                throw ValidationException::withMessages([
                    'installments' => __('funding_agreements.installment_has_transfer_data', ['sequence' => $installment->sequence]),
                ]);
            }
        }
    }
}
