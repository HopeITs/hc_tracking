<?php

declare(strict_types=1);

namespace App\Support\Desk\Cards;

use App\Enums\DonorReportState;
use App\Models\DonorReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Donor reports in card form: what is late, what falls due next and what
 * went out, over the reports the viewer may open (DonorReportPolicy::view,
 * as the donor reports page reads it).
 */
final class DonorReportsCard extends PageCard
{
    public function route(): string
    {
        return 'donor-reports.index';
    }

    public function figures(User $user): array
    {
        $today = CarbonImmutable::today();

        return [
            $this->figure(DonorReportState::Overdue->label(), $this->visible($user)->whereNull('submitted_on')->whereDate('due_on', '<', $today)->count(), 'critical'),
            $this->figure(__('desk-workspace.card.figure.due_30'), $this->visible($user)->whereNull('submitted_on')->whereBetween('due_on', [$today, $today->addDays(30)])->count(), 'warn'),
            $this->figure(DonorReportState::Submitted->label(), $this->visible($user)->whereNotNull('submitted_on')->count(), 'good'),
        ];
    }

    public function breakdown(User $user): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $overdue = $this->visible($user)->whereNull('submitted_on')->whereDate('due_on', '<', $today)->count();
        $due = $this->visible($user)->whereNull('submitted_on')->whereDate('due_on', '>=', $today)->count();
        $sent = $this->visible($user)->whereNotNull('submitted_on')->count();

        return $this->parts([
            ['label' => DonorReportState::Overdue->label(), 'count' => $overdue, 'tone' => DonorReportState::Overdue->tone()->value],
            ['label' => DonorReportState::Due->label(), 'count' => $due, 'tone' => DonorReportState::Due->tone()->value],
            ['label' => DonorReportState::Submitted->label(), 'count' => $sent, 'tone' => DonorReportState::Submitted->tone()->value],
        ]);
    }

    public function hero(User $user): ?array
    {
        $today = CarbonImmutable::today();
        $overdue = $this->visible($user)->whereNull('submitted_on')->whereDate('due_on', '<', $today)->count();
        $due = $this->visible($user)->whereNull('submitted_on')->whereBetween('due_on', [$today, $today->addWeeks(8)])->pluck('due_on');
        $weeks = [];

        for ($week = 0; $week < 8; $week++) {
            $start = $today->addWeeks($week);
            $count = $due->filter(fn ($date): bool => $date >= $start && $date < $start->addWeek())->count();
            $weeks[] = ['label' => $start->translatedFormat('j/n'), 'value' => (string) $count, 'count' => $count, 'tone' => $week < 2 && $count > 0 ? 'warn' : 'active'];
        }

        return [
            'kind' => 'weeks',
            'big' => $this->number($overdue),
            'caption' => (string) DonorReportState::Overdue->label(),
            'tone' => $overdue > 0 ? 'critical' : 'good',
            'items' => $weeks,
        ];
    }

    public function layout(): string
    {
        return 'agenda';
    }

    protected function records(User $user, string $search): Collection
    {
        return $this->visible($user)->with('donorProposal.donor:id,name')
            ->orderByRaw('submitted_on is not null')->orderBy('due_on')->limit(self::RowLimit)->get()
            ->map(function (DonorReport $report): array {
                $state = $report->isSubmitted() ? DonorReportState::Submitted : ($report->isOverdue() ? DonorReportState::Overdue : DonorReportState::Due);
                $badge = $this->badgeOf($state);

                return $this->shaped(
                    $this->row(
                        'dr-'.$report->id,
                        $report->title,
                        (string) ($report->donorProposal?->donor?->name ?? ''),
                        $badge,
                        route('donor-reports.show', $report),
                    ),
                    lead: ['kind' => 'date', 'big' => $report->due_on->translatedFormat('j'), 'small' => $report->due_on->translatedFormat('M y'), 'tone' => $badge['tone']],
                );
            });
    }

    /**
     * @return Builder<DonorReport>
     */
    private function visible(User $user): Builder
    {
        return DonorReport::query()->unless(
            $user->isAdministrator() || $user->can('funding-agreements.view') || $user->can('reports.aggregate'),
            fn (Builder $query) => $query->where('responsible_employee_id', $user->employee_id ?? 0),
        );
    }
}
