<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Models\Project;

/**
 * The small presentation helpers of the project directory views: the tone
 * classes of a schedule verdict, the relation chips of a card and the
 * demographic commitments.
 *
 * Used by projects/index only. The page's top PHP block keeps the two helpers
 * that capture its `$today` (`$elapsedOf`, `$scheduleOf`); the views call these
 * as `$this->name(...)`.
 */
trait PresentsProjectDirectory
{
    /**
     * The fill mirrors the pill, so a verdict never disagrees with its own bar.
     */
    protected function progressFillClasses(string $tone): string
    {
        return match ($tone) {
            'critical' => 'bg-primary dark:bg-status-critical',
            'warn' => 'bg-status-warn',
            'good' => 'bg-status-good',
            default => 'bg-secondary-dark dark:bg-ink-muted',
        };
    }

    /**
     * The board card reads the same verdict at full RAG resolution. Critical
     * fills solid so white type carries it in both themes; the softer tones
     * already flip their own tokens in dark mode.
     */
    protected function tonePillClasses(string $tone): string
    {
        return match ($tone) {
            'critical' => 'border-primary bg-primary dark:border-transparent dark:bg-status-critical-soft dark:text-status-critical font-semibold text-white',
            'warn' => 'border-status-warn/40 bg-status-warn-soft font-semibold text-status-warn',
            'good' => 'border-status-good/40 bg-status-good-soft font-semibold text-status-good',
            default => 'border-board-line text-secondary-medium dark:text-ink-muted',
        };
    }

    /**
     * Every relation a project carries, counted in the list query and shown in
     * one fixed order so the same chip sits in the same place on every record.
     *
     * @param  Project&object{materialised_risks_count: int}  $project
     * @return list<array{icon: string, count: int, label: string, alert: bool}>
     */
    protected function connectionsOf(Project $project): array
    {
        $openRisks = $project->risks_count - $project->materialised_risks_count;

        /* An empty relation says nothing worth a chip; a materialised risk always does. */
        return array_values(array_filter([
            ['icon' => 'flag', 'count' => $project->milestones_count, 'label' => trans_choice('common.count_milestone', $project->milestones_count, ['count' => $project->milestones_count]), 'alert' => false],
            ['icon' => 'chart-bar', 'count' => $project->plan_indicators_count, 'label' => trans_choice('common.count_target', $project->plan_indicators_count, ['count' => $project->plan_indicators_count]), 'alert' => false],
            ['icon' => 'presentation-chart-line', 'count' => $project->indicators_count, 'label' => trans_choice('projects.count_indicator', $project->indicators_count, ['count' => $project->indicators_count]), 'alert' => false],
            ['icon' => 'document-text', 'count' => $project->proposals_count, 'label' => trans_choice('projects.count_proposal', $project->proposals_count, ['count' => $project->proposals_count]), 'alert' => false],
            ['icon' => 'paper-clip', 'count' => $project->media_count, 'label' => trans_choice('projects.count_file', $project->media_count, ['count' => $project->media_count]), 'alert' => false],
            [
                'icon' => 'shield-exclamation',
                'count' => $project->risks_count,
                'label' => $project->materialised_risks_count > 0
                    ? trans_choice('projects.count_risks_materialised', $project->risks_count, ['count' => $project->risks_count, 'materialised' => $project->materialised_risks_count])
                    : trans_choice('programs.timeline_open_risks', $openRisks, ['count' => $openRisks]),
                'alert' => $project->materialised_risks_count > 0,
            ],
        ], fn (array $connection): bool => $connection['count'] > 0 || $connection['alert']));
    }

    /**
     * The four demographic commitments, shown only where they were recorded.
     *
     * @return array<string, int>
     */
    protected function demographicsOf(Project $project): array
    {
        return array_filter([
            __('projects.men') => $project->target_men,
            __('projects.women') => $project->target_women,
            __('projects.boys') => $project->target_boys,
            __('projects.girls') => $project->target_girls,
        ], fn (?int $value): bool => $value !== null);
    }
}
