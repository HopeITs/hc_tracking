<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\MediaCollection;
use App\Models\Proposal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Record the donor's acceptance of the final report (D-02c); the acceptor is
 * the signed-in user.
 *
 * The final report file must already be filed. The acceptance email
 * (`final_report_acceptance`) is optional. Saved quietly so the only
 * activity is `proposal.final_report_accepted`, never a generic update.
 */
final class RecordFinalReportAcceptance
{
    public function handle(Proposal $proposal, CarbonImmutable $acceptedOn): Proposal
    {
        Gate::authorize('update', $proposal);

        if ($acceptedOn->startOfDay()->isAfter(today())) {
            throw ValidationException::withMessages(['accepted_on' => __('proposals.date_not_future')]);
        }

        return DB::transaction(function () use ($proposal, $acceptedOn): Proposal {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->media()->where('collection', MediaCollection::FinalReport)->exists()) {
                throw ValidationException::withMessages(['final_report' => __('proposals.final_report_required')]);
            }

            $locked->final_report_accepted_on = $acceptedOn->startOfDay();
            $locked->final_report_accepted_by = Auth::id();
            $locked->saveQuietly();

            $locked->recordActivity('proposal.final_report_accepted');

            return $locked;
        });
    }
}
