<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\MediaCollection;
use App\Enums\ProposalStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Enums\StatusNoteKind;
use App\Models\Media;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\ReportTemplateLine;
use App\Support\DonorReportFiles;
use App\Support\ProjectPeriods;
use App\Support\StoredFiles;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Extend an active proposal to a later end date (W-08, D-60).
 *
 * The original `actual_date_end` stays; `extended_date_end`, the reason and an
 * optional amendment file are recorded beside it with one `extension` status
 * note. Every linked approved or active project that ends earlier widens
 * through ProjectPeriods (an active one raises ProjectPeriodChanged, so the
 * new monthly reports are generated after commit). The proposal's template
 * lines that ran to the old last month run to the new one. Not a status
 * writer: the status stays `active`.
 */
final class ExtendProposal
{
    /**
     * @param  array{new_date_end: string, reason: string, file?: UploadedFile|null}  $input
     *
     * @throws ValidationException stale status, not active, bad date, short reason, bad file
     */
    public function handle(Proposal $proposal, ProposalStatus $expectedStatus, array $input): ProposalStatusNote
    {
        Gate::authorize('update', $proposal);

        $reason = trim($input['reason']);
        $file = $input['file'] ?? null;

        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['extensionReason' => __('proposals.extend_reason_min')]);
        }

        $newEnd = $this->parseDate($input['new_date_end']);

        if ($file !== null) {
            DonorReportFiles::validateUploads([$file], 'extensionFile');
        }

        $stored = null;

        try {
            $note = DB::transaction(function () use ($proposal, $expectedStatus, $reason, $file, $newEnd, &$stored): ProposalStatusNote {
                $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->status !== $expectedStatus) {
                    throw ValidationException::withMessages(['status' => __('proposals.stale_status')]);
                }

                if ($locked->status !== ProposalStatus::Active) {
                    throw ValidationException::withMessages(['status' => __('proposals.extend_only_active')]);
                }

                $currentEnd = $locked->effectiveDateEnd();

                if ($currentEnd !== null && ! $newEnd->gt($currentEnd)) {
                    throw ValidationException::withMessages([
                        'extensionDateEnd' => __('proposals.extend_date_after', ['date' => $currentEnd->translatedFormat(__('formats.date'))]),
                    ]);
                }

                $locked->extended_date_end = $newEnd;
                $locked->extension_reason = $reason;
                $locked->save();

                $note = ProposalStatusNote::query()->create([
                    'proposal_id' => $locked->id,
                    'status' => $locked->status,
                    'context' => $reason,
                    'date' => today()->toDateString(),
                    'changed_by' => Auth::id(),
                    'kind' => StatusNoteKind::Extension,
                ]);

                if ($file !== null) {
                    $stored = new Media(['mediable_type' => $note->getMorphClass(), 'mediable_id' => $note->id, 'disk' => 'local']);

                    StoredFiles::save(
                        $stored,
                        $file,
                        'media/proposals/'.$locked->id,
                        '',
                        ['collection' => MediaCollection::Amendment->value],
                        ['uploaded_at' => now()],
                        'amendment file',
                    );
                }

                $this->widenProjects($locked, $newEnd);
                $this->extendTemplateLines($locked, $currentEnd, $newEnd);

                $locked->recordActivity('proposal.extended', properties: [
                    'old_end' => $currentEnd?->toDateString(),
                    'new_end' => $newEnd->toDateString(),
                    'value' => $newEnd->toDateString(),
                    'file' => $stored?->original_name,
                ]);

                return $note;
            });
        } catch (Throwable $exception) {
            if ($stored?->path !== null) {
                StoredFiles::forget($stored->disk, $stored->path, 'amendment file');
            }

            throw $exception;
        }

        $proposal->refresh();

        return $note;
    }

    /**
     * @throws ValidationException
     */
    private function parseDate(string $value): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === null || $date->toDateString() !== $value) {
            throw ValidationException::withMessages(['extensionDateEnd' => __('proposals.extend_date_required')]);
        }

        return $date;
    }

    /**
     * Widen every linked approved or active project, locked in ascending id
     * order, loaded through the proposal. Dates are never shortened.
     */
    private function widenProjects(Proposal $proposal, CarbonImmutable $newEnd): void
    {
        $ids = $proposal->projects()->orderBy('projects.id')->pluck('projects.id')->all();

        if ($ids === []) {
            return;
        }

        $projects = Project::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

        foreach ($projects as $project) {
            if ($project->start_date !== null) {
                ProjectPeriods::widen($project, $project->start_date, $newEnd);
            }
        }
    }

    /**
     * The proposal's template lines that ran to its old last month now run to
     * the new last month (contracts §12.4); retired lines keep their end.
     */
    private function extendTemplateLines(Proposal $proposal, ?CarbonImmutable $oldEnd, CarbonImmutable $newEnd): void
    {
        if ($oldEnd === null) {
            return;
        }

        $targetIds = PlanIndicator::query()
            ->where('source_type', 'proposal')
            ->where('source_id', $proposal->id)
            ->pluck('id')
            ->all();

        if ($targetIds === []) {
            return;
        }

        ReportTemplateLine::query()
            ->whereIn('plan_indicator_id', $targetIds)
            ->where('status', '!=', ReportTemplateLineStatus::Retired->value)
            ->whereBetween('effective_to', [$oldEnd->startOfMonth()->toDateString(), $oldEnd->endOfMonth()->toDateString()])
            ->update(['effective_to' => $newEnd->startOfMonth()->toDateString()]);
    }
}
