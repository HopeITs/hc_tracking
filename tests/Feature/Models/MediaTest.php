<?php

use App\Enums\FileType;
use App\Enums\MediaCollection;
use App\Models\Media;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('a file resolves its owner through the morph map', function () {
    $project = Project::factory()->create();
    $media = Media::factory()->create(['mediable_type' => 'project', 'mediable_id' => $project->id]);

    expect($media->mediable)->toBeInstanceOf(Project::class)
        ->and($media->mediable->id)->toBe($project->id);
});

test('a milestone carries its evidence and a project its paperwork', function () {
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->create(['project_id' => $project->id]);

    Media::factory(2)->create(['mediable_type' => 'project', 'mediable_id' => $project->id]);
    Media::factory()->forMilestone($milestone->id)->create();

    expect($project->media)->toHaveCount(2)
        ->and($milestone->media)->toHaveCount(1)
        ->and($milestone->media->first()->collection)->toBe(MediaCollection::Evidence);
});

test('a file casts its collection and type to enums', function () {
    $media = Media::factory()->inCollection(MediaCollection::Agreement)->create()->fresh();

    expect($media->collection)->toBe(MediaCollection::Agreement)
        ->and($media->type)->toBeInstanceOf(FileType::class);
});

test('a file reports its uploader', function () {
    $user = User::factory()->create(['name' => 'Layla Haddad']);
    $media = Media::factory()->create(['uploaded_by' => $user->id]);

    expect($media->uploader->name)->toBe('Layla Haddad');
});

test('the collection scope narrows to one kind of file', function () {
    $project = Project::factory()->create();

    Media::factory()->inCollection(MediaCollection::Agreement)->create([
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
    ]);
    Media::factory()->inCollection(MediaCollection::Photo)->create([
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
    ]);

    expect(Media::query()->inCollection(MediaCollection::Photo)->count())->toBe(1);
});

test('deleting a project through Eloquent removes its files', function () {
    $project = Project::factory()->create();
    Media::factory(2)->create(['mediable_type' => 'project', 'mediable_id' => $project->id]);

    $project->delete();

    expect(DB::table('media')->where('mediable_type', 'project')->count())->toBe(0);
});

test('deleting a project also removes the evidence on its milestones', function () {
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->create(['project_id' => $project->id]);
    Media::factory()->forMilestone($milestone->id)->create();

    // milestones.project_id cascades at the database level, which fires no
    // model events. MediaOwnerObserver deletes the milestones through Eloquent
    // first so their media is not left pointing at nothing.
    $project->delete();

    expect(DB::table('media')->count())->toBe(0)
        ->and(DB::table('milestones')->count())->toBe(0);
});

test('deleting a parent milestone removes the evidence on its whole subtree', function () {
    $project = Project::factory()->create();
    $parent = Milestone::factory()->create(['project_id' => $project->id]);
    $child = Milestone::factory()->create([
        'project_id' => $project->id,
        'parent_milestone_id' => $parent->id,
    ]);

    Media::factory()->forMilestone($parent->id)->create();
    Media::factory()->forMilestone($child->id)->create();

    $parent->delete();

    expect(DB::table('media')->count())->toBe(0);
});

test('deleting one project leaves another project files alone', function () {
    $subject = Project::factory()->create();
    $other = Project::factory()->create();

    Media::factory()->create(['mediable_type' => 'project', 'mediable_id' => $subject->id]);
    Media::factory()->create(['mediable_type' => 'project', 'mediable_id' => $other->id]);

    $subject->delete();

    expect(DB::table('media')->count())->toBe(1)
        ->and((int) DB::table('media')->first()->mediable_id)->toBe($other->id);
});
