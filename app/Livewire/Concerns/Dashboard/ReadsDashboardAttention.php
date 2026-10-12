<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Dashboard;

use App\Enums\ArStatus;
use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Risk;
use App\Models\Scopes\TaskVisibilityScope;
use App\Models\Task;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;

/**
 * The dashboard's "Needs attention" queue: every late task, milestone and
 * pending transfer plus every materialised risk, the counts behind its
 * filter, and the row shaping and links the "Coming up" list shares.
 *
 * Used by dashboard/index only. The page provides visible() and canView()
 * (the viewer's module access). transferHref() and taskParentName() are also
 * called by ReadsDashboardWork::upcoming(); attentionCounts() is also read by
 * ChartsDashboard::pulse().
 *
 * @property-read Collection<int, array{key: string, group: string, icon: string, tone: string, title: string, context: string, badge: string, daysLate: int|null, href: string, allRank: int|null, groupRank: int}> $attentionItems  #[Computed] attentionItems()
 */
trait ReadsDashboardAttention
{
    /**
     * The most rows the attention queue shows at once, in any view.
     */
    private const AttentionLimit = 8;

    /**
     * How many rows of the "All" view are held for materialised risks.
     */
    private const RiskSlots = 2;

    /**
     * Everything that is late or has gone wrong, most overdue first.
     *
     * Dated items (tasks, milestones, transfers) sort by days late; materialised
     * risks carry no due date and follow them, newest first. Every row is
     * rendered once and the queue's filter only hides rows in the browser, so
     * `allRank` and `groupRank` say where a row sits in each view. The "All" view
     * keeps up to two rows for risks, so a busy week of late work never pushes
     * a materialised risk off the dashboard.
     *
     * @return Collection<int, array{key: string, group: string, icon: string, tone: string, title: string, context: string, badge: string, daysLate: int|null, href: string, allRank: int|null, groupRank: int}>
     */
    #[Computed]
    public function attentionItems(): Collection
    {
        $today = CarbonImmutable::today();

        $tasks = $this->visible(Task::query(), 'tasks')
            ->select(['id', 'source_type', 'source_id', 'title', 'due_date', 'status'])
            ->with(['source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Milestone::class => ['project:id,name']])])
            ->where('status', '!=', TaskStatus::Done)
            ->whereDate('due_date', '<', $today)
            ->oldest('due_date')
            ->limit(self::AttentionLimit)
            ->get()
            ->map(fn (Task $task): array => $this->attentionEntry(
                key: 'task-'.$task->id,
                group: 'delivery',
                icon: 'clipboard-document-check',
                title: $task->title,
                context: __('dashboard.task_in', ['parent' => $this->taskParentName($task)]),
                daysLate: (int) $task->due_date->diffInDays($today),
                href: route('tasks.show', $task),
            ));

        $milestones = $this->visible(Milestone::query(), 'milestones')
            ->select(['id', 'project_id', 'name', 'planned_end_date', 'status'])
            ->with('project:id,name')
            ->where('status', MilestoneStatus::Planned)
            ->whereDate('planned_end_date', '<', $today)
            ->oldest('planned_end_date')
            ->limit(self::AttentionLimit)
            ->get()
            ->map(fn (Milestone $milestone): array => $this->attentionEntry(
                key: 'milestone-'.$milestone->id,
                group: 'delivery',
                icon: 'flag',
                title: $milestone->name,
                context: __('dashboard.milestone_in', ['project' => $milestone->project?->name ?? '—']),
                daysLate: (int) $milestone->planned_end_date->diffInDays($today),
                href: route('milestones.index', ['project' => $milestone->project_id, 'status' => MilestoneStatus::Planned->value]),
            ));

        $transfers = ! $this->canView('transfers') ? new Collection : TransfersTracking::query()
            ->select(['id', 'donor_proposal_id', 'payment_amount', 'amount_transfered_currency', 'scheduled_date', 'ar_status'])
            ->with(['donorProposal:id,donor_id,proposal_id', 'donorProposal.donor:id,name', 'donorProposal.proposal:id,name,status'])
            ->where('ar_status', ArStatus::Pending)
            ->whereDate('scheduled_date', '<', $today)
            ->oldest('scheduled_date')
            ->limit(self::AttentionLimit)
            ->get()
            ->map(fn (TransfersTracking $transfer): array => $this->attentionEntry(
                key: 'transfer-'.$transfer->id,
                group: 'funding',
                icon: 'banknotes',
                title: __('dashboard.transfer_from', [
                    'amount' => Number::format((float) $transfer->payment_amount, maxPrecision: 0),
                    'currency' => $transfer->amount_transfered_currency,
                    'donor' => $transfer->donorProposal?->donor?->name ?? '—',
                ]),
                context: $transfer->donorProposal?->proposal?->name ?? '—',
                daysLate: (int) $transfer->scheduled_date->diffInDays($today),
                href: $this->transferHref($transfer),
            ));

        $risks = $this->visible(Risk::query(), 'risks')
            ->select(['id', 'project_id', 'name', 'updated_at'])
            ->with('project:id,name')
            ->where('happened', true)
            ->latest('updated_at')
            ->limit(self::AttentionLimit)
            ->get()
            ->map(fn (Risk $risk): array => $this->attentionEntry(
                key: 'risk-'.$risk->id,
                group: 'risks',
                icon: 'exclamation-triangle',
                title: $risk->name,
                context: __('dashboard.risk_in', ['project' => $risk->project?->name ?? '—']),
                daysLate: null,
                href: route('risks.index', ['project' => $risk->project_id, 'status' => 'happened']),
            ));

        $dated = $tasks->concat($milestones)->concat($transfers)->sortByDesc('daysLate')->values();
        $datedShownInAll = min($dated->count(), self::AttentionLimit - min($risks->count(), self::RiskSlots));
        $risksShownInAll = min($risks->count(), self::AttentionLimit - $datedShownInAll);
        $groupRanks = [];

        return $dated
            ->map(fn (array $item, int $index): array => [...$item, 'allRank' => $index < $datedShownInAll ? $index : null])
            ->concat($risks->map(fn (array $item, int $index): array => [...$item, 'allRank' => $index < $risksShownInAll ? $datedShownInAll + $index : null]))
            ->map(function (array $item) use (&$groupRanks): array {
                /** @var array<string, int> $groupRanks */
                $groupRanks[$item['group']] = ($groupRanks[$item['group']] ?? -1) + 1;

                return [...$item, 'groupRank' => $groupRanks[$item['group']]];
            })
            ->values();
    }

    /**
     * How many rows each view of the attention queue actually shows.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function attentionShown(): array
    {
        $items = $this->attentionItems;

        return [
            'all' => $items->whereNotNull('allRank')->count(),
            'delivery' => min(self::AttentionLimit, $items->where('group', 'delivery')->count()),
            'funding' => min(self::AttentionLimit, $items->where('group', 'funding')->count()),
            'risks' => min(self::AttentionLimit, $items->where('group', 'risks')->count()),
        ];
    }

    /**
     * How many attention items each group holds, for the queue's filter.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function attentionCounts(): array
    {
        $today = CarbonImmutable::today();

        // Organisation-wide: a count on the dashboard is the same for everyone.
        $delivery = $this->visible(Task::query(), 'tasks')->withoutGlobalScope(TaskVisibilityScope::class)->where('status', '!=', TaskStatus::Done)->whereDate('due_date', '<', $today)->count()
            + $this->visible(Milestone::query(), 'milestones')->where('status', MilestoneStatus::Planned)->whereDate('planned_end_date', '<', $today)->count();
        $funding = $this->canView('transfers')
            ? TransfersTracking::query()->where('ar_status', ArStatus::Pending)->whereDate('scheduled_date', '<', $today)->count()
            : 0;
        $risks = $this->visible(Risk::query(), 'risks')->where('happened', true)->count();

        return [
            'all' => $delivery + $funding + $risks,
            'delivery' => $delivery,
            'funding' => $funding,
            'risks' => $risks,
        ];
    }

    /**
     * Where a transfer row leads: its funding agreement for someone who may open
     * agreements, otherwise the transfers list they are allowed to see.
     */
    private function transferHref(TransfersTracking $transfer): string
    {
        return $this->canView('funding-agreements')
            ? route('funding-agreements.show', $transfer->donorProposal)
            : route('transfers.index');
    }

    /**
     * Shape one attention row.
     *
     * @return array{key: string, group: string, icon: string, tone: string, title: string, context: string, badge: string, daysLate: int|null, href: string}
     */
    private function attentionEntry(string $key, string $group, string $icon, string $title, string $context, ?int $daysLate, string $href): array
    {
        return [
            'key' => $key,
            'group' => $group,
            'icon' => $icon,
            'tone' => match ($group) {
                'funding' => 'funding',
                'risks' => 'critical',
                default => 'warn',
            },
            'title' => $title,
            'context' => $context,
            'badge' => $daysLate === null
                ? __('common.materialised')
                : trans_choice('dashboard.days_late', max(1, $daysLate), ['count' => max(1, $daysLate)]),
            'daysLate' => $daysLate,
            'href' => $href,
        ];
    }

    /**
     * The parent context shown for a task row: its milestone's project name
     * when sourced from a milestone (matching what the old milestone_id path
     * showed), otherwise the task's own source name (a proposal).
     */
    private function taskParentName(Task $task): string
    {
        return $task->source instanceof Milestone
            ? ($task->source->project?->name ?? '—')
            : $task->sourceLabel();
    }
}
