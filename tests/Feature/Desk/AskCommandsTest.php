<?php

use App\Models\User;
use App\Support\Desk\AskCommands;

test('the instant index holds only the pages and actions the viewer may use', function () {
    $index = fn (User $user): array => app(AskCommands::class)->for($user);
    $routes = fn (array $index): array => array_column($index['pages'], 'route');
    $actions = fn (array $index): array => array_column($index['actions'], 'item');

    $financial = $index(User::factory()->role('Financial')->create());
    $mediaTeam = $index(User::factory()->role('Media Team')->create());
    $teamMember = $index(User::factory()->role('Team Member')->create());

    expect($routes($financial))->toContain('transfers.index', 'admin.accounts.index')
        ->and($actions($financial))->toContain('a-transfer')
        ->and($routes($mediaTeam))->not->toContain('transfers.index', 'admin.accounts.index')
        ->and($actions($mediaTeam))->not->toContain('a-transfer')
        ->and($actions($mediaTeam))->toContain('a-task')
        ->and($actions($teamMember))->not->toContain('a-task');
});

test('each page carries what stands for it on the desk, and whether it is there', function () {
    $user = User::factory()->role('MEAL')->create(['desk_layout' => [['panel' => 'p-admin-evidence-types', 'size' => 'L']]]);
    $index = app(AskCommands::class)->for($user);
    $evidence = collect($index['pages'])->firstWhere('route', 'admin.evidence-types.index');

    expect($evidence['item'])->toBe('p-admin-evidence-types')
        ->and($evidence['kind'])->toBe('card')
        ->and($evidence['href'])->toBe(route('admin.evidence-types.index'))
        ->and($index['placed'])->toBe(['p-admin-evidence-types']);
});

test('the index reads both languages, normalised as the server reads them', function () {
    $index = app(AskCommands::class)->for(User::factory()->role('Super Admin')->create());
    $projects = collect($index['pages'])->firstWhere('route', 'projects.index');
    $newTask = collect($index['actions'])->firstWhere('key', 'task');

    expect(explode(' ', $projects['words']))->toContain('projects', 'مشروع', 'مشاريع')
        ->and(explode(' ', $newTask['words']))->toContain('task', 'مهمه', 'new', 'جديد')
        ->and($index['verbs']['desk'])->toContain('desk', 'مكتب')
        ->and($index['verbs']['add'])->toContain('pin', 'اضف')
        ->and($index['verbs']['create'])->toContain('new', 'جديد');
});
