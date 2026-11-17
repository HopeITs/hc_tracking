<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Y-02 backfill (D-75, D-76, D-82, D-88, wave4/AGENT.md §4): gives every
 * template that still hangs on a project its place and its proposals.
 *
 * City and program come from the project; the center from the project's own
 * `center_id`, else from the location row that carries the project when it
 * has exactly one center; city-wide when that row is `city_wide`; otherwise
 * the template stays "center not set". Rows that disagree leave it unset and
 * are listed. Every proposal of the project (`project_proposal`) is linked
 * with `joined_at` = the template's `created_at`. Nothing is merged and no
 * center is guessed (D-80).
 *
 * This and the Y-02 migration are the only places that read `projects` for
 * the template (D-74), so the logic lives here once and the migration calls
 * it. `--dry-run` prints the same counts and writes nothing. Idempotent: it
 * only touches templates with a project and no proposal link yet.
 */
class PlaceBackfillReportTemplates extends Command
{
    protected $signature = 'report-templates:place-backfill
        {--dry-run : Print the counts without writing anything}';

    protected $description = 'Give existing report templates their place and proposals (Y-02)';

    public function handle(): int
    {
        $plan = $this->plan();
        $counts = $this->counts($plan);

        foreach ($counts as $key => $value) {
            $this->line(__('report_templates.console.backfill_'.$key, ['count' => $value]));
        }

        if ($plan !== [] && $counts['without_proposal'] > 0) {
            $this->error(__('report_templates.console.backfill_stopped', ['ids' => implode(', ', $this->idsWithoutProposal($plan))]));

            return self::FAILURE;
        }

        if ($counts['disagree'] > 0) {
            $this->line(__('report_templates.console.backfill_disagree_ids', ['ids' => implode(', ', array_keys(array_filter($plan, fn (array $row): bool => $row['disagree'])))]));
        }

        if (! $this->option('dry-run')) {
            $this->apply($plan);
        }

        return self::SUCCESS;
    }

    /**
     * The answer for every template that has a project and no proposal link.
     *
     * @return array<int, array{project_id: int, city_id: int, program_id: int, center_id: int|null, city_wide: bool, disagree: bool, created_at: mixed, proposal_ids: list<int>}>
     */
    public function plan(): array
    {
        $templates = DB::table('report_templates')
            ->whereNotNull('project_id')
            ->when(Schema::hasTable('proposal_report_template'), fn ($query) => $query->whereNotExists(fn ($links) => $links->selectRaw('1')->from('proposal_report_template')->whereColumn('proposal_report_template.report_template_id', 'report_templates.id')))
            ->orderBy('id')
            ->get(['id', 'project_id', 'created_at']);

        $plan = [];

        foreach ($templates as $template) {
            $project = DB::table('projects')->where('id', $template->project_id)->first(['city_id', 'program_id', 'center_id']);

            if ($project === null || $project->program_id === null || $project->city_id === null) {
                throw new RuntimeException("Template {$template->id} has a project with no city or program; nothing was changed.");
            }

            $answers = $this->rowAnswers((int) $template->project_id);
            $disagree = count($answers) > 1;
            $centerId = null;
            $cityWide = false;

            if ($project->center_id !== null) {
                $centerId = (int) $project->center_id;
            } elseif (! $disagree && $answers !== []) {
                $answer = array_values($answers)[0];
                $centerId = $answer['center_id'];
                $cityWide = $answer['city_wide'];
            }

            $plan[(int) $template->id] = [
                'project_id' => (int) $template->project_id,
                'city_id' => (int) $project->city_id,
                'program_id' => (int) $project->program_id,
                'center_id' => $centerId,
                'city_wide' => $cityWide,
                'disagree' => $disagree && $project->center_id === null,
                'created_at' => $template->created_at,
                'proposal_ids' => DB::table('project_proposal')->where('project_id', $template->project_id)->orderBy('proposal_id')->pluck('proposal_id')->map(fn ($id): int => (int) $id)->all(),
            ];
        }

        return $plan;
    }

    /**
     * Write the plan: place columns, then the proposal links.
     *
     * @param  array<int, array{project_id: int, city_id: int, program_id: int, center_id: int|null, city_wide: bool, disagree: bool, created_at: mixed, proposal_ids: list<int>}>  $plan
     */
    public function apply(array $plan): void
    {
        DB::transaction(function () use ($plan): void {
            foreach ($plan as $id => $row) {
                DB::table('report_templates')->where('id', $id)->update([
                    'city_id' => $row['city_id'],
                    'program_id' => $row['program_id'],
                    'center_id' => $row['center_id'],
                    'city_wide' => $row['city_wide'],
                ]);

                foreach ($row['proposal_ids'] as $proposalId) {
                    DB::table('proposal_report_template')->insertOrIgnore([
                        'proposal_id' => $proposalId,
                        'report_template_id' => $id,
                        'joined_at' => $row['created_at'],
                    ]);
                }
            }
        });
    }

    /**
     * What each row carrying the project says, de-duplicated: one center, city-wide, or nothing.
     *
     * @return array<string, array{center_id: int|null, city_wide: bool}>
     */
    private function rowAnswers(int $projectId): array
    {
        $answers = [];
        $rows = DB::table('proposal_locations')->where('project_id', $projectId)->get(['id', 'city_wide']);

        foreach ($rows as $row) {
            $centers = DB::table('proposal_location_centers')->where('proposal_location_id', $row->id)->pluck('center_id');

            $answer = match (true) {
                (bool) $row->city_wide => ['center_id' => null, 'city_wide' => true],
                $centers->count() === 1 => ['center_id' => (int) $centers->first(), 'city_wide' => false],
                default => ['center_id' => null, 'city_wide' => false],
            };

            $answers[($answer['city_wide'] ? 'cw' : ($answer['center_id'] ?? 'unset'))] = $answer;
        }

        return $answers;
    }

    /**
     * @param  array<int, array{project_id: int, city_id: int, program_id: int, center_id: int|null, city_wide: bool, disagree: bool, created_at: mixed, proposal_ids: list<int>}>  $plan
     * @return array{templates: int, with_center: int, city_wide: int, center_not_set: int, disagree: int, proposals_linked: int, without_proposal: int}
     */
    public function counts(array $plan): array
    {
        $rows = collect($plan);

        return [
            'templates' => $rows->count(),
            'with_center' => $rows->filter(fn (array $row): bool => $row['center_id'] !== null)->count(),
            'city_wide' => $rows->filter(fn (array $row): bool => $row['city_wide'])->count(),
            'center_not_set' => $rows->filter(fn (array $row): bool => $row['center_id'] === null && ! $row['city_wide'])->count(),
            'disagree' => $rows->filter(fn (array $row): bool => $row['disagree'])->count(),
            'proposals_linked' => $rows->sum(fn (array $row): int => count($row['proposal_ids'])),
            'without_proposal' => $rows->filter(fn (array $row): bool => $row['proposal_ids'] === [])->count(),
        ];
    }

    /**
     * @param  array<int, array{proposal_ids: list<int>}>  $plan
     * @return list<int>
     */
    private function idsWithoutProposal(array $plan): array
    {
        return array_keys(array_filter($plan, fn (array $row): bool => $row['proposal_ids'] === []));
    }
}
