<?php

namespace Database\Seeders;

use App\Enums\MediaCollection;
use App\Models\Media;
use App\Models\Project;
use App\Models\Risk;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\TaskAttachment;
use App\Models\User;
use Database\Seeders\Concerns\PicksHrRecords;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * The delivery layer: risks, tasks, task updates and attachments.
 *
 * Depends on DemoDeliverySeeder.
 */
class DeliverySeeder extends Seeder
{
    use PicksHrRecords;

    /**
     * Seed the delivery tables.
     */
    public function run(): void
    {
        $users = User::query()->get();
        $employeeIds = $this->hrEmployeeIds();

        Project::query()->with('proposals')->each(function (Project $project) use ($users, $employeeIds): void {
            Risk::factory(2)->create([
                'project_id' => $project->id,
            ]);

            Risk::factory()->happened()->create([
                'project_id' => $project->id,
            ]);

            $this->seedMedia($project, $users);

            $proposal = $project->proposals->first();

            if ($proposal === null) {
                return;
            }

            $this->seedTasks($project, $users, $employeeIds);
        });
    }

    /**
     * Attach a project's paperwork.
     *
     * These rows carry no file on disk: no cloud driver is installed and
     * storage/app is empty, so the paths describe files that do not exist yet.
     * The UI renders them as metadata and emits no download link.
     *
     * @param  Collection<int, User>  $users
     */
    protected function seedMedia(Project $project, Collection $users): void
    {
        foreach ([MediaCollection::Proposal, MediaCollection::Agreement, MediaCollection::Photo] as $collection) {
            Media::factory()->inCollection($collection)->create([
                'mediable_type' => 'project',
                'mediable_id' => $project->id,
                'uploaded_by' => $users->random()->id,
            ]);
        }
    }

    /**
     * Seed a small board of tasks on a funded project.
     *
     * @param  Collection<int, User>  $users
     * @param  Collection<int, int>  $employeeIds
     */
    protected function seedTasks(Project $project, $users, Collection $employeeIds): void
    {
        $assignee = TaskAssignee::factory()->state(fn (): array => ['employee_id' => $employeeIds->random()]);

        $open = Task::factory(2)->has($assignee, 'taskAssignees')->create([
            'source_type' => 'project',
            'source_id' => $project->id,
            'created_by' => fn (): int => $users->random()->id,
        ]);

        $done = Task::factory()->completed()->has($assignee, 'taskAssignees')->create([
            'source_type' => 'project',
            'source_id' => $project->id,
            'created_by' => $users->random()->id,
        ]);

        foreach ($open->push($done) as $task) {
            TaskAttachment::factory()->create([
                'task_id' => $task->id,
            ]);
        }
    }
}
