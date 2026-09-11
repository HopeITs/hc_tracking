<?php

use App\Enums\FileType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Insert a project and return its id, wiring up the city, program and owner it
 * hangs from.
 */
function insertMediaOwningProject(): int
{
    $user = User::factory()->create();

    return insertProject(insertCity(), insertProgram(insertStrategy(), $user->id), $user->id);
}

test('a new file takes its collection disk and upload date from the column defaults', function () {
    $user = User::factory()->create();

    $media = DB::table('media')->find(insertMedia('project', insertMediaOwningProject(), $user->id));

    expect($media->collection)->toBe('other')
        ->and($media->disk)->toBe('local')
        ->and($media->uploaded_at)->toBe(now()->toDateString());
});

test('the morph column stores the map key rather than a class name', function () {
    $user = User::factory()->create();

    $media = DB::table('media')->find(insertMedia('project', insertMediaOwningProject(), $user->id));

    expect($media->mediable_type)->toBe('project')
        ->and($media->mediable_type)->not->toContain('App\\Models');
});

test('a file can be attached to a milestone as well as a project', function () {
    $user = User::factory()->create();
    $projectId = insertMediaOwningProject();
    $milestoneId = insertMilestone($projectId, $user->id);

    $media = DB::table('media')->find(insertMedia('milestone', $milestoneId, $user->id));

    expect($media->mediable_type)->toBe('milestone')
        ->and((int) $media->mediable_id)->toBe($milestoneId);
});

test('an uploader is not deletable while their files remain', function () {
    $user = User::factory()->create();
    insertMedia('project', insertMediaOwningProject(), $user->id);

    expect(fn () => DB::table('users')->where('id', $user->id)->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('users', ['id' => $user->id]);
});

test('no foreign key protects the owner id, so a raw delete leaves the file behind', function () {
    $user = User::factory()->create();
    $projectId = insertMediaOwningProject();
    $mediaId = insertMedia('project', $projectId, $user->id);

    // A query-builder delete fires no model events, so MediaOwnerObserver never
    // runs. mediable_id carries no foreign key — a polymorphic column cannot —
    // so the database cannot stop this either. Documented, not fixed: delete
    // owners through Eloquent.
    DB::table('projects')->where('id', $projectId)->delete();

    $orphan = DB::table('media')->find($mediaId);

    expect($orphan)->not->toBeNull()
        ->and((int) $orphan->mediable_id)->toBe($projectId);
});

test('a file records its own extension and size', function () {
    $user = User::factory()->create();
    $projectId = insertMediaOwningProject();

    $mediaId = DB::table('media')->insertGetId([
        'mediable_type' => 'project',
        'mediable_id' => $projectId,
        'path' => 'media/signed-agreement.pdf',
        'original_name' => 'signed-agreement.pdf',
        'type' => FileType::Pdf->value,
        'size' => 2_400_000,
        'uploaded_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $media = DB::table('media')->find($mediaId);

    expect($media->type)->toBe('pdf')
        ->and((int) $media->size)->toBe(2_400_000)
        ->and($media->original_name)->toBe('signed-agreement.pdf');
});
