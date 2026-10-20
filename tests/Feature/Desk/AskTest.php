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
