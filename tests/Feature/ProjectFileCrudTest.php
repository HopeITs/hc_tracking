<?php

use App\Enums\FileType;
use App\Enums\MediaCollection;
use App\Models\Media;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('uploading a valid file creates a media row and writes the file to disk', function () {
    Storage::fake('local');
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $file = UploadedFile::fake()->create('signed-agreement.pdf', 100, 'application/pdf');

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'files')
        ->call('openCreateFileModal')
        ->set('newFile', $file)
        ->set('fileCategory', MediaCollection::Agreement->value)
        ->set('fileDisplayName', 'Signed agreement')
        ->call('saveFile')
        ->assertHasNoErrors();

    $media = Media::where('mediable_type', 'project')->where('mediable_id', $project->id)->sole();

    expect($media->collection)->toBe(MediaCollection::Agreement)
        ->and($media->type)->toBe(FileType::Pdf)
        ->and($media->original_name)->toBe('Signed agreement.pdf')
        ->and($media->disk)->toBe('local')
        ->and($media->uploaded_by)->toBe($user->id);

    Storage::disk('local')->assertExists($media->path);
});

test('a display name typed without an extension keeps the uploaded files real extension, so the download filename still opens correctly', function () {
    Storage::fake('local');
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $file = UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg');

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'files')
        ->call('openCreateFileModal')
        ->set('newFile', $file)
        ->set('fileCategory', MediaCollection::Photo->value)
        ->set('fileDisplayName', 'Site visit')
        ->call('saveFile')
        ->assertHasNoErrors();

    $media = Media::where('mediable_type', 'project')->where('mediable_id', $project->id)->sole();

    expect($media->original_name)->toBe('Site visit.jpg');
});

test('uploading a file over 5mb fails validation and stores no row or disk file', function () {
    Storage::fake('local');
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $file = UploadedFile::fake()->create('too-big.pdf', 5121, 'application/pdf');

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'files')
        ->call('openCreateFileModal')
        ->set('newFile', $file)
        ->set('fileCategory', MediaCollection::Agreement->value)
        ->call('saveFile')
        ->assertHasErrors(['newFile' => 'max']);

    expect(Media::where('mediable_type', 'project')->where('mediable_id', $project->id)->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('media/projects/'.$project->id);
});

test('uploading a file with a disallowed extension fails validation and stores no row or disk file', function () {
    Storage::fake('local');
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $file = UploadedFile::fake()->create('script.exe', 100, 'application/x-msdownload');

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'files')
        ->call('openCreateFileModal')
        ->set('newFile', $file)
        ->set('fileCategory', MediaCollection::Agreement->value)
        ->call('saveFile')
        ->assertHasErrors(['newFile' => 'mimes']);

    expect(Media::where('mediable_type', 'project')->where('mediable_id', $project->id)->count())->toBe(0);
});

test('editing a file updates its category and display name only', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $media = Media::factory()->inCollection(MediaCollection::Other)->create([
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
        'original_name' => 'old-name.pdf',
        'path' => 'media/projects/'.$project->id.'/original-path.pdf',
        'type' => FileType::Pdf,
        'size' => 12345,
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'files')
        ->call('openEditFileModal', $media->id)
        ->set('fileCategory', MediaCollection::Mou->value)
        ->set('fileDisplayName', 'New name')
        ->call('saveFile')
        ->assertHasNoErrors();

    $media->refresh();

    expect($media->collection)->toBe(MediaCollection::Mou)
        ->and($media->original_name)->toBe('New name.pdf')
        ->and($media->path)->toBe('media/projects/'.$project->id.'/original-path.pdf')
        ->and($media->disk)->toBe('local')
        ->and($media->size)->toBe(12345);
});

test('editing a file with a display name that already carries the right extension does not double it', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $media = Media::factory()->create([
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
        'type' => FileType::Pdf,
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'files')
        ->call('openEditFileModal', $media->id)
        ->set('fileCategory', MediaCollection::Other->value)
        ->set('fileDisplayName', 'Already Named.pdf')
        ->call('saveFile')
        ->assertHasNoErrors();

    expect($media->refresh()->original_name)->toBe('Already Named.pdf');
});

test('deleting a file removes the media row and the file from disk', function () {
    Storage::fake('local');
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $path = 'media/projects/'.$project->id.'/doomed.pdf';
    Storage::disk('local')->put($path, 'content');
    $media = Media::factory()->create([
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
        'path' => $path,
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'files')
        ->call('confirmDeleteFile', $media->id)
        ->call('deleteFile');

    expect(Media::find($media->id))->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

test('the download route streams a file that belongs to the project in the url', function () {
    Storage::fake('local');
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $path = 'media/projects/'.$project->id.'/report.pdf';
    Storage::disk('local')->put($path, 'file contents');
    $media = Media::factory()->create([
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
        'path' => $path,
        'original_name' => 'report.pdf',
    ]);

    $this->actingAs($user)
        ->get(route('projects.files.download', [$project, $media]))
        ->assertOk()
        ->assertDownload('report.pdf');
});

test('the download route returns 404 instead of crashing when the media row has no real file on disk', function () {
    Storage::fake('local');
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $media = Media::factory()->create([
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
        'path' => 'media/never-actually-written.pdf',
    ]);

    $this->actingAs($user)
        ->get(route('projects.files.download', [$project, $media]))
        ->assertNotFound();
});

test('the download route rejects a media id belonging to a different project', function () {
    Storage::fake('local');
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $media = Media::factory()->create([
        'mediable_type' => 'project',
        'mediable_id' => $otherProject->id,
    ]);

    $this->actingAs($user)
        ->get(route('projects.files.download', [$project, $media]))
        ->assertNotFound();
});

test('editing and deleting reject a media id belonging to a different project', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $media = Media::factory()->create([
        'mediable_type' => 'project',
        'mediable_id' => $otherProject->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'files')
        ->call('openEditFileModal', $media->id)
        ->assertNotFound();

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'files')
        ->call('confirmDeleteFile', $media->id)
        ->assertNotFound();

    expect(Media::find($media->id))->not->toBeNull();
});
