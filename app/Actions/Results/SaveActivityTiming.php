<?php

declare(strict_types=1);

namespace App\Actions\Results;

use App\Enums\ActivityKind;
use App\Enums\ResultType;
use App\Models\Proposal;
use App\Models\Result;
use App\Support\ProposalFramework;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * When an activity of a proposal framework runs: Continuous, Event, or on
 * set Dates. "Dates" is a continuous activity with its own start and end
 * (results.starts_on / ends_on); choosing Continuous or Event clears them.
 */
final class SaveActivityTiming
{
    /** The three choices the activity editor offers. */
    public const MODES = ['continuous', 'event', 'dates'];

    /**
     * @throws ValidationException
     */
    public function handle(Proposal $proposal, Result $activity, string $mode, ?string $startsOn, ?string $endsOn): Result
    {
        Gate::authorize('results.update');

        if ($activity->proposal_id !== $proposal->id || $activity->type !== ResultType::Activity) {
            abort(404);
        }

        if (ProposalFramework::isStructureLocked($proposal) && ! Gate::allows('results.update')) {
            abort(403);
        }

        $validated = Validator::make(['mode' => $mode, 'starts_on' => $startsOn, 'ends_on' => $endsOn], [
            'mode' => ['required', Rule::in(self::MODES)],
            'starts_on' => ['nullable', 'required_if:mode,dates', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'required_if:mode,dates', 'date_format:Y-m-d', 'after:starts_on'],
        ], [
            'starts_on.required_if' => __('proposals.framework.dates_required'),
            'ends_on.required_if' => __('proposals.framework.dates_required'),
            'ends_on.after' => __('proposals.page.end_before_start'),
        ])->validate();

        $isDates = $validated['mode'] === 'dates';

        $activity->activity_kind = $validated['mode'] === 'event' ? ActivityKind::Event : ActivityKind::Continuous;
        $activity->starts_on = $isDates ? $validated['starts_on'] : null;
        $activity->ends_on = $isDates ? $validated['ends_on'] : null;
        $activity->save();

        return $activity;
    }
}
