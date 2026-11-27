<?php

use Illuminate\Support\Facades\File;

/*
| contracts.md §3 and §4 as Y-17 leaves them: `proposal_locations.status` is
| written only by the location actions the [contract] lists, and **nothing
| under app/ writes the `projects` table at all**. This file replaces
| ProjectStatusWriteGuardTest, whose allowlist named the ten project actions
| Y-17 removed.
|
| Both checks are source scans over app/ and resources/views/, so they catch
| a write on a path no test exercises.
*/

/**
 * The §3 writers of a location row's status.
 *
 * @return list<string>
 */
function locationStatusWriters(): array
{
    return [
        'app/Actions/Locations/ApproveLocationFromProposal.php',
        'app/Actions/Locations/ActivateLocation.php',
        'app/Actions/Locations/CloseLocation.php',
        'app/Actions/Locations/ArchiveLocation.php',
        'app/Actions/Locations/RestoreLocation.php',
        'app/Actions/Locations/ReopenLocationAsDraft.php',
    ];
}

/**
 * The line numbers of every recognisable location-status write in a source.
 *
 * A row's status is a `ProjectStatus` (the enum keeps its name, §8.5), so
 * the patterns below look for the row, by variable name or by the model and
 * table it is written through.
 *
 * @return list<int>
 */
function locationStatusWrites(string $source): array
{
    $status = '[\'"]status[\'"]\s*=>';
    $patterns = [
        // $row->status = …, $locked->status = ProjectStatus::Closed
        '/\$(?:this->)?\w*(?:row|location|place(?![a-z]))\w*->status\s*=(?![=>])/i',
        '/\$locked->status\s*=\s*\x5c?(?:App\x5cEnums\x5c)?ProjectStatus::/',
        // $row->setAttribute('status', …)
        '/\$(?:this->)?\w*(?:row|location|place(?![a-z]))\w*->setAttribute\(\s*[\'"]status[\'"]/i',
        // $row->fill([... 'status' => …]) and friends
        '/\$(?:this->)?\w*(?:row|location|place(?![a-z]))\w*->(?:fill|forceFill|update|updateQuietly)\(\s*\[[^\]]*'.$status.'/i',
        // ProposalLocation::query()->…->update([... 'status' => …]), ::create([...])
        '/ProposalLocation::[^;]*?(?:create|forceCreate|update|updateOrCreate|firstOrCreate|insert|upsert)\(\s*\[[^\]]*'.$status.'/',
        // DB::table('proposal_locations')->…->update([... 'status' => …])
        '/table\(\s*[\'"]proposal_locations[\'"]\s*\)[^;]*?(?:update|insert|upsert|updateOrInsert)\(\s*\[[^\]]*'.$status.'/',
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
 * The line numbers of every recognisable write to the `projects` table.
 *
 * @return list<int>
 */
function projectTableWrites(string $source): array
{
    $patterns = [
        // Project::create([...]), Project::query()->update([...]), ->insert, ->upsert, ->delete
        '/Project::(?!factory)[^;]*?(?:create|forceCreate|updateOrCreate|firstOrCreate|insert|insertGetId|upsert)\s*\(/',
        '/Project::query\(\)[^;]*?->(?:update|updateOrInsert|delete|forceDelete|increment|decrement)\s*\(/',
        // new Project(...)
        '/new\s+\x5c?(?:App\x5cModels\x5c)?Project\s*\(/',
        // $project->save(), ->update(), ->delete(), ->fill(...)->save()
        '/\$(?:this->)?\w*project\w*->(?:save|saveQuietly|update|updateQuietly|delete|forceDelete)\s*\(/i',
        // DB::table('projects')->… writes
        '/table\(\s*[\'"]projects[\'"]\s*\)[^;]*?->(?:update|insert|insertGetId|upsert|updateOrInsert|delete|truncate|increment|decrement)\s*\(/',
        // $proposal->projects()->attach / detach / sync / updateExistingPivot
        '/->projects\(\)\s*->\s*(?:attach|detach|sync|syncWithoutDetaching|updateExistingPivot|toggle)\s*\(/',
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
function locationStatusScannedSources(): array
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

test('the scan recognises each way of writing a location status', function (string $source) {
    expect(locationStatusWrites("<?php\n".$source))->not->toBeEmpty();
})->with([
    'assignment' => '$row->status = ProjectStatus::Active;',
    'enum assignment on a locked row' => '$locked->status = ProjectStatus::Closed;',
    'setAttribute' => '$this->locationRow->setAttribute(\'status\', \'active\');',
    'fill' => '$row->fill([\'description\' => $text, \'status\' => $status])->save();',
    'forceFill' => '$place->forceFill([\'status\' => ProjectStatus::Active])->save();',
    'update' => '$row->update([\'status\' => ProjectStatus::Archived]);',
    'create' => 'ProposalLocation::query()->create([\'city_id\' => 1, \'status\' => \'active\']);',
    'mass update' => "ProposalLocation::query()->whereKey(\$ids)\n    ->update(['status' => 'active']);",
    'query builder' => "DB::table('proposal_locations')->where('id', 1)->update(['status' => 'active']);",
]);

test('the location scan ignores reads and other models', function (string $source) {
    expect(locationStatusWrites("<?php\n".$source))->toBe([]);
})->with([
    'comparison' => 'if ($row->status === ProjectStatus::Active) {}',
    'match arm' => '$tone = match ($row->status) { default => 1 };',
    'task update' => '$task->update([\'status\' => TaskStatus::Done]);',
    'proposal mass update' => "Proposal::query()->whereKey(1)->update(['status' => 'active']);",
    'filter by status' => "ProposalLocation::query()->where('status', 'active')->get();",
]);

test('only the contracts §3 writers write a location status', function () {
    $offenders = [];

    foreach (locationStatusScannedSources() as $path => $source) {
        if (in_array($path, locationStatusWriters(), true)) {
            continue;
        }

        foreach (locationStatusWrites($source) as $line) {
            $offenders[] = "{$path}:{$line}";
        }
    }

    expect($offenders)->toBe([]);
});

test('every allowlisted location status writer exists', function () {
    $sources = locationStatusScannedSources();

    foreach (locationStatusWriters() as $path) {
        expect($sources)->toHaveKey($path);
    }
});

test('the projects scan recognises each way of writing that table', function (string $source) {
    expect(projectTableWrites("<?php\n".$source))->not->toBeEmpty();
})->with([
    'create' => 'Project::query()->create([\'name\' => \'x\']);',
    'new model' => '$project = new Project([\'name\' => \'x\']);',
    'save' => '$project->save();',
    'update' => '$project->update([\'name\' => \'x\']);',
    'delete' => '$project->delete();',
    'mass update' => "Project::query()->whereKey(1)->update(['name' => 'x']);",
    'query builder' => "DB::table('projects')->where('id', 1)->update(['name' => 'x']);",
    'pivot attach' => '$proposal->projects()->attach($id, [\'target_beneficiaries\' => 10]);',
    'pivot detach' => '$locked->projects()->detach($id);',
    'pivot pivot update' => '$locked->projects()->updateExistingPivot($id, [\'target_beneficiaries\' => 1]);',
]);

test('the projects scan ignores reads', function (string $source) {
    expect(projectTableWrites("<?php\n".$source))->toBe([]);
})->with([
    'find' => 'Project::query()->find($id);',
    'exists' => '$proposal->projects()->exists();',
    'pluck' => "\$proposal->projects()->pluck('projects.id')->all();",
    'count' => '$proposal->projects()->count();',
    'read a column' => '$project->status;',
    'another table' => "DB::table('proposal_locations')->where('id', 1)->update(['status' => 'active']);",
]);

test('nothing under app writes the projects table', function () {
    // Y-17: the location row is the only thing written. `projects` is read
    // until Y-18 drops it (the morph map, the bridges, the models that still
    // carry a `project_id`), but nothing writes it any more.
    $offenders = [];

    foreach (locationStatusScannedSources() as $path => $source) {
        if (! str_starts_with($path, 'app/')) {
            continue;
        }

        foreach (projectTableWrites($source) as $line) {
            $offenders[] = "{$path}:{$line}";
        }
    }

    expect($offenders)->toBe([]);
});
