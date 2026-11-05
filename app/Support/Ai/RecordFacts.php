<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Risk;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The plain-text facts a record page hands to the AI summarizer.
 *
 * Built only from values the page already computed for this viewer, so the
 * AI never reads anything the page would not show them (money stays out
 * unless the page passes it). Dates are absolute so "late" is checkable.
 */
final class RecordFacts
{
    /**
     * @param  array{health: string, stage: int, donor: string, reports: array{validated: int, total: int}, next: array{text: string}, lede: string}  $story
     * @param  Collection<int, Milestone>  $milestones
     * @param  Collection<int, Task>  $tasks
     * @param  Collection<int, Risk>  $risks
     * @param  list<string>  $money  One line per currency, only when the viewer may see money.
     */
    public static function project(Project $project, array $story, ?float $progress, Collection $milestones, Collection $tasks, Collection $risks, array $money): string
    {
        $today = CarbonImmutable::today();
        $open = $tasks->filter(fn (Task $task): bool => $task->status !== TaskStatus::Done);
        $overdue = $open->filter(fn (Task $task): bool => $task->due_date !== null && $task->due_date->lt($today));

        $facts = [
            'TODAY: '.$today->toDateString(),
            'RECORD: project "'.$project->name.'"',
            'STATUS: '.$project->status->label().' · health: '.$story['health'],
            'DATES: '.self::date($project->start_date).' to '.self::date($project->end_date),
            'DONOR: '.($story['donor'] !== '' ? $story['donor'] : '—'),
            'SUMMARY ON PAGE: '.self::line($story['lede']),
            'MILESTONE PROGRESS: '.($progress === null ? 'not measured' : $progress.'%'),
            'MONTHLY REPORTS VALIDATED: '.$story['reports']['validated'].' of '.$story['reports']['total'],
            'NEXT STEP ON PAGE: '.self::line($story['next']['text']),
            'TASKS: '.$open->count().' open, '.$overdue->count().' overdue',
            '',
            'MILESTONES (name | status | planned end):',
        ];

        foreach ($milestones->take(12) as $milestone) {
            $facts[] = '- '.$milestone->name.' | '.$milestone->status->label().' | '.self::date($milestone->planned_end_date);
        }

        $facts[] = $milestones->isEmpty() ? '- none' : '';
        $facts[] = 'OVERDUE TASKS (title | due):';

        foreach ($overdue->take(8) as $task) {
            $facts[] = '- '.$task->title.' | '.self::date($task->due_date);
        }

        $facts[] = $overdue->isEmpty() ? '- none' : '';
        $facts[] = 'RISKS (risk | happened | impact):';

        foreach ($risks->take(8) as $risk) {
            $facts[] = '- '.$risk->name.' | '.($risk->happened ? 'yes' : 'no').' | '.self::line($risk->impact);
        }

        $facts[] = $risks->isEmpty() ? '- none' : '';

        if ($money !== []) {
            $facts[] = 'MONEY:';

            foreach ($money as $line) {
                $facts[] = '- '.$line;
            }
        }

        return implode("\n", $facts);
    }

    /**
     * @param  array<int, array{label: string, figure: string, suffix: string|null, caption: string, alarm: bool}>  $vitals
     * @param  list<array{text: string, critical: bool}>  $warnings
     * @param  list<array{date: CarbonImmutable, title: string, detail: string, state: string}>  $deadlines
     */
    public static function proposal(Proposal $proposal, string $donor, array $vitals, array $warnings, array $deadlines): string
    {
        $facts = [
            'TODAY: '.CarbonImmutable::today()->toDateString(),
            'RECORD: grant proposal "'.$proposal->name.'"',
            'STATUS: '.$proposal->status->label(),
            'DONOR: '.($donor !== '' ? $donor : '—'),
            'PLANNED PERIOD: '.self::date($proposal->planned_date_begin).' to '.self::date($proposal->extended_date_end ?? $proposal->planned_date_end),
            '',
            'READINGS ON PAGE (reading | figure | note | needs attention):',
        ];

        foreach ($vitals as $vital) {
            $facts[] = '- '.implode(' | ', [$vital['label'], trim($vital['figure'].' '.($vital['suffix'] ?? '')), self::line($vital['caption']), $vital['alarm'] ? 'yes' : 'no']);
        }

        $facts[] = '';
        $facts[] = 'WARNINGS (most urgent first):';

        foreach ($warnings as $warning) {
            $facts[] = '- '.($warning['critical'] ? '[critical] ' : '').self::line($warning['text']);
        }

        $facts[] = $warnings === [] ? '- none' : '';
        $facts[] = 'UPCOMING AND LATE DEADLINES (date | item | detail | state):';

        foreach (array_slice($deadlines, 0, 8) as $deadline) {
            $facts[] = '- '.implode(' | ', [$deadline['date']->toDateString(), $deadline['title'], self::line($deadline['detail']), $deadline['state']]);
        }

        if ($deadlines === []) {
            $facts[] = '- none';
        }

        return implode("\n", $facts);
    }

    private static function date(?CarbonImmutable $date): string
    {
        return $date?->toDateString() ?? '—';
    }

    private static function line(?string $value): string
    {
        $value = trim(strip_tags((string) $value));

        return $value === '' ? '—' : preg_replace('/\s+/', ' ', $value) ?? $value;
    }
}
