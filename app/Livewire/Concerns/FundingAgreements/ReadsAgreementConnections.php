<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\FundingAgreements;

use App\Enums\ArStatus;
use App\Enums\MediaCollection;
use App\Enums\MilestoneStatus;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Media;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * What funding-agreements/show links out to: the projects the grant pays for,
 * related agreements, the accounts the money lands in, the signed paperwork
 * and the reports owed to the donor.
 *
 * Used by funding-agreements/show only. The page provides agreementId and the
 * #[Computed] agreement() and transfers().
 *
 * @property int $agreementId
 * @property-read DonorProposal $agreement  #[Computed] agreement()
 * @property-read Collection<int, TransfersTracking> $transfers  #[Computed] transfers()
 * @property-read Collection<int, Project> $projects  #[Computed] projects()
 */
trait ReadsAgreementConnections
{
    /**
     * The projects the funded proposal pays for, with how far each has got.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return $this->agreement->proposal->projects()
            ->select(['projects.id', 'projects.name', 'projects.city_id', 'projects.program_id', 'projects.status', 'projects.start_date', 'projects.end_date'])
            ->with(['city:id,name_en,name_ar', 'program:id'])
            ->withCount([
                'milestones',
                'milestones as reached_milestones_count' => fn (Builder $query) => $query->where('status', MilestoneStatus::Reached),
            ])
            ->orderBy('projects.name')
            ->get();
    }

    /**
     * Other agreements that share this agreement's donor or its proposal.
     *
     * `landed` sums only net amounts that arrived in each agreement's own
     * currency, the same rule this page counts by.
     *
     * @return Collection<int, DonorProposal>
     */
    #[Computed]
    public function relatedAgreements(): Collection
    {
        return DonorProposal::query()
            ->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'external_project_number'])
            ->whereKeyNot($this->agreementId)
            ->where(fn (Builder $query) => $query
                ->where('donor_id', $this->agreement->donor_id)
                ->orWhere('proposal_id', $this->agreement->proposal_id))
            ->with(['donor:id,name', 'proposal:id,name'])
            ->withSum([
                'transfers as landed' => fn (Builder $query) => $query
                    ->where('ar_status', ArStatus::Received)
                    ->whereColumn('transfers_tracking.amount_transfered_currency', 'donor_proposal.currency')
                    ->whereColumn('transfers_tracking.amount_received_currency', 'donor_proposal.currency'),
            ], 'net_amount_received')
            ->orderByDesc('budget')
            ->get();
    }

    /**
     * The accounts this agreement's money moves through, with what landed in
     * each per currency.
     *
     * @return array<int, array{name: string, active: bool, tranches: int, landed: array<string, float>}>
     */
    #[Computed]
    public function accounts(): array
    {
        return $this->transfers
            ->filter(fn (TransfersTracking $transfer): bool => $transfer->account !== null)
            ->groupBy('account_id')
            ->map(fn (Collection $group): array => [
                'name' => $group->first()->account->account_name,
                'active' => (bool) $group->first()->account->is_active,
                'tranches' => $group->count(),
                'landed' => $group
                    ->where('ar_status', ArStatus::Received)
                    ->groupBy(fn (TransfersTracking $transfer): string => $transfer->amount_received_currency->value)
                    ->map(fn (Collection $byCurrency): float => (float) $byCurrency->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received))
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * The signed paperwork behind this money: agreements and MOUs filed on
     * the proposal or on the projects it funds.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function paperwork(): Collection
    {
        $projects = $this->projects->keyBy('id');
        $proposalId = $this->agreement->proposal_id;

        return Media::query()
            ->select(['id', 'mediable_type', 'mediable_id', 'collection', 'original_name', 'type', 'size', 'uploaded_at'])
            ->whereIn('collection', [MediaCollection::Agreement->value, MediaCollection::Mou->value])
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('mediable_type', 'proposal')->where('mediable_id', $proposalId))
                ->when($projects->isNotEmpty(), fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query
                    ->where('mediable_type', 'project')
                    ->whereIn('mediable_id', $projects->keys()->all()))))
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->get()
            ->each(fn (Media $media) => $media->setRelation('mediable', $media->mediable_type === 'project' ? $projects->get($media->mediable_id) : null));
    }

    /**
     * The reports owed to the donor under this agreement, soonest due first.
     *
     * @return Collection<int, DonorReport>
     */
    #[Computed]
    public function reports(): Collection
    {
        return DonorReport::query()
            ->select(['id', 'donor_proposal_id', 'title', 'type', 'due_on', 'submitted_on'])
            ->where('donor_proposal_id', $this->agreementId)
            ->orderBy('due_on')
            ->orderBy('id')
            ->get();
    }

    protected function paperworkUrl(Media $media): string
    {
        return $media->mediable_type === 'proposal'
            ? route('proposals.files.download', [Proposal::query()->findOrFail($media->mediable_id), $media])
            : route('projects.files.download', [Project::query()->findOrFail($media->mediable_id), $media]);
    }
}
