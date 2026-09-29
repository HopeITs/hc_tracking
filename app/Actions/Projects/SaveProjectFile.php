<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Media;
use App\Models\Project;
use App\Support\StoredFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Add a file to a project, or change an existing file's category and name.
 */
class SaveProjectFile
{
    /**
     * @param  array<string, mixed>  $validated  keyed by the file form's property names
     */
    public function handle(Project $project, ?Media $existing, ?UploadedFile $upload, array $validated): Media
    {
        Gate::authorize('update', $project);

        $file = $existing ?? new Media(['mediable_type' => 'project', 'mediable_id' => $project->id, 'disk' => 'local']);

        StoredFiles::save(
            $file,
            $upload,
            'media/projects/'.$project->id,
            (string) ($validated['fileDisplayName'] ?? ''),
            ['collection' => $validated['fileCategory']],
            ['uploaded_at' => now()],
            'project file',
        );

        return $file;
    }
}
