<?php

use App\Enums\Currency;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-15 09:00:00');
});

/**
 * @return array{answers: list<array{title: string, body: string}>, groups: list<array{name: string, items: list<array<string, string>>}>}
 */
function askAs(User $user, string $query): array
{
    return Livewire::actingAs($user)->test('desk.ask')->set('query', $query)->instance()->results;
}

test('"what is late?" lists every late item the viewer may see, worst first', function () {
    Task::factory()->create(['title' => 'Slightly late', 'due_date' => '2026-09-12']);
    Task::factory()->create(['title' => 'Very late', 'due_date' => '2026-09-01']);
    Task::factory()->create(['title' => 'Not late', 'due_date' => '2026-09-30']);

    $results = askAs(User::factory()->role('General Manager')->create(), 'what is late?');

    expect($results['answers'][0]['title'])->toBe('2 things are late')
        ->and(array_column($results['groups'][0]['items'], 'label'))->toBe(['Very late', 'Slightly late']);
});

test('"money" answers per currency only for people who see funding agreements and transfers', function () {
    DonorProposal::factory()->create(['budget' => 1000, 'currency' => Currency::Usd]);

    expect(askAs(User::factory()->role('Financial')->create(), 'money')['answers'][0]['title'])->toBe('0 USD received of 1,000')
        ->and(askAs(User::factory()->role('Team Member')->create(), 'money')['answers'])->toBe([]);
});

test('names find projects and donors the viewer may open, and never the others', function () {
    Project::factory()->create(['name' => 'Aleppo Wells']);
    Donor::factory()->create(['name' => 'Aleppo Foundation']);

    $labels = fn (User $user): array => collect(askAs($user, 'aleppo')['groups'])->flatMap(fn (array $group) => array_column($group['items'], 'label'))->all();

    expect($labels(User::factory()->role('Financial')->create()))->toContain('Aleppo Wells', 'Aleppo Foundation')
        ->and($labels(User::factory()->role('Team Member')->create()))->toContain('Aleppo Wells')->not->toContain('Aleppo Foundation');
});

test('"Go to" offers only the pages the viewer may open', function () {
    $goTo = fn (User $user, string $query): array => array_column(collect(askAs($user, $query)['groups'])->firstWhere('name', __('desk.ask_group_go'))['items'] ?? [], 'href');

    expect($goTo(User::factory()->role('Financial')->create(), 'transfers'))->toContain(route('transfers.index'))
        ->and($goTo(User::factory()->role('Team Member')->create(), 'transfers'))->toBe([]);
});

test('a create word opens the matching register form, only with the create permission', function () {
    $actions = fn (User $user, string $query): array => array_values(array_filter(
        array_merge(...array_column(askAs($user, $query)['groups'], 'items')),
        fn (array $item): bool => $item['action'] ?? false,
    ));

    expect($actions(User::factory()->role('Financial')->create(), 'new transfer'))->toHaveCount(1)
        ->and($actions(User::factory()->role('Financial')->create(), 'new transfer')[0]['href'])->toBe(route('transfers.index', ['quick' => 'create']))
        ->and($actions(User::factory()->role('Team Member')->create(), 'new transfer'))->toBe([]);
});

test('typos and Arabic still find the page and the action', function () {
    $user = User::factory()->role('Super Admin')->create();
    $labels = fn (string $query): array => array_column(array_merge(...array_column(askAs($user, $query)['groups'], 'items')), 'label');

    expect($labels('propsal'))->toContain(__('desk.ask_new.proposal'), __('common.proposals'))
        ->and($labels('مقترح جديد'))->toContain(__('desk.ask_new.proposal'));
});

test('"my tasks this week" lists only my open tasks due within seven days', function () {
    $employeeId = insertHrEmployee();
    $user = User::factory()->role('Team Member')->create(['employee_id' => $employeeId]);
    $mine = Task::factory()->create(['title' => 'Mine soon', 'due_date' => '2026-09-18']);
    $mine->taskAssignees()->create(['employee_id' => $employeeId]);
    $later = Task::factory()->create(['title' => 'Mine later', 'due_date' => '2026-10-30']);
    $later->taskAssignees()->create(['employee_id' => $employeeId]);
    Task::factory()->create(['title' => 'Not mine', 'due_date' => '2026-09-17']);

    $results = askAs($user, 'my tasks this week');

    expect(array_column($results['groups'][0]['items'], 'label'))->toBe(['Mine soon']);
});

test('"how many projects" counts only what the viewer may see', function () {
    Project::factory()->count(3)->create();

    expect(askAs(User::factory()->role('Super Admin')->create(), 'how many projects')['answers'][0]['title'])->toBe('3 projects')
        ->and(askAs(User::factory()->create(), 'how many projects')['answers'][0]['title'])->toBe('0 projects');
});

test('an empty box offers example questions', function () {
    expect(askAs(User::factory()->role('Team Member')->create(), '')['suggestions'])->toContain(__('desk.ask_suggest.late'));
});
