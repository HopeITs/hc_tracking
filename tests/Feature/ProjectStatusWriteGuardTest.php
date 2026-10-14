<?php

use Illuminate\Support\Facades\File;

/*
| contracts.md §4: `projects.status` is written only by the status writers
| below (and the T05 data migration, which lives outside the scanned
| folders). T08 adds ApproveProjectFromProposal and ActivateProject when it
| implements them; they are listed already so their stubs are covered. This
| test reads app/ and resources/views/ and fails on any other write it can
| recognise.
*/

/**
 * The §4 writers.
 *
 * @return list<string>
 */
function projectStatusWriters(): array
{
    return [
        'app/Actions/Projects/SaveProject.php',
        'app/Actions/Projects/ApproveProjectFromProposal.php',
        'app/Actions/Projects/ActivateProject.php',
        'app/Actions/Projects/CloseProject.php',
        'app/Actions/Projects/ReopenProjectAsDraft.php',
        'app/Actions/Projects/ArchiveProject.php',
        'app/Actions/Projects/RestoreProject.php',
    ];
}

/**
 * The line numbers of every recognisable project status write in a source.
 *
 * @return list<int>
 */
function projectStatusWrites(string $source): array
{
    $status = '[\'"]status[\'"]\s*=>';
    $patterns = [
        // $project->status = …, $locked->status = ProjectStatus::Closed
        '/\$(?:this->)?\w*project\w*->status\s*=(?![=>])/i',
        '/->status\s*=\s*\x5c?(?:App\x5cEnums\x5c)?ProjectStatus::/',
        // $project->setAttribute('status', …)
        '/\$(?:this->)?\w*project\w*->setAttribute\(\s*[\'"]status[\'"]/i',
        // $project->fill([... 'status' => …]) and friends
        '/\$(?:this->)?\w*project\w*->(?:fill|forceFill|update|updateQuietly)\(\s*\[[^\]]*'.$status.'/i',
        // Project::query()->…->update([... 'status' => …]), Project::create([...])
        '/Project::[^;]*?(?:create|forceCreate|update|updateOrCreate|firstOrCreate|insert|upsert)\(\s*\[[^\]]*'.$status.'/',
        // DB::table('projects')->…->update([... 'status' => …])
        '/table\(\s*[\'"]projects[\'"]\s*\)[^;]*?(?:update|insert|upsert|updateOrInsert)\(\s*\[[^\]]*'.$status.'/',
    ];

    $lines = [];

    foreach ($patterns as $pattern) {
        preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [, $offset]) {
            $lines[] = substr_count(substr($source, 0, $offset), "\n") + 1;
        }
    }

    sort($lines);

    return array_values(array_unique($lines));
}

/**
 * Every scanned source, by path relative to the project root.
 *
 * @return array<string, string>
 */
function projectStatusScannedSources(): array
{
    $sources = [];

    foreach ([app_path(), resource_path('views')] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            $sources[$path] = $file->getContents();
        }
    }

    return $sources;
}

test('the scan recognises each way of writing a project status', function (string $source) {
    expect(projectStatusWrites("<?php\n".$source))->not->toBeEmpty();
})->with([
    'assignment' => '$project->status = ProjectStatus::Active;',
    'enum assignment on a locked row' => '$locked->status = ProjectStatus::Closed;',
    'setAttribute' => '$this->project->setAttribute(\'status\', \'active\');',
    'fill' => '$project->fill([\'name\' => $name, \'status\' => $status])->save();',
    'forceFill' => '$project->forceFill([\'status\' => ProjectStatus::Active])->save();',
    'update' => '$openProject->update([\'status\' => ProjectStatus::Archived]);',
    'create' => 'Project::query()->create([\'name\' => \'x\', \'status\' => \'active\']);',
    'mass update' => "Project::query()->whereKey(\$ids)\n    ->update(['status' => 'active']);",
    'query builder' => "DB::table('projects')->where('id', 1)->update(['status' => 'active']);",
]);

test('the scan ignores reads and other models', function (string $source) {
    expect(projectStatusWrites("<?php\n".$source))->toBe([]);
})->with([
    'comparison' => 'if ($project->status === ProjectStatus::Active) {}',
    'match arm' => '$tone = match ($project->status) { default => 1 };',
    'task update' => '$task->update([\'status\' => TaskStatus::Done]);',
    'proposal mass update' => "Proposal::query()->whereKey(1)->update(['status' => 'active']);",
    'filter by status' => "Project::query()->where('status', 'active')->get();",
]);

test('only the contracts §4 writers write a project status', function () {
    $offenders = [];

    foreach (projectStatusScannedSources() as $path => $source) {
        if (in_array($path, projectStatusWriters(), true)) {
            continue;
        }

        foreach (projectStatusWrites($source) as $line) {
            $offenders[] = "{$path}:{$line}";
        }
    }

    expect($offenders)->toBe([]);
});

test('every allowlisted project status writer exists', function () {
    $sources = projectStatusScannedSources();

    foreach (projectStatusWriters() as $path) {
        expect($sources)->toHaveKey($path);
    }
});
