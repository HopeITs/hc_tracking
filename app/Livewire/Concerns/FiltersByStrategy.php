<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Program;
use App\Models\Strategy;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * A register narrowed to one strategy's work, reached from a figure on the
 * strategies register (?strategy=<id>). There is no picker for it: the page
 * shows a removable chip (x-desk.strategy-chip) while it applies.
 *
 * A strategy's work is everything on the projects of its programs. The page
 * uses WithPagination: clearing the filter goes back to the first page.
 *
 * @property-read Strategy|null $strategyFilter  #[Computed] strategyFilter()
 * @property-read list<int> $strategyProgramIds  #[Computed] strategyProgramIds()
 */
trait FiltersByStrategy
{
    #[Url(except: '')]
    public string $strategy = '';

    /**
     * The strategy being filtered on, or null when none (or an unknown id) is set.
     */
    #[Computed]
    public function strategyFilter(): ?Strategy
    {
        return ctype_digit($this->strategy)
            ? Strategy::query()->select(['id', 'name'])->find((int) $this->strategy)
            : null;
    }

    /**
     * The ids of the filtered strategy's programs, for registers that filter in memory.
     *
     * @return list<int>
     */
    #[Computed]
    public function strategyProgramIds(): array
    {
        $strategy = $this->strategyFilter;

        return $strategy === null ? [] : array_values(Program::query()->where('strategy_id', $strategy->id)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    public function clearStrategyFilter(): void
    {
        $this->strategy = '';
        unset($this->strategyFilter, $this->strategyProgramIds);
        $this->resetPage();
    }

    /**
     * Keep only rows whose project belongs to the filtered strategy.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  string  $projectColumn  the qualified column holding the row's project id
     * @return Builder<TModel>
     */
    protected function whereInStrategy(Builder $query, string $projectColumn): Builder
    {
        $strategy = $this->strategyFilter;

        if ($strategy === null) {
            return $query;
        }

        return $query->whereIn($projectColumn, fn ($projects) => $projects
            ->select('projects.id')
            ->from('projects')
            ->join('programs', 'programs.id', '=', 'projects.program_id')
            ->where('programs.strategy_id', $strategy->id));
    }

    /**
     * Keep only rows whose proposal funds a project of the filtered strategy.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  string  $proposalColumn  the qualified column holding the row's proposal id
     * @return Builder<TModel>
     */
    protected function whereProposalInStrategy(Builder $query, string $proposalColumn): Builder
    {
        $strategy = $this->strategyFilter;

        if ($strategy === null) {
            return $query;
        }

        return $query->whereIn($proposalColumn, fn ($links) => $links
            ->select('project_proposal.proposal_id')
            ->from('project_proposal')
            ->join('projects', 'projects.id', '=', 'project_proposal.project_id')
            ->join('programs', 'programs.id', '=', 'projects.program_id')
            ->where('programs.strategy_id', $strategy->id));
    }
}
