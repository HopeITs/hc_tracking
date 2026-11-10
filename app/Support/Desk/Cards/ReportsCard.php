<?php

declare(strict_types=1);

namespace App\Support\Desk\Cards;

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Indicator readings (reports) in card form: what is late, what waits for a
 * review, and the newest readings with the indicator and project they are for.
 */
final class ReportsCard extends PageCard
{
    public function route(): string
    {
        return 'reports.index';
    }

    public function figures(User $user): array
    {
        return [
            $this->figure(__('desk-workspace.card.figure.late'), Report::query()->overdue()->count(), 'critical'),
            $this->figure(ReportStatus::Draft->label(), Report::query()->where('status', ReportStatus::Draft)->count(), 'warn'),
            $this->figure(ReportStatus::Approved->label(), Report::query()->approved()->count(), 'good'),
        ];
    }

    public function breakdown(User $user): array
    {
        $counts = Report::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return $this->parts(array_map(fn (ReportStatus $status): array => [
            'label' => (string) $status->label(),
            'count' => (int) ($counts[$status->value] ?? 0),
            'tone' => $status->tone()->value,
        ], ReportStatus::cases()));
    }

    public function hero(User $user): ?array
    {
        $counts = Report::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $late = Report::query()->overdue()->count();

        return [
            'kind' => 'flow',
            'big' => $this->number($late),
            'caption' => (string) __('desk-workspace.card.figure.late'),
            'tone' => $late > 0 ? 'critical' : 'good',
            'items' => array_map(fn (ReportStatus $status): array => [
                'label' => (string) $status->label(),
                'value' => $this->number((int) ($counts[$status->value] ?? 0)),
                'tone' => $status->tone()->value,
            ], ReportStatus::cases()),
        ];
    }

    public function layout(): string
    {
        return 'agenda';
    }

    protected function records(User $user, string $search): Collection
    {
        return Report::query()->with(['planIndicator.indicator:id,name', 'planIndicator.project:id,name'])
            ->latest('planned_date')->latest('id')->limit(self::RowLimit)->get()
            ->map(function (Report $report): array {
                $badge = $this->badgeOf($report->status);

                return $this->shaped(
                    $this->row(
                        'rp-'.$report->id,
                        (string) ($report->planIndicator?->indicator?->name ?? '—'),
                        (string) ($report->planIndicator?->project?->name ?? ''),
                        $badge,
                        route('reports.show', $report),
                    ),
                    lead: ['kind' => 'date', 'big' => $report->planned_date->translatedFormat('j'), 'small' => $report->planned_date->translatedFormat('M y'), 'tone' => $badge['tone']],
                );
            });
    }
}
