<?php

use Illuminate\Support\Facades\File;

/*
| contracts.md §4: `proposals.status` is written only by the status writers
| below (and the T04 data migration, which lives outside the scanned
| folders). Every other change goes through one of them, so each move is
| checked against the transition table, audited and announced. This test
| reads app/ and resources/views/ and fails on any other write it can
| recognise.
*/

/**
 * The §4 writers.
 *
 * @return list<string>
 */
function proposalStatusWriters(): array
{
    return [
        'app/Actions/Proposals/TransitionProposal.php',
        'app/Actions/Proposals/CorrectProposalStatus.php',
        'app/Actions/Proposals/ApproveProposal.php',
        'app/Actions/Projects/ActivateProject.php',
    ];
}

/**
 * The line numbers of every recognisable proposal status write in a source.
 *
 * @return list<int>
 */
function proposalStatusWrites(string $source): array
{
    $status = '[\'"]status[\'"]\s*=>';
    $patterns = [
        // $proposal->status = …
        '/\$(?:this->)?\w*proposal\w*->status\s*=(?![=>])/i',
        // $proposal->setAttribute('status', …)
        '/\$(?:this->)?\w*proposal\w*->setAttribute\(\s*[\'"]status[\'"]/i',
        // $proposal->fill([... 'status' => …]) and friends
        '/\$(?:this->)?\w*proposal\w*->(?:fill|forceFill|update|updateQuietly)\(\s*\[[^\]]*'.$status.'/i',
        // Proposal::query()->…->update([... 'status' => …]), Proposal::create([...])
        '/Proposal::[^;]*?(?:create|forceCreate|update|updateOrCreate|firstOrCreate|insert|upsert)\(\s*\[[^\]]*'.$status.'/',
        // DB::table('proposals')->…->update([... 'status' => …])
        '/table\(\s*[\'"]proposals[\'"]\s*\)[^;]*?(?:update|insert|upsert|updateOrInsert)\(\s*\[[^\]]*'.$status.'/',
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
function proposalStatusScannedSources(): array
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

test('the scan recognises each way of writing a proposal status', function (string $source) {
    expect(proposalStatusWrites("<?php\n".$source))->not->toBeEmpty();
})->with([
    'assignment' => '$proposal->status = ProposalStatus::Approved;',
    'setAttribute' => '$this->proposal->setAttribute(\'status\', \'approved\');',
    'fill' => '$proposal->fill([\'name\' => $name, \'status\' => $status])->save();',
    'forceFill' => '$proposal->forceFill([\'status\' => ProposalStatus::Active])->save();',
    'update' => '$openProposal->update([\'status\' => ProposalStatus::Rejected]);',
    'create' => 'Proposal::query()->create([\'name\' => \'x\', \'status\' => \'approved\']);',
    'mass update' => "Proposal::query()->whereKey(\$ids)\n    ->update(['status' => 'active']);",
    'query builder' => "DB::table('proposals')->where('id', 1)->update(['status' => 'active']);",
]);

test('the scan ignores reads and other models', function (string $source) {
    expect(proposalStatusWrites("<?php\n".$source))->toBe([]);
})->with([
    'comparison' => 'if ($proposal->status === ProposalStatus::Approved) {}',
    'match arm' => '$tone = match ($proposal->status) { default => 1 };',
    'task update' => '$task->update([\'status\' => TaskStatus::Done]);',
    'project mass update' => "Project::query()->whereKey(1)->update(['status' => 'active']);",
    'filter by status' => "Proposal::query()->where('status', 'active')->get();",
]);

test('only the contracts §4 writers write a proposal status', function () {
    $allowed = proposalStatusWriters();
    $offenders = [];

    foreach (proposalStatusScannedSources() as $path => $source) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

        foreach (proposalStatusWrites($source) as $line) {
            $offenders[] = "{$path}:{$line}";
        }
    }

    expect($offenders)->toBe([]);
});

test('every allowlisted writer exists', function () {
    $sources = proposalStatusScannedSources();

    foreach (proposalStatusWriters() as $path) {
        expect($sources)->toHaveKey($path);
    }
});
