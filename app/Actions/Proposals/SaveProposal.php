<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Create or update a proposal from the proposal form (contracts.md §6, T06).
 *
 * A new proposal always starts at `concept note` (the column default; this
 * action never writes the status) with its first audit note, is linked to
 * its projects through LinkProposalProject and, for a skipped concept note,
 * moved to `proposal draft` through TransitionProposal, all in one
 * transaction. An edit never touches links or status: it changes the details,
 * the donor while the concept note is still in the house, the budget (money
 * holders only) and the beneficiaries of the projects already linked.
 *
 * The internal number's unique race is left to the caller: the page catches
 * the narrowed UniqueConstraintViolationException around this action.
 */
final class SaveProposal
{
    /**
     * `projectIds`, `startingStage` and `skipReason` are read on create only.
     * `beneficiaries` is keyed by project id. The budget fields are ignored
     * entirely for a user who may not see money.
     *
     * @param  array{name: string, internalProjectNumber: string, donorId: int|string|null, projectIds?: list<int|string>, beneficiaries?: array<int|string, int|string|null>, beneficiariesReason?: ?string, requestedBudget?: string|int|float|null, requestedCurrency?: ?string, plannedDateBegin: ?string, plannedDateEnd: ?string, notes?: ?string, startingStage?: 'concept note'|'proposal draft', skipReason?: ?string}  $data
     */
    public function handle(?Proposal $existing, array $data, User $actor): Proposal
    {
        return $existing === null ? $this->create($data, $actor) : $this->update($existing, $data, $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function create(array $data, User $actor): Proposal
    {
        Gate::forUser($actor)->authorize('create', Proposal::class);

        $input = $this->normalize($data);
        $this->validate($input, $actor, null, false);

        $projectIds = $this->projectIds($input);
        sort($projectIds);
        $beneficiaries = $this->beneficiaries($input);

        $proposal = DB::transaction(function () use ($input, $actor, $projectIds, $beneficiaries): Proposal {
            $proposal = new Proposal;
            $this->fillDetails($proposal, $input, $actor);
            $proposal->save();

            ProposalStatusNote::query()->create([
                'proposal_id' => $proposal->id,
                'status' => ProposalStatus::ConceptNote,
                'context' => __('proposals.proposal_recorded_note'),
                'date' => today()->toDateString(),
                'changed_by' => $actor->id,
                'is_correction' => false,
            ]);

            foreach ($projectIds as $projectId) {
                app(LinkProposalProject::class)->handle($proposal, $projectId, $beneficiaries[$projectId] ?? null);
            }

            if ($input['startingStage'] === ProposalStatus::ProposalDraft->value) {
                app(TransitionProposal::class)->handle($proposal, ProposalStatus::ProposalDraft, ProposalStatus::ConceptNote, [
                    'reason' => (string) $input['skipReason'],
                ]);
            }

            return $proposal;
        });

        return $proposal->fresh() ?? $proposal;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function update(Proposal $existing, array $data, User $actor): Proposal
    {
        $input = $this->normalize($data);

        $proposal = DB::transaction(function () use ($existing, $input, $actor): Proposal {
            $locked = Proposal::query()->whereKey($existing->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('update', $locked);

            /** @var Collection<int, Project> $linked */
            $linked = $locked->projects()->select(['projects.id', 'projects.name'])->orderBy('projects.id')->get();
            $isApproved = $locked->status->isApprovedByDonor();

            $this->validate($input, $actor, $locked, $isApproved && $this->beneficiaryChanges($linked, $input) !== [], $linked);

            $changes = $this->beneficiaryChanges($linked, $input);

            $this->fillDetails($locked, $input, $actor);
            $locked->save();

            foreach ($changes as $change) {
                $locked->projects()->updateExistingPivot($change['project_id'], ['target_beneficiaries' => $change['to']]);
            }

            if ($isApproved && $changes !== []) {
                $locked->recordActivity('proposal.beneficiaries_changed', [], [
                    'reason' => (string) $input['beneficiariesReason'],
                    'projects' => $changes,
                ]);
            }

            return $locked;
        });

        $existing->refresh();

        return $proposal->fresh() ?? $proposal;
    }

    /**
     * Trim the free-text fields and default the starting stage, so the rules
     * and the writes read the same values.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        foreach (['name', 'internalProjectNumber', 'notes', 'skipReason', 'beneficiariesReason'] as $field) {
            if (is_string($data[$field] ?? null)) {
                $data[$field] = trim($data[$field]);
            }
        }

        $data['startingStage'] ??= ProposalStatus::ConceptNote->value;
        $data['beneficiaries'] ??= [];

        return $data;
    }

    /**
     * Check the form before anything is written. On edit the proposal is the
     * locked row and $linked its current projects.
     *
     * @param  array<string, mixed>  $input
     * @param  Collection<int, Project>|null  $linked
     */
    private function validate(array $input, User $actor, ?Proposal $proposal, bool $requiresBeneficiariesReason, ?Collection $linked = null): void
    {
        $storedNumber = $proposal?->internal_project_number;

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'internalProjectNumber' => [
                'required',
                'string',
                'max:50',
                Rule::unique('proposals', 'internal_project_number')->ignore($proposal?->id),
                // TEMP- marks the placeholders the migration wrote. A proposal
                // still holding one saves unchanged, but nobody types a new one.
                function (string $attribute, mixed $value, Closure $fail) use ($storedNumber): void {
                    if (Proposal::isPlaceholderNumber((string) $value) && $value !== $storedNumber) {
                        $fail(__('proposals.internal_project_number_prefix_reserved'));
                    }
                },
            ],
            'donorId' => ['required', 'integer', Rule::exists(Donor::class, 'id')],
            'beneficiaries' => ['array'],
            'beneficiaries.*' => ['nullable', 'integer', 'min:1'],
            'beneficiariesReason' => [$requiresBeneficiariesReason ? 'required' : 'nullable', 'string', 'max:5000'],
            'plannedDateBegin' => ['required', 'date'],
            'plannedDateEnd' => ['required', 'date', 'after_or_equal:plannedDateBegin'],
            'notes' => ['nullable', 'string'],
        ];

        $messages = [
            'name.required' => __('proposals.enter_a_proposal_name'),
            'donorId.required' => __('proposals.choose_the_donor'),
            'donorId.integer' => __('proposals.choose_the_donor'),
            'donorId.exists' => __('proposals.choose_the_donor'),
            'beneficiaries.*.integer' => __('proposals.enter_at_least_one_beneficiary'),
            'beneficiaries.*.min' => __('proposals.enter_at_least_one_beneficiary'),
            'beneficiariesReason.required' => __('proposals.explain_why_the_beneficiaries_changed'),
            'plannedDateBegin.required' => __('proposals.set_the_planned_start_date'),
            'plannedDateEnd.required' => __('proposals.set_the_planned_end_date'),
            'plannedDateEnd.after_or_equal' => __('proposals.the_planned_end_cannot_fall_before_the_planned_start'),
        ];

        if ($proposal === null) {
            $rules['projectIds'] = ['required', 'array', 'min:1'];
            $rules['startingStage'] = ['required', Rule::in([ProposalStatus::ConceptNote->value, ProposalStatus::ProposalDraft->value])];
            $rules['skipReason'] = ['nullable', 'required_if:startingStage,'.ProposalStatus::ProposalDraft->value, 'string', 'min:10', 'max:5000'];
            $messages['projectIds.required'] = __('proposals.link_at_least_one_project');
            $messages['projectIds.min'] = __('proposals.link_at_least_one_project');
            $messages['skipReason.required_if'] = __('proposals.explain_why_the_concept_note_is_skipped');
            $messages['skipReason.min'] = __('proposals.explain_why_the_concept_note_is_skipped');
        }

        if (self::canSeeMoney($actor)) {
            $rules['requestedBudget'] = ['nullable', 'numeric', 'min:0', 'max:999999999999.99'];
            $rules['requestedCurrency'] = ['nullable', 'required_with:requestedBudget', Rule::enum(Currency::class)];
            $messages['requestedBudget.min'] = __('proposals.the_budget_cannot_be_negative');
        }

        $validator = Validator::make($input, $rules, $messages);

        $validator->after(function (ValidatorInstance $validator) use ($input, $actor, $proposal, $linked): void {
            $allowedKeys = $proposal === null
                ? $this->checkProjects($validator, $input, $actor)
                : array_values(array_map(fn (Project $project): int => $project->id, $linked?->all() ?? []));

            $this->checkBeneficiaryKeys($validator, $input, $allowedKeys);

            if ($proposal !== null) {
                $this->checkDonorChange($validator, $input, $proposal);
            }
        });

        $validator->validate();
    }

    /**
     * Refuse any chosen project that is gone, tampered with or not linkable
     * (contracts.md §3), using the same query that builds the picker. Returns
     * the chosen ids.
     *
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    private function checkProjects(ValidatorInstance $validator, array $input, User $actor): array
    {
        if (! is_array($input['projectIds'] ?? null) || $input['projectIds'] === []) {
            return [];
        }

        $ids = $this->projectIds($input);

        $linkableCount = $actor->can('projects.view')
            ? Project::query()->linkableToProposals()->whereKey($ids)->count()
            : 0;

        if ($linkableCount !== count($ids)) {
            $validator->errors()->add('projectIds', __('proposals.that_project_is_no_longer_available'));
        }

        return $ids;
    }

    /**
     * Beneficiaries may only be given for the chosen (create) or linked
     * (edit) projects.
     *
     * @param  array<string, mixed>  $input
     * @param  list<int>  $allowedKeys
     */
    private function checkBeneficiaryKeys(ValidatorInstance $validator, array $input, array $allowedKeys): void
    {
        if (! is_array($input['beneficiaries'])) {
            return;
        }

        foreach (array_keys($input['beneficiaries']) as $key) {
            if (! in_array((int) $key, $allowedKeys, true) || (string) (int) $key !== (string) $key) {
                $validator->errors()->add('beneficiaries', __('proposals.that_project_is_no_longer_available'));

                return;
            }
        }
    }

    /**
     * The donor may change only while the concept note has not left the
     * house and no agreement names it. The composite foreign key is the
     * backstop.
     *
     * @param  array<string, mixed>  $input
     */
    private function checkDonorChange(ValidatorInstance $validator, array $input, Proposal $proposal): void
    {
        if (! is_numeric($input['donorId'] ?? null) || (int) $input['donorId'] === $proposal->donor_id) {
            return;
        }

        $isOpen = in_array($proposal->status, [ProposalStatus::ConceptNote, ProposalStatus::ConceptNoteReview], true);

        if (! $isOpen || $proposal->donorProposals()->exists()) {
            $validator->errors()->add('donorId', __('proposals.the_donor_cannot_change_after_the_concept_note'));
        }
    }

    /**
     * Write the form's own columns. The status is never written here, the
     * donor only explicitly, and the budget only for money holders who sent
     * it.
     *
     * @param  array<string, mixed>  $input
     */
    private function fillDetails(Proposal $proposal, array $input, User $actor): void
    {
        $notes = (string) ($input['notes'] ?? '');

        $proposal->fill([
            'name' => (string) $input['name'],
            'internal_project_number' => (string) $input['internalProjectNumber'],
            'planned_date_begin' => (string) $input['plannedDateBegin'],
            'planned_date_end' => (string) $input['plannedDateEnd'],
            'notes' => $notes === '' ? null : $notes,
        ]);

        $proposal->donor_id = (int) $input['donorId'];

        $sentMoney = array_key_exists('requestedBudget', $input) || array_key_exists('requestedCurrency', $input);

        if (self::canSeeMoney($actor) && ($sentMoney || ! $proposal->exists)) {
            $budget = $input['requestedBudget'] ?? null;
            $currency = $input['requestedCurrency'] ?? null;

            $proposal->requested_budget = $budget === null || $budget === '' ? null : (string) $budget;
            $proposal->requested_currency = $currency === null || $currency === '' ? null : Currency::from((string) $currency);
        }
    }

    /**
     * Each linked project whose beneficiaries the form changes, with its name
     * (a people count, never money).
     *
     * @param  Collection<int, Project>  $linked
     * @param  array<string, mixed>  $input
     * @return list<array{project_id: int, name: string, from: int|null, to: int|null}>
     */
    private function beneficiaryChanges(Collection $linked, array $input): array
    {
        $submitted = is_array($input['beneficiaries']) ? $input['beneficiaries'] : [];
        $changes = [];

        foreach ($linked as $project) {
            if (! array_key_exists($project->id, $submitted)) {
                continue;
            }

            /** @var int|string|null $stored */
            $stored = $project->getRelation('pivot')->getAttribute('target_beneficiaries');
            $from = $stored === null ? null : (int) $stored;
            $to = $this->beneficiaryCount($submitted[$project->id]);

            if ($from !== $to) {
                $changes[] = ['project_id' => $project->id, 'name' => $project->name, 'from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /**
     * The chosen project ids as distinct integers (create only).
     *
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    private function projectIds(array $input): array
    {
        $submitted = is_array($input['projectIds'] ?? null) ? $input['projectIds'] : [];

        return array_values(array_unique(array_map(
            fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            array_values($submitted),
        )));
    }

    /**
     * The submitted beneficiaries as project id => count (null when empty).
     *
     * @param  array<string, mixed>  $input
     * @return array<int, int|null>
     */
    private function beneficiaries(array $input): array
    {
        $result = [];

        foreach (is_array($input['beneficiaries']) ? $input['beneficiaries'] : [] as $projectId => $count) {
            $result[(int) $projectId] = $this->beneficiaryCount($count);
        }

        return $result;
    }

    private function beneficiaryCount(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * Money visibility (contracts.md §8), decided from the acting user. The
     * proposal form reads the same check to decide whether it renders and
     * loads the budget.
     */
    public static function canSeeMoney(User $actor): bool
    {
        return $actor->can('funding-agreements.view') || $actor->can('transfers.view') || $actor->can('accounts.view');
    }
}
