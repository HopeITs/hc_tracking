<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\Currency;
use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Events\ProposalLocationsChanged;
use App\Models\Donor;
use App\Models\HrCenter;
use App\Models\HrCity;
use App\Models\HrEmployee;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\User;
use App\Support\ProposalLocationRows;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
 * Location rows (contracts.md §7, W-34a, D-62) come in `locations`: the set
 * is validated as a whole, then replaced under the proposal lock through
 * ProposalLocationRows, a new row without a program opens its program
 * request, and ProposalLocationsChanged fires after commit with the counts.
 * On create, each `projectIds` entry still becomes a row with `project_id`
 * through LinkProposalProject, so a form without rows keeps working until
 * the picker goes (W-34a slice 3).
 * A payroll proposal (D-55b, W-32) is chosen on create only and never changes
 * kind. It needs a narrative, links no project and starts in `proposal
 * draft`: created at the concept note like any other, then moved on at once
 * with the standard payroll note, the same hop as the D-03 skip.
 */
final class SaveProposal
{
    /**
     * `kind`, `projectIds`, `startingStage`, `skipReason` and
     * `previousProposalId` (the proposal a renewal renews, set only by
     * CloneProposalForRenewal) are read on create only;
     * `kind` is raw form input, validated as a ProposalKind value (default
     * `project`); `startingStage` is validated as `concept note` or
     * `proposal draft` and ignored for payroll, which always starts in draft.
     * `beneficiaries` is keyed by project id; payroll ignores it and
     * `projectIds`. `narrative` is read for payroll only. The budget fields
     * are ignored entirely for a user who may not see money. `locations`, when
     * sent, replaces the rows; an edit that omits it leaves them alone.
     *
     * @param  array{kind?: ?string, name: string, donorId: int|string|null, narrative?: ?string, projectIds?: list<int|string>, locations?: list<array{city_id: int|string|null, program_id?: int|string|null, responsible_employee_id?: int|string|null, center_ids?: list<int|string>, is_new?: bool|int|string|null, new_program_name_en?: ?string, new_program_name_ar?: ?string}>, beneficiaries?: array<int|string, int|string|null>, beneficiariesReason?: ?string, requestedBudget?: string|int|float|null, requestedCurrency?: ?string, plannedDateBegin: ?string, plannedDateEnd: ?string, notes?: ?string, conceptNote?: ?string, startingStage?: string, skipReason?: ?string, previousProposalId?: int|null}  $data
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

        $kind = ProposalKind::from((string) $input['kind']);
        $projectIds = $this->projectIds($input);
        sort($projectIds);
        $beneficiaries = $this->beneficiaries($input);
        $rows = $this->locationRows($input);

        $proposal = DB::transaction(function () use ($input, $actor, $kind, $projectIds, $beneficiaries, $rows): Proposal {
            $proposal = new Proposal;
            $proposal->kind = $kind;
            $this->fillDetails($proposal, $input, $actor);
            $proposal->internal_project_number = $this->nextNumber(
                (string) $input['plannedDateBegin'],
                shortYear: $kind === ProposalKind::Payroll && isset($input['previousProposalId']),
            );
            $proposal->previous_proposal_id = isset($input['previousProposalId']) ? (int) $input['previousProposalId'] : null;
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

            if ($rows !== null) {
                $this->announceLocations($proposal, app(ProposalLocationRows::class)->replace($proposal, $rows, $actor), $actor);
            }

            if ($kind === ProposalKind::Payroll || $input['startingStage'] === ProposalStatus::ProposalDraft->value) {
                app(TransitionProposal::class)->handle($proposal, ProposalStatus::ProposalDraft, ProposalStatus::ConceptNote, [
                    'reason' => $kind === ProposalKind::Payroll ? __('proposals.payroll_start_note') : (string) $input['skipReason'],
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
        $proposal = DB::transaction(function () use ($existing, $data, $actor): Proposal {
            $locked = Proposal::query()->whereKey($existing->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('update', $locked);

            // The kind never changes: the stored one decides the rules.
            $input = $this->normalize([...$data, 'kind' => $locked->kind->value]);
            $rows = $this->locationRows($input);

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

            if ($rows !== null) {
                $this->announceLocations($locked, app(ProposalLocationRows::class)->replace($locked, $rows, $actor), $actor);
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
        foreach (['name', 'notes', 'conceptNote', 'narrative', 'skipReason', 'beneficiariesReason'] as $field) {
            if (is_string($data[$field] ?? null)) {
                $data[$field] = trim($data[$field]);
            }
        }

        $data['kind'] = blank($data['kind'] ?? null) ? ProposalKind::Project->value : $data['kind'];
        $data['startingStage'] ??= ProposalStatus::ConceptNote->value;
        $data['beneficiaries'] ??= [];

        // A payroll proposal links no project and always starts in draft.
        if ($data['kind'] === ProposalKind::Payroll->value) {
            $data['projectIds'] = [];
            $data['beneficiaries'] = [];
            $data['startingStage'] = ProposalStatus::ProposalDraft->value;
            $data['skipReason'] = null;
        }

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
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'donorId' => ['required', 'integer', Rule::exists(Donor::class, 'id')],
            'beneficiaries' => ['array'],
            'beneficiaries.*' => ['nullable', 'integer', 'min:1'],
            'beneficiariesReason' => [$requiresBeneficiariesReason ? 'required' : 'nullable', 'string', 'max:5000'],
            'plannedDateBegin' => ['required', 'date'],
            'plannedDateEnd' => ['required', 'date', 'after_or_equal:plannedDateBegin'],
            'notes' => ['nullable', 'string'],
            // The concept note is never required: no transition asks for it (contracts §2).
            'conceptNote' => ['nullable', 'string', 'max:20000'],
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
            'conceptNote.max' => __('proposals.concept_note.too_long'),
        ];

        $isPayroll = $input['kind'] === ProposalKind::Payroll->value;

        if ($proposal === null) {
            $rules['kind'] = ['required', Rule::enum(ProposalKind::class)];
            $messages['kind.required'] = __('proposals.choose_the_proposal_kind');
            $messages['kind.enum'] = __('proposals.choose_the_proposal_kind');
        }

        if ($proposal === null && ! $isPayroll) {
            $rules['projectIds'] = ['bail', 'required_without:locations', 'array'];
            $rules['locations'] = ['bail', 'required_without:projectIds', 'array'];
            $rules['startingStage'] = ['required', Rule::in([ProposalStatus::ConceptNote->value, ProposalStatus::ProposalDraft->value])];
            $rules['skipReason'] = ['nullable', 'required_if:startingStage,'.ProposalStatus::ProposalDraft->value, 'string', 'min:10', 'max:5000'];
            $messages['projectIds.required_without'] = __('proposals.link_at_least_one_project');
            $messages['skipReason.required_if'] = __('proposals.explain_why_the_concept_note_is_skipped');
            $messages['skipReason.min'] = __('proposals.explain_why_the_concept_note_is_skipped');
        } else {
            $rules['locations'] = ['bail', 'sometimes', 'required', 'array'];
        }

        $messages['locations.required_without'] = __('proposals.locations_required');
        $messages['locations.required'] = __('proposals.locations_required');
        $messages['locations.array'] = __('proposals.locations_required');

        if ($isPayroll) {
            $rules['narrative'] = ['nullable', 'string', 'max:10000'];
        }

        if (self::canSeeMoney($actor)) {
            $rules['requestedBudget'] = ['nullable', 'numeric', 'min:0', 'max:999999999999.99'];
            $rules['requestedCurrency'] = ['nullable', 'required_with:requestedBudget', Rule::enum(Currency::class)];
            $messages['requestedBudget.min'] = __('proposals.the_budget_cannot_be_negative');
        }

        $validator = Validator::make($input, $rules, $messages);

        $validator->after(function (ValidatorInstance $validator) use ($input, $actor, $proposal, $linked, $isPayroll): void {
            if ($isPayroll && ! $validator->errors()->has('narrative') && ! Proposal::isNarrativeComplete(is_string($input['narrative'] ?? null) ? $input['narrative'] : null)) {
                $validator->errors()->add('narrative', __('proposals.narrative_required'));
            }

            $allowedKeys = $proposal === null
                ? $this->checkProjects($validator, $input, $actor)
                : array_values(array_map(fn (Project $project): int => $project->id, $linked?->all() ?? []));

            $this->checkBeneficiaryKeys($validator, $input, $allowedKeys);

            if ($proposal !== null) {
                $this->checkDonorChange($validator, $input, $proposal);
            }

            $this->checkDonorApproved($validator, $input, $proposal);
            $this->checkLocations($validator, $input);
        });

        $validator->validate();
    }

    /**
     * The location rows as a whole (contracts.md §6, W-34a): every row names
     * an active HR city; cities are distinct per program, with at most one
     * row per city still waiting for a program; a program exists in
     * tracking; every center stands in the row's city; a responsible is an
     * active HR employee; a new row without a program names it in both
     * languages. HR is read once per rule, never per row; the centers come
     * from the in-memory lookup.
     *
     * @param  array<string, mixed>  $input
     */
    private function checkLocations(ValidatorInstance $validator, array $input): void
    {
        $rows = $this->locationRows($input);

        if ($rows === null || $rows === [] || $validator->errors()->has('locations')) {
            return;
        }

        $cityIds = array_values(array_unique(array_filter(array_column($rows, 'city_id'))));
        $programIds = array_values(array_unique(array_filter(array_column($rows, 'program_id'))));
        $employeeIds = array_values(array_unique(array_filter(array_column($rows, 'responsible_employee_id'))));

        $activeCities = $cityIds === [] ? [] : HrCity::query()->whereKey($cityIds)->where('is_active', true)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $programs = $programIds === [] ? [] : Program::query()->whereKey($programIds)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $activeEmployees = $employeeIds === [] ? [] : HrEmployee::query()->whereKey($employeeIds)->where('is_active', true)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $seen = [];

        foreach ($rows as $index => $row) {
            $cityOk = $row['city_id'] !== null && in_array($row['city_id'], $activeCities, true);

            if (! $cityOk) {
                $validator->errors()->add("locations.{$index}.city_id", __('proposals.location_choose_the_city'));
            } else {
                $key = $row['city_id'].':'.($row['program_id'] ?? '');

                if (isset($seen[$key])) {
                    $validator->errors()->add("locations.{$index}.city_id", __('proposals.location_city_duplicate'));
                }

                $seen[$key] = true;
            }

            if ($row['program_id'] !== null && ! in_array($row['program_id'], $programs, true)) {
                $validator->errors()->add("locations.{$index}.program_id", __('proposals.location_choose_the_program'));
            }

            if ($cityOk) {
                foreach ($row['center_ids'] as $centerId) {
                    if (HrCenter::remembered($centerId)?->city_id !== $row['city_id']) {
                        $validator->errors()->add("locations.{$index}.center_ids", __('proposals.location_center_not_in_city'));

                        break;
                    }
                }
            }

            if ($row['responsible_employee_id'] !== null && ! in_array($row['responsible_employee_id'], $activeEmployees, true)) {
                $validator->errors()->add("locations.{$index}.responsible_employee_id", __('proposals.location_responsible_inactive'));
            }

            if ($row['is_new'] && $row['program_id'] === null) {
                foreach (['new_program_name_en', 'new_program_name_ar'] as $field) {
                    if ($row[$field] === '') {
                        $validator->errors()->add("locations.{$index}.{$field}", __('proposals.location_program_name_required'));
                    }
                }
            }
        }
    }

    /**
     * The sent location rows in one shape: ids as positive integers or
     * null, centers distinct and ascending, names trimmed. Null when the
     * form sent no `locations` key at all.
     *
     * @param  array<string, mixed>  $input
     * @return list<array{city_id: int|null, program_id: int|null, responsible_employee_id: int|null, center_ids: list<int>, is_new: bool, new_program_name_en: string, new_program_name_ar: string}>|null
     */
    private function locationRows(array $input): ?array
    {
        if (! array_key_exists('locations', $input)) {
            return null;
        }

        $id = fn (mixed $value): ?int => is_numeric($value) && (int) $value > 0 && (string) (int) $value === (string) $value ? (int) $value : null;
        $rows = [];

        foreach (is_array($input['locations']) ? array_values($input['locations']) : [] as $row) {
            $row = is_array($row) ? $row : [];
            $centerIds = array_values(array_unique(array_filter(array_map($id, is_array($row['center_ids'] ?? null) ? array_values($row['center_ids']) : []))));
            sort($centerIds);

            $rows[] = [
                'city_id' => $id($row['city_id'] ?? null),
                'program_id' => $id($row['program_id'] ?? null),
                'responsible_employee_id' => $id($row['responsible_employee_id'] ?? null),
                'center_ids' => $centerIds,
                'is_new' => filter_var($row['is_new'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'new_program_name_en' => is_string($row['new_program_name_en'] ?? null) ? trim($row['new_program_name_en']) : '',
                'new_program_name_ar' => is_string($row['new_program_name_ar'] ?? null) ? trim($row['new_program_name_ar']) : '',
            ];
        }

        return $rows;
    }

    /**
     * Record and announce a change of the rows (counts only, never names or
     * amounts). Nothing changed → nothing recorded, nothing dispatched. The
     * event waits for the commit.
     *
     * @param  array{added: int, removed: int, changed: int}  $counts
     */
    private function announceLocations(Proposal $proposal, array $counts, User $actor): void
    {
        if ($counts['added'] + $counts['removed'] + $counts['changed'] === 0) {
            return;
        }

        $proposal->recordActivity('proposal.locations_changed', [], $counts);
        ProposalLocationsChanged::dispatch($proposal->id, $actor->id, $counts['added'], $counts['removed'], $counts['changed']);
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
     * A donor the General Manager has not approved yet cannot be chosen
     * (D-57). A proposal that already carries one keeps saving its other
     * details; only choosing it is refused.
     *
     * @param  array<string, mixed>  $input
     */
    private function checkDonorApproved(ValidatorInstance $validator, array $input, ?Proposal $proposal): void
    {
        if ($validator->errors()->has('donorId') || ! is_numeric($input['donorId'] ?? null)) {
            return;
        }

        $donorId = (int) $input['donorId'];

        if ($proposal !== null && $donorId === $proposal->donor_id) {
            return;
        }

        if (Donor::query()->pending()->whereKey($donorId)->exists()) {
            $validator->errors()->add('donorId', __('proposals.donor_request_pending'));
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
            'planned_date_begin' => (string) $input['plannedDateBegin'],
            'planned_date_end' => (string) $input['plannedDateEnd'],
            'notes' => $notes === '' ? null : $notes,
        ]);

        $proposal->donor_id = (int) $input['donorId'];

        if (array_key_exists('conceptNote', $input)) {
            $conceptNote = (string) ($input['conceptNote'] ?? '');
            $proposal->concept_note = $conceptNote === '' ? null : $conceptNote;
        }

        if ($input['kind'] === ProposalKind::Payroll->value) {
            $proposal->narrative = (string) $input['narrative'];
        }

        $sentMoney = array_key_exists('requestedBudget', $input) || array_key_exists('requestedCurrency', $input);

        if (self::canSeeMoney($actor) && ($sentMoney || ! $proposal->exists)) {
            $budget = $input['requestedBudget'] ?? null;
            $currency = $input['requestedCurrency'] ?? null;

            $proposal->requested_budget = $budget === null || $budget === '' ? null : (string) $budget;
            $proposal->requested_currency = $currency === null || $currency === '' ? null : Currency::from((string) $currency);
        }
    }

    /**
     * The next internal project number of the planned start's year: HC-YYYY-NNN,
     * or YY-NNN (26-001) for a renewed payroll proposal. The short form counts
     * on its own, from the numbers already written, under the same year lock.
     */
    private function nextNumber(string $plannedStart, bool $shortYear = false): string
    {
        $year = CarbonImmutable::parse($plannedStart)->year;
        $prefix = $shortYear ? sprintf('%02d-', $year % 100) : sprintf('HC-%04d-', $year);

        DB::table('proposal_number_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
        $sequence = DB::table('proposal_number_sequences')->where('year', $year)->lockForUpdate()->first();
        $lastNumber = $shortYear ? 0 : (int) $sequence->last_number;

        foreach (Proposal::query()->where('internal_project_number', 'like', $prefix.'%')->pluck('internal_project_number') as $number) {
            if (preg_match('/^'.preg_quote($prefix, '/').'([0-9]{3})$/', $number, $matches)) {
                $lastNumber = max($lastNumber, (int) $matches[1]);
            }
        }

        if ($lastNumber >= 999) {
            throw ValidationException::withMessages([
                'plannedDateBegin' => __('proposals.internal_number_exhausted', ['year' => $year]),
            ]);
        }

        if (! $shortYear) {
            DB::table('proposal_number_sequences')->where('year', $year)->update(['last_number' => $lastNumber + 1]);
        }

        return $prefix.str_pad((string) ($lastNumber + 1), 3, '0', STR_PAD_LEFT);
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
