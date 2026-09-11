<?php

namespace Database\Seeders;

use App\Enums\MediaCollection;
use App\Models\Media;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Risk;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskUpdate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * The delivery layer: risks, milestones, tasks, task updates and attachments.
 *
 * Depends on ProgrammeSeeder.
 */
class DeliverySeeder extends Seeder
{
    /**
     * Seed the delivery tables.
     */
    public function run(): void
    {
        $users = User::query()->get();

        Project::query()->with('proposals')->each(function (Project $project) use ($users): void {
            Risk::factory(2)->create([
                'project_id' => $project->id,
            ]);

            Risk::factory()->happened()->create([
                'project_id' => $project->id,
            ]);

            $parent = Milestone::factory()->create([
                'project_id' => $project->id,
                'name' => 'Inception phase',
                'responsible_user_id' => $users->random()->id,
                'sequence' => 1,
                'weight_percentage' => 30,
            ]);

            $reached = Milestone::factory()->reached()->create([
                'project_id' => $project->id,
                'name' => 'Baseline assessment',
                'responsible_user_id' => $users->random()->id,
                'parent_milestone_id' => $parent->id,
                'sequence' => 2,
                'weight_percentage' => 70,
            ]);

            $this->seedMedia($project, $reached, $users);

            $proposal = $project->proposals->first();

            if ($proposal === null) {
                return;
            }

            $this->seedTasks($proposal, $parent, $users);
        });
    }

    /**
     * Attach a project's paperwork and a milestone's evidence.
     *
     * These rows carry no file on disk: no cloud driver is installed and
     * storage/app is empty, so the paths describe files that do not exist yet.
     * The UI renders them as metadata and emits no download link.
     *
     * @param  Collection<int, User>  $users
     */
    protected function seedMedia(Project $project, Milestone $milestone, Collection $users): void
    {
        foreach ([MediaCollection::Proposal, MediaCollection::Agreement, MediaCollection::Photo] as $collection) {
            Media::factory()->inCollection($collection)->create([
                'mediable_type' => 'project',
                'mediable_id' => $project->id,
                'uploaded_by' => $users->random()->id,
            ]);
        }

        Media::factory(2)->forMilestone($milestone->id)->create([
            'uploaded_by' => $users->random()->id,
        ]);
    }

    /**
     * Seed a small board of tasks under one proposal milestone.
     *
     * @param  Collection<int, User>  $users
     */
    protected function seedTasks(Proposal $proposal, Milestone $milestone, $users): void
    {
        $open = Task::factory(2)->create([
            'proposal_id' => $proposal->id,
            'milestone_id' => $milestone->id,
            'assigned_to' => fn (): int => $users->random()->id,
            'created_by' => fn (): int => $users->random()->id,
        ]);

        $done = Task::factory()->completed()->create([
            'proposal_id' => $proposal->id,
            'milestone_id' => $milestone->id,
            'assigned_to' => $users->random()->id,
            'created_by' => $users->random()->id,
        ]);

        foreach ($open->push($done) as $task) {
            TaskUpdate::factory(2)->create([
                'task_id' => $task->id,
                'user_id' => $users->random()->id,
            ]);

            TaskAttachment::factory()->create([
                'task_id' => $task->id,
            ]);
        }
    }
}
