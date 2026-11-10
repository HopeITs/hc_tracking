<?php

declare(strict_types=1);

namespace App\Support\Desk\Cards;

use App\Models\AnnualPlan;
use App\Models\Program;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Programs in card form: every program with its strategy and projects, and
 * whether this year's plan is in.
 */
final class ProgramsCard extends PageCard
{
    public function route(): string
    {
        return 'programs.index';
    }

    public function figures(User $user): array
    {
        $programs = Program::query()->count();
        $planned = AnnualPlan::query()->where('year', now()->year)->distinct()->count('program_id');

        return [
            $this->figure(__('desk-workspace.card.figure.programs'), $programs, 'active'),
            $this->figure(__('desk-workspace.card.figure.planned_this_year'), $planned, 'good'),
            $this->figure(__('desk-workspace.card.figure.no_plan_yet'), max(0, $programs - $planned), $programs > $planned ? 'warn' : 'neutral'),
        ];
    }

    public function hero(User $user): ?array
    {
        $programs = Program::query()->count();
        $planned = AnnualPlan::query()->where('year', now()->year)->distinct()->count('program_id');

        return [
            'kind' => 'ring',
            'big' => $this->number($planned).'/'.$this->number($programs),
            'caption' => (string) __('desk-workspace.card.figure.planned_this_year'),
            'share' => $programs > 0 ? round($planned / $programs * 100, 1) : 0.0,
            'tone' => $planned >= $programs && $programs > 0 ? 'good' : 'warn',
            'items' => [
                ['label' => (string) __('desk-workspace.card.figure.planned_this_year'), 'value' => $this->number($planned), 'tone' => 'good'],
                ['label' => (string) __('desk-workspace.card.figure.no_plan_yet'), 'value' => $this->number(max(0, $programs - $planned)), 'tone' => 'warn'],
            ],
        ];
    }

    public function layout(): string
    {
        return 'meter';
    }

    protected function records(User $user, string $search): Collection
    {
        $planned = AnnualPlan::query()->where('year', now()->year)->pluck('program_id')->flip();
        $programs = Program::query()->with('strategy:id,name')->withCount('projects')->orderByName()->limit(self::RowLimit)->get();
        $most = (int) $programs->max('projects_count');

        return $programs->map(fn (Program $program): array => $this->shaped(
            $this->row(
                'pg-'.$program->id,
                (string) $program->name,
                collect([$program->strategy?->name, trans_choice('desk-workspace.card.projects', (int) $program->projects_count, ['count' => (int) $program->projects_count])])->filter()->implode(' · '),
                $planned->has($program->id)
                    ? ['label' => (string) __('desk-workspace.card.planned', ['year' => now()->year]), 'tone' => 'good']
                    : ['label' => (string) __('desk-workspace.card.no_plan', ['year' => now()->year]), 'tone' => 'warn'],
                route('programs.show', $program),
            ),
            end: ['big' => $this->number((int) $program->projects_count)],
            bar: ['to' => $this->share((int) $program->projects_count, $most), 'tone' => $planned->has($program->id) ? 'good' : 'warn'],
        ));
    }
}
