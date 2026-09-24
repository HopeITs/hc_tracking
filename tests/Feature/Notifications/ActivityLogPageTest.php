<?php

use App\Models\Activity;
use App\Models\Risk;
use App\Models\Task;
use Livewire\Livewire;

test('only super admins and the general manager can open the activity log', function (string $role, int $status) {
    $this->actingAs(userWithRole($role))->get(route('activity.index'))->assertStatus($status);
})->with([
    ['Super Admin', 200],
    ['General Manager', 200],
    ['Managers', 403],
    ['MEAL', 403],
]);

test('the log filters by module, person, text and date range', function () {
    $sami = userWithRole('Managers');
    $rana = userWithRole('Managers');

    $this->actingAs($sami);
    Task::factory()->create(['title' => 'Water trucking']);
    $this->travel(2)->days();
    $this->actingAs($rana);
    Risk::factory()->create(['name' => 'Road closure']);

    $page = fn () => Livewire::actingAs(userWithRole('Super Admin'))->test('pages::activity.index');

    $page()->set('module', 'risks')->assertSeeText('Road closure')->assertDontSeeText('Water trucking');
    $page()->set('actor', (string) $sami->id)->assertSeeText('Water trucking')->assertDontSeeText('Road closure');
    $page()->set('search', 'trucking')->assertSeeText('Water trucking')->assertDontSeeText('Road closure');
    $page()->set('from', now()->toDateString())->assertSeeText('Road closure')->assertDontSeeText('Water trucking');
});

test('a deleted record shows as deleted instead of a link', function () {
    $task = Activity::withoutRecording(fn () => Task::factory()->create(['title' => 'Gone']));
    $this->actingAs(userWithRole('Managers'));
    $task->delete();

    Livewire::actingAs(userWithRole('Super Admin'))->test('pages::activity.index')
        ->assertSeeText(__('activity.record_gone'))
        ->assertDontSeeHtml(route('tasks.show', $task->id));
});

test('the sidebar links the log only for those who may open it', function () {
    $this->actingAs(userWithRole('General Manager'))->get(route('dashboard'))->assertSee(route('activity.index'));
    $this->actingAs(userWithRole('Managers'))->get(route('dashboard'))->assertDontSee(route('activity.index'));
});
