<?php

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\Project;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-16 09:00:00');
});

/**
 * @return array<string, array{key: string, value: string, label: string, delta: int|null, deltaText: string|null, good: bool|null, href: string|null}>
 */
function glanceFor(User $user): array
{
    return collect(Livewire::actingAs($user)->test('desk.panels.glance', ['size' => 'XL'])->instance()->figures)->keyBy('key')->all();
}

test('figures are computed with their trend: new running projects are good, more late tasks are bad', function () {
    Activity::withoutRecording(function (): void {
        Project::factory()->create(['status' => ProjectStatus::Active, 'start_date' => '2026-09-02']);
        Project::factory()->create(['status' => ProjectStatus::Active, 'start_date' => '2026-03-01']);
        Task::factory()->create(['due_date' => '2026-09-10', 'status' => TaskStatus::Todo, 'created_at' => '2026-08-01']);
        Task::factory()->create(['due_date' => '2026-09-12', 'status' => TaskStatus::InProgress, 'created_at' => '2026-08-01']);
    });

    $figures = glanceFor(User::factory()->role('Super Admin')->create());

    expect($figures['running'])->toMatchArray(['value' => '2', 'delta' => 1, 'good' => true])
        ->and($figures['late'])->toMatchArray(['value' => '2', 'delta' => 2, 'good' => false])
        ->and($figures['late']['href'])->toBe(route('tasks.index'));

    Livewire::actingAs(User::factory()->role('Super Admin')->create())->test('desk.panels.glance', ['size' => 'XL'])
        ->assertSeeHtml('data-trend="up"')
        ->assertDontSeeHtml('data-panel-filter');
});

test('money is shown only to viewers who may see the funding position', function () {
    Activity::withoutRecording(function (): void {
        $agreement = DonorProposal::factory()->create(['budget' => 100000, 'currency' => 'USD']);
        TransfersTracking::factory()->received()->create(['donor_proposal_id' => $agreement->id, 'payment_amount' => 25000, 'amount_received' => 25000, 'amount_received_currency' => 'USD', 'scheduled_date' => '2026-09-05']);
    });

    expect(glanceFor(User::factory()->role('Super Admin')->create()))->toHaveKey('money')
        ->and(glanceFor(userWithRole('Team Member', insertHrEmployee())))->not->toHaveKey('money');
});
