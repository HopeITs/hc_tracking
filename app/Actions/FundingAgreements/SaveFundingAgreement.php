<?php

declare(strict_types=1);

namespace App\Actions\FundingAgreements;

use App\Events\FundingAgreementRecorded;
use App\Models\DonorProposal;
use App\Models\Proposal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Record a new funding agreement or update an existing one from
 * already-validated input.
 *
 * An agreement is recorded only for a donor-approved proposal that has none,
 * and its donor is always the proposal's (contracts.md §6, D-31): the request
 * never names a donor, and an update never moves the agreement to another
 * proposal or donor.
 */
class SaveFundingAgreement
{
    /**
     * The unique index that keeps one agreement per proposal (T03).
     */
    public const ONE_PER_PROPOSAL_INDEX = 'donor_proposal_proposal_id_unique';

    /**
     * @param  array<string, mixed>  $validated  keyed by the agreement form's property names
     *
     * @throws ValidationException
     */
    public function handle(?DonorProposal $existing, array $validated): DonorProposal
    {
        Gate::authorize($existing === null ? 'funding-agreements.create' : 'funding-agreements.update');

        return $existing === null ? $this->create($validated) : $this->update($existing, $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function create(array $validated): DonorProposal
    {
        try {
            return DB::transaction(function () use ($validated): DonorProposal {
                // The proposal row is locked first so the checks below and the
                // insert see the same row; the unique index stays the guarantee.
                $proposal = Proposal::query()
                    ->with('donor:id,slug')
                    ->select(['id', 'slug', 'donor_id', 'status'])
                    ->lockForUpdate()
                    ->find((int) $validated['agreementProposalId']);

                if ($proposal === null || ! $proposal->status->isApprovedByDonor()) {
                    throw ValidationException::withMessages([
                        'agreementProposalId' => __('funding_agreements.proposal_not_donor_approved'),
                    ]);
                }

                if (DonorProposal::query()->where('proposal_id', $proposal->id)->exists()) {
                    throw $this->alreadyRecorded();
                }

                $agreement = new DonorProposal;
                $agreement->setRelation('donor', $proposal->donor);
                $agreement->setRelation('proposal', $proposal);
                $agreement->fill([
                    'donor_id' => $proposal->donor_id,
                    'proposal_id' => $proposal->id,
                    ...$this->fields($validated),
                ])->save();

                $actorId = Auth::id();

                FundingAgreementRecorded::dispatch($agreement->id, $proposal->id, $actorId === null ? null : (int) $actorId);

                return $agreement;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), self::ONE_PER_PROPOSAL_INDEX)) {
                throw $exception;
            }

            throw $this->alreadyRecorded();
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function update(DonorProposal $existing, array $validated): DonorProposal
    {
        $existing->fill($this->fields($validated))->save();

        return $existing;
    }

    /**
     * The editable columns. Donor and proposal are deliberately absent.
     *
     * @param  array<string, mixed>  $validated
     * @return array{budget: mixed, currency: mixed, external_project_number: string|null}
     */
    private function fields(array $validated): array
    {
        return [
            'budget' => $validated['budget'],
            'currency' => $validated['agreementCurrency'],
            'external_project_number' => $validated['externalProjectNumber'] === '' ? null : $validated['externalProjectNumber'],
        ];
    }

    private function alreadyRecorded(): ValidationException
    {
        return ValidationException::withMessages([
            'agreementProposalId' => __('funding_agreements.agreement_exists'),
        ]);
    }
}
