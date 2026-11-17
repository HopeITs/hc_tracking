<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Models\ProposalChangeRequest;
use App\Support\ProposalChangeLines;
use App\Support\Slots;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * The change requests waiting on approved proposals (Y-09 slice 2), as two
 * status buttons over the proposals register: "Changes to review" for the
 * Finance and MEAL Managers (requests whose review in a slot they hold is
 * still waiting) and "Changes to approve" for holders of proposals.approve
 * (every pending request). Pressing a button replaces the register's body
 * with that list; pressing it again shows the register again.
 *
 * Each list is read only for someone who may see it: a viewer with no slot,
 * or without proposals.approve, costs no query. Used by proposals/index only.
 */
trait ReadsProposalChangeQueue
{
    /** Which change list replaces the register: '', `review` or `approve`. */
    #[Url(except: '')]
    public string $focus = '';

    /**
     * A focus typed into the address bar that the viewer may not see falls
     * back to the register.
     */
    public function mountReadsProposalChangeQueue(): void
    {
        if (! $this->mayFocusOn($this->focus)) {
            $this->focus = '';
        }
    }

    /**
     * Toggle one change list; an unknown or forbidden list clears it.
     */
    public function setFocus(string $focus): void
    {
        $this->focus = $this->focus === $focus || ! $this->mayFocusOn($focus) ? '' : $focus;
    }

    protected function mayFocusOn(string $focus): bool
    {
        return match ($focus) {
            'review' => $this->heldChangeSlots !== [],
            'approve' => $this->mayApproveChanges(),
            default => false,
        };
    }

    protected function mayApproveChanges(): bool
    {
        return (bool) auth()->user()?->can('proposals.approve');
    }

    /**
     * The review slots the viewer holds right now.
     *
     * @return list<string>
     */
    #[Computed]
    public function heldChangeSlots(): array
    {
        $employeeId = (int) auth()->user()?->employee_id;

        if ($employeeId === 0) {
            return [];
        }

        return array_values(array_filter(
            ProposalChangeRequest::ReviewSlots,
            fn (string $slot): bool => Slots::holderOf($slot) === $employeeId,
        ));
    }

    /**
     * Pending requests whose review in a slot the viewer holds is waiting.
     *
     * @return Collection<int, ProposalChangeRequest>
     */
    #[Computed]
    public function changesToReview(): Collection
    {
        $slots = $this->heldChangeSlots;

        if ($slots === []) {
            return new Collection;
        }

        return $this->changeQueueQuery()
            ->whereHas('reviews', fn (Builder $query) => $query->whereIn('slot', $slots)->whereNull('verdict'))
            ->get();
    }

    /**
     * Every pending request, for someone who decides them.
     *
     * @return Collection<int, ProposalChangeRequest>
     */
    #[Computed]
    public function changesToApprove(): Collection
    {
        return $this->mayApproveChanges() ? $this->changeQueueQuery()->get() : new Collection;
    }

    /**
     * The list the pressed button shows.
     *
     * @return Collection<int, ProposalChangeRequest>
     */
    #[Computed]
    public function changeQueue(): Collection
    {
        return match ($this->focus) {
            'review' => $this->changesToReview,
            'approve' => $this->changesToApprove,
            default => new Collection,
        };
    }

    /**
     * The changed fields' labels of each listed request, keyed by its id:
     * one ProposalChangeLines call for the whole list.
     *
     * @return array<int, list<string>>
     */
    #[Computed]
    public function changeQueueFields(): array
    {
        $queue = $this->changeQueue;

        if ($queue->isEmpty()) {
            return [];
        }

        $lines = ProposalChangeLines::forMany(
            $queue->mapWithKeys(fn (ProposalChangeRequest $request): array => [$request->id => $request->getAttribute('changes') ?? []])->all(),
            auth()->user(),
        );

        return array_map(fn (array $list): array => array_values(array_unique(array_column($list, 'label'))), $lines);
    }

    /**
     * @return Builder<ProposalChangeRequest>
     */
    protected function changeQueueQuery(): Builder
    {
        return ProposalChangeRequest::query()
            ->pending()
            ->with(['proposal:id,slug,name,internal_project_number', 'reviews', 'requester:id,hr_user_id'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
