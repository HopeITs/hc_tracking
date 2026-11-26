<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Location rows for the seeded proposals (W-34a, D-93): one row per place a
 * proposal delivers at. Runs after the proposal seeders; idempotent.
 *
 * Y-17: it builds the rows itself. It used to call ProposalLocationBackfill,
 * the one-off tool that read `project_proposal`, and Y-17 deleted that with
 * the link it backfilled.
 */
class ProposalLocationSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRowsForLinkedProjects();
    }

    /**
     * One row per project a seeded proposal still links, taking its place
     * from the project. Idempotent: a proposal and city that already has a
     * row is left alone.
     */
    private function seedRowsForLinkedProjects(): void
    {
        $links = DB::table('project_proposal')
            ->join('projects', 'projects.id', '=', 'project_proposal.project_id')
            ->orderBy('project_proposal.proposal_id')
            ->orderBy('projects.id')
            ->get(['project_proposal.proposal_id', 'projects.id as project_id', 'projects.city_id', 'projects.program_id', 'projects.user_id', 'projects.center_id']);

        foreach ($links as $link) {
            $exists = ProposalLocation::query()
                ->where('proposal_id', $link->proposal_id)
                ->where('city_id', (int) $link->city_id)
                ->where('program_id', $link->program_id)
                ->exists();

            if ($exists) {
                continue;
            }

            $row = ProposalLocation::query()->create([
                'proposal_id' => (int) $link->proposal_id,
                'city_id' => (int) $link->city_id,
                'program_id' => $link->program_id === null ? null : (int) $link->program_id,
                'responsible_employee_id' => $link->user_id === null ? null : (int) $link->user_id,
                'project_id' => (int) $link->project_id,
                'is_new' => false,
                'sort' => (int) ProposalLocation::query()->where('proposal_id', $link->proposal_id)->max('sort') + 1,
            ]);

            if ($link->center_id !== null) {
                $row->centers()->create(['center_id' => (int) $link->center_id]);
            }
        }
    }
}
