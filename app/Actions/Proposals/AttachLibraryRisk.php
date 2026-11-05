<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ResultType;
use App\Models\Proposal;
use App\Models\Risk;
use App\Models\RiskLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Pick a risk from the MEAL library onto an outcome or output of a
 * proposal's logframe (contracts.md §6/§7, W-09, D-61). The only writer of
 * a new risk: there is no free-text path.
 *
 * The result is loaded through the proposal (BOLA), the library entry must be
 * active, and a library risk sits once on a result; all three are checked
 * under the proposal lock. `name` is copied from the library at pick time
 * (`name` = the Arabic title, `name_en` = the English one) so the proposal
 * keeps its wording if the library changes; likelihood and severity default
 * from the library and the caller may override them.
 */
final class AttachLibraryRisk
{
    /**
     * @param  array{likelihood?: int|string|null, severity?: int|string|null, solution?: ?string}  $overrides
     *
     * @throws ValidationException
     */
    public function handle(Proposal $proposal, int $resultId, int $libraryRiskId, array $overrides): Risk
    {
        Gate::authorize('attach', [Risk::class, $proposal]);

        $validated = $this->validate($overrides);

        return DB::transaction(function () use ($proposal, $resultId, $libraryRiskId, $validated): Risk {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('attach', [Risk::class, $locked]);

            $result = $locked->results()->whereKey($resultId)->firstOrFail();

            if (! in_array($result->type, [ResultType::Outcome, ResultType::Output], true)) {
                throw ValidationException::withMessages(['resultId' => __('risk_library.result_type_unsupported')]);
            }

            $entry = RiskLibrary::query()->active()->whereKey($libraryRiskId)->lockForUpdate()->first();

            if ($entry === null) {
                throw ValidationException::withMessages(['libraryRiskId' => __('risk_library.unavailable')]);
            }

            if ($locked->risks()->where('result_id', $result->id)->where('library_risk_id', $entry->id)->exists()) {
                throw ValidationException::withMessages(['libraryRiskId' => __('risk_library.already_on_result')]);
            }

            $risk = new Risk;
            $risk->fill([
                'library_risk_id' => $entry->id,
                'proposal_id' => $locked->id,
                'result_id' => $result->id,
                'name' => $entry->title_ar,
                'name_en' => $entry->title_en,
                'likelihood' => array_key_exists('likelihood', $validated) ? $validated['likelihood'] : $entry->default_likelihood,
                'severity' => array_key_exists('severity', $validated) ? $validated['severity'] : $entry->default_severity,
                'solution' => $validated['solution'] ?? null,
            ]);
            $risk->save();

            $locked->recordActivity('proposal.risk_attached', properties: [
                'value' => $risk->name,
                'result' => $result->statement,
            ]);

            return $risk;
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{likelihood?: ?int, severity?: ?int, solution?: ?string}
     */
    private function validate(array $overrides): array
    {
        $input = array_intersect_key($overrides, array_flip(['likelihood', 'severity', 'solution']));

        /** @var array{likelihood?: ?int, severity?: ?int, solution?: ?string} */
        return Validator::make(
            $input,
            [
                'likelihood' => ['sometimes', 'nullable', 'integer', 'between:1,3'],
                'severity' => ['sometimes', 'nullable', 'integer', 'between:1,3'],
                'solution' => ['sometimes', 'nullable', 'string', 'max:5000'],
            ],
            [
                'likelihood.*' => __('risk_library.invalid_rating'),
                'severity.*' => __('risk_library.invalid_rating'),
                'solution.max' => __('risk_library.solution_too_long'),
            ],
        )->validate();
    }
}
