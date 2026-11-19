<?php

declare(strict_types=1);

namespace App\Actions\Results;

use App\Enums\AssumptionStatus;
use App\Models\Assumption;
use App\Models\Proposal;
use App\Models\Result;
use App\Support\ProposalFramework;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Add, change or delete an assumption of a proposal's logical framework.
 *
 * The result must belong to the same proposal framework. Writing needs
 * results.create (add) or results.update (change, delete), and the same
 * structural lock as the results themselves (after Submitted, only holders
 * of results.update).
 */
final class SaveAssumption
{
    /**
     * @param  array{statement?: mixed, status?: mixed, result_id?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function handle(Proposal $proposal, ?Assumption $existing, array $input): Assumption
    {
        Gate::authorize($existing === null ? 'results.create' : 'results.update');
        $this->guardLock($proposal);

        $validated = Validator::make($input, [
            'statement' => ['required', 'string', 'max:2000'],
            'status' => ['required', Rule::in(AssumptionStatus::values())],
            'result_id' => ['required', 'integer', Rule::exists(Result::class, 'id')->where('proposal_id', $proposal->id)],
        ], [
            'statement.required' => __('proposals.framework.assumption_required'),
            'result_id.required' => __('proposals.framework.choose_result'),
            'result_id.exists' => __('proposals.framework.choose_result'),
        ])->validate();

        $assumption = $existing ?? new Assumption(['proposal_id' => $proposal->id]);

        if ($assumption->proposal_id !== $proposal->id) {
            abort(404);
        }

        $assumption->fill([
            'statement' => trim((string) $validated['statement']),
            'status' => $validated['status'],
            'result_id' => (int) $validated['result_id'],
        ])->save();

        return $assumption;
    }

    public function delete(Proposal $proposal, Assumption $assumption): void
    {
        Gate::authorize('results.update');
        $this->guardLock($proposal);

        if ($assumption->proposal_id !== $proposal->id) {
            abort(404);
        }

        $assumption->delete();
    }

    private function guardLock(Proposal $proposal): void
    {
        if (ProposalFramework::isStructureLocked($proposal) && ! Gate::allows('results.update')) {
            abort(403);
        }
    }
}
