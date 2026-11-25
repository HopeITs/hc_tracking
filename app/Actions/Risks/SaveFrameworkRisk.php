<?php

declare(strict_types=1);

namespace App\Actions\Risks;

use App\Models\Program;
use App\Models\Proposal;
use App\Models\Risk;
use App\Models\RiskLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Type or edit a risk of a program's results framework or of a proposal's
 * logframe. MEAL types the Arabic and English titles, the ratings and the
 * mitigation; there is no library picker. The risk is loaded through its
 * owner (BOLA), so another program's or proposal's risk is never reachable.
 * A risk copied from a program is remove-only. The titles are remembered in
 * the risk library, which only suggests them later.
 */
final class SaveFrameworkRisk
{
    /**
     * @param  array{name?: mixed, name_en?: mixed, likelihood?: mixed, severity?: mixed, solution?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function handle(Program|Proposal $owner, ?int $riskId, array $input): Risk
    {
        Gate::authorize('manageFramework', [Risk::class, $owner]);

        $validated = $this->validate($input);

        return DB::transaction(function () use ($owner, $riskId, $validated): Risk {
            $locked = $owner::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('manageFramework', [Risk::class, $locked]);

            if ($riskId === null) {
                $risk = new Risk;
                $risk->fill($locked instanceof Program
                    ? ['program_id' => $locked->id]
                    : ['proposal_id' => $locked->id, 'proposal_location_id' => null]);
            } else {
                $risk = ($locked instanceof Program
                    ? Risk::query()->onProgram($locked->id)
                    : Risk::query()->onLogframeOf($locked->id))
                    ->whereKey($riskId)->lockForUpdate()->firstOrFail();

                if ($risk->isCopied()) {
                    throw ValidationException::withMessages(['risk' => __('risks.copied_is_remove_only')]);
                }
            }

            $entry = RiskLibrary::rememberTitles($validated['name'], $validated['name_en']);

            $risk->fill([...$validated, 'library_risk_id' => $entry->id]);
            $risk->save();

            if ($riskId === null && $locked instanceof Proposal) {
                $locked->recordActivity('proposal.risk_attached', properties: ['value' => $risk->name]);
            }

            return $risk;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, name_en: string, likelihood: int, severity: int, solution: ?string}
     */
    private function validate(array $input): array
    {
        /** @var array{name: string, name_en: string, likelihood: int, severity: int, solution: ?string} */
        return Validator::make(
            array_intersect_key($input, array_flip(['name', 'name_en', 'likelihood', 'severity', 'solution'])),
            [
                'name' => ['required', 'string', 'max:255'],
                'name_en' => ['required', 'string', 'max:255'],
                'likelihood' => ['required', 'integer', 'between:1,3'],
                'severity' => ['required', 'integer', 'between:1,3'],
                'solution' => ['nullable', 'string', 'max:5000'],
            ],
            [
                'name.required' => __('risks.title_ar_required'),
                'name.max' => __('risks.title_too_long'),
                'name_en.required' => __('risks.title_en_required'),
                'name_en.max' => __('risks.title_too_long'),
                'likelihood.*' => __('risks.invalid_rating'),
                'severity.*' => __('risks.invalid_rating'),
                'solution.max' => __('risks.solution_too_long'),
            ],
        )->validate();
    }
}
