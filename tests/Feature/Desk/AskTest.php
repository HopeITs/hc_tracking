<?php

use App\Enums\Currency;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
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

/**
 * The proposal part of each Ask result label: a place is listed as
 * "<proposal> · <place>" (Y-16, D-93), and these tests are about the name
 * the search matched.
 *
 * @param  array<int, array{label: string}>  $items
 * @return list<string>
 */
function askNames(array $items): array
{
    return array_map(fn (array $item): string => trim(explode(' · ', $item['label'])[0]), $items);
}

test('"money" answers per currency only for people who see funding agreements and transfers', function () {
    DonorProposal::factory()->create(['budget' => 1000, 'currency' => Currency::Usd]);

    expect(askAs(User::factory()->role('Financial')->create(), 'money')['answers'][0]['title'])->toBe('0 USD received of 1,000')
        ->and(askAs(User::factory()->role('Team Member')->create(), 'money')['answers'])->toBe([]);
});

test('names find projects and donors the viewer may open, and never the others', function () {
    rowFor(Project::factory()->create(['name' => 'Aleppo Wells']), Proposal::factory()->running()->create(['name' => 'Aleppo Wells']));
    Donor::factory()->create(['name' => 'Aleppo Foundation']);

    $labels = fn (User $user): array => collect(askAs($user, 'aleppo')['groups'])->flatMap(fn (array $group) => askNames($group['items']))->all();

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
    Project::factory()->count(3)->create()->each(fn (Project $project) => rowFor($project));

    expect(askAs(User::factory()->role('Super Admin')->create(), 'how many projects')['answers'][0]['title'])->toBe('3 projects')
        ->and(askAs(User::factory()->create(), 'how many projects')['answers'][0]['title'])->toBe('0 projects');
});

test('an empty box offers example questions', function () {
    expect(askAs(User::factory()->role('Team Member')->create(), '')['suggestions'])->toContain(__('desk.ask_suggest.late'));
});

test('names find programs, which open their own page', function () {
    $program = Program::factory()->create(['name' => 'Aleppo Relief']);

    $group = collect(askAs(User::factory()->role('General Manager')->create(), 'aleppo relief')['groups'])->firstWhere('name', 'Programs');

    expect(array_column($group['items'], 'label'))->toBe(['Aleppo Relief'])
        ->and($group['items'][0]['href'])->toBe(route('programs.show', $program));
});

test('English typed on the Arabic keyboard layout finds what it meant, and says so', function () {
    $user = User::factory()->role('Super Admin')->create();

    $results = askAs($user, 'حقخلقشةس');

    expect($results['corrected'])->toBe('programs')
        ->and(collect($results['groups'])->firstWhere('name', __('desk.ask_group_go'))['items'])->not->toBeEmpty();
});

test('Arabic typed on the English keyboard layout finds what it meant', function () {
    rowFor(Project::factory()->create(['name' => 'حلب']), Proposal::factory()->running()->create(['name' => 'حلب']));

    $results = askAs(User::factory()->role('Super Admin')->create(), 'pgf');

    expect($results['corrected'])->toBe('حلب')
        ->and(askNames(collect($results['groups'])->firstWhere('name', __('common.projects'))['items']))->toBe(['حلب']);
});

test('a query that already finds something is never swapped to the other layout', function () {
    rowFor(Project::factory()->create(['name' => 'Aleppo Wells']), Proposal::factory()->running()->create(['name' => 'Aleppo Wells']));

    expect(askAs(User::factory()->role('Super Admin')->create(), 'aleppo')['corrected'])->toBeNull();
});

test('mixed text typed partly on the wrong layout is read word by word', function () {
    rowFor(Project::factory()->create(['name' => 'Aleppo Wells']), Proposal::factory()->running()->create(['name' => 'Aleppo Wells']));

    $results = askAs(User::factory()->role('Super Admin')->create(), 'شمثححخ wells');

    expect($results['corrected'])->toBe('aleppo wells')
        ->and(askNames(collect($results['groups'])->firstWhere('name', __('common.projects'))['items']))->toBe(['Aleppo Wells']);
});

test('the other layout wins when the typed text only finds weaker matches', function () {
    Task::factory()->create(['title' => 'حقخلقشةس draft']);

    expect(askAs(User::factory()->role('Super Admin')->create(), 'حقخلقشةس')['corrected'])->toBe('programs');
});

test('Arabic names are found whatever form of alef, taa marbuta or yaa is typed', function (string $typed) {
    rowFor(Project::factory()->create(['name' => 'مدرسة الإغاثة في مستشفى']), Proposal::factory()->running()->create(['name' => 'مدرسة الإغاثة في مستشفى']));

    $labels = askNames(collect(askAs(User::factory()->role('Super Admin')->create(), $typed)['groups'])->firstWhere('name', __('common.projects'))['items'] ?? []);

    expect($labels)->toBe(['مدرسة الإغاثة في مستشفى']);
})->with(['مدرسة', 'مدرسه', 'اغاثه', 'إغاثة', 'مستشفي']);

test('a word one typo from a module name is also searched as a name', function () {
    rowFor(Project::factory()->create(['name' => 'آبار حلب']), Proposal::factory()->running()->create(['name' => 'آبار حلب']));

    $results = askAs(User::factory()->role('Super Admin')->create(), 'ابار');

    expect(askNames(collect($results['groups'])->firstWhere('name', __('common.projects'))['items'] ?? []))->toBe(['آبار حلب']);
});

test('names stored with tashkeel are found without it', function () {
    rowFor(Project::factory()->create(['name' => 'مَدْرَسَة الأمل']), Proposal::factory()->running()->create(['name' => 'مَدْرَسَة الأمل']));

    $results = askAs(User::factory()->role('Super Admin')->create(), 'مدرسة');

    expect(askNames(collect($results['groups'])->firstWhere('name', __('common.projects'))['items'] ?? []))->toBe(['مَدْرَسَة الأمل']);
});

test('a correct Arabic search that finds a record is never swapped for a page match', function () {
    rowFor(Project::factory()->create(['name' => 'حلب']), Proposal::factory()->running()->create(['name' => 'حلب']));

    expect(askAs(User::factory()->role('Super Admin')->create(), 'حلب')['corrected'])->toBeNull();
});

test('server pages, actions and "nothing found" show only for the text they answered', function () {
    Livewire::actingAs(User::factory()->role('Financial')->create())
        ->test('desk.ask')
        ->set('query', 'transfers')
        ->assertSeeHtml("x-show=\"instant.length === 0 && typed.trim() === 'transfers'\"")
        ->assertSeeHtml('wire:loading.delay.longer');
});
