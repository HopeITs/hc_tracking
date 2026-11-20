<?php

declare(strict_types=1);

namespace App\Actions\Results;

use App\Enums\ResultType;
use App\Models\Indicator;
use App\Models\Proposal;
use App\Models\Result;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The proposal page's activity board writes: link an activity to the output
 * it delivers (or unlink it), rename it, and keep what it counts (its own
 * indicators, each with an optional target).
 *
 * Linking and renaming go through SaveResult, so the results-chain rules and
 * the structural lock stay in one place. What an activity counts is a plain
 * numeric indicator on the activity.
 */
final class SaveActivity
{
    /**
     * @throws ValidationException
     */
    public function link(Proposal $proposal, Result $activity, ?int $outputId): Result
    {
        $this->guard($proposal, $activity);

        if ($outputId !== null && ! Result::query()->whereKey($outputId)->where('proposal_id', $proposal->id)->where('type', ResultType::Output->value)->exists()) {
            throw ValidationException::withMessages(['output' => __('proposals.board.choose_output')]);
        }

        return $this->save($proposal, $activity, ['parent_id' => $outputId === null ? '' : (string) $outputId]);
    }

    /**
     * @throws ValidationException
     */
    public function rename(Proposal $proposal, Result $activity, string $name): Result
    {
        $this->guard($proposal, $activity);

        if (trim($name) === '') {
            throw ValidationException::withMessages(['name' => __('proposals.board.name_required')]);
        }

        return $this->save($proposal, $activity, ['statement' => trim($name)]);
    }

    /**
     * Add (no `$indicator`) or change one thing the activity counts.
     *
     * @throws ValidationException
     */
    public function saveCount(Proposal $proposal, Result $activity, ?Indicator $indicator, string $name, ?string $target): Indicator
    {
        $this->guard($proposal, $activity);
        Gate::authorize($indicator === null ? 'indicators.create' : 'indicators.update');

        if ($indicator !== null && $indicator->result_id !== $activity->id) {
            abort(404);
        }

        $validated = Validator::make(['name' => trim($name), 'target' => $target === '' ? null : $target], [
            'name' => ['required', 'string', 'max:255'],
            'target' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ], [
            'name.required' => __('proposals.board.count_required'),
            'target.numeric' => __('proposals.board.target_number'),
            'target.min' => __('proposals.board.target_number'),
        ])->validate();

        $indicator ??= new Indicator(['result_id' => $activity->id]);
        $indicator->fill(['name' => $validated['name'], 'target' => $validated['target']])->save();

        return $indicator;
    }

    public function deleteCount(Proposal $proposal, Result $activity, Indicator $indicator): void
    {
        $this->guard($proposal, $activity);
        Gate::authorize('indicators.delete');

        if ($indicator->result_id !== $activity->id) {
            abort(404);
        }

        DB::transaction(fn () => $indicator->delete());
    }

    private function guard(Proposal $proposal, Result $activity): void
    {
        if ($activity->proposal_id !== $proposal->id || $activity->type !== ResultType::Activity) {
            abort(404);
        }
    }

    /**
     * @param  array<string, string>  $changes
     */
    private function save(Proposal $proposal, Result $activity, array $changes): Result
    {
        return app(SaveResult::class)->handle($activity, [
            'statement' => $activity->statement,
            'type' => $activity->type->value,
            'activity_kind' => $activity->activity_kind?->value ?? 'continuous',
            'checkout_period' => $activity->checkout_period->value,
            'program_id' => (string) ($activity->program_id ?? ''),
            'parent_id' => (string) ($activity->parent_id ?? ''),
            'contributes_to_result_id' => '',
            ...$changes,
        ], $proposal);
    }
}
