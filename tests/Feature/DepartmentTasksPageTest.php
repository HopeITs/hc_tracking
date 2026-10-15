<?php

use App\Enums\Currency;
use App\Enums\DeliverableKind;
use App\Enums\DeliveryState;
use App\Enums\TaskStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-15 10:30:00');

    $this->media = insertHrDepartment('Media');
    $this->mediaEmployee = insertHrEmployee();
    insertHrContract($this->mediaEmployee, insertHrPosition($this->media));
    $this->mediaUser = userWithRole('Media Team', $this->mediaEmployee);

    $this->requester = User::factory()->create();
    $this->proposal = Proposal::factory()->create(['name' => 'Winter Response', 'requested_budget' => 987654.32, 'requested_currency' => Currency::Usd]);
});

/**
 * A media deliverable of the Media department on the shared proposal.
 *
 * @param  list<DeliverableKind>  $kinds
 * @param  array<string, mixed>  $attributes
 */
function departmentPageDeliverable(array $kinds = [DeliverableKind::Video], array $attributes = []): Task
{
    return Task::factory()
        ->forProposal(test()->proposal, test()->media)
        ->mediaDeliverable($kinds)
        ->create(['title' => 'Film the distribution', 'created_by' => test()->requester->id, ...$attributes]);
}

test('the page lists the department\'s tasks with no money and no proposal link without proposals.view', function () {
    $donor = Donor::factory()->create();
    Proposal::query()->whereKey($this->proposal->id)->update(['donor_id' => $donor->id]);
    DonorProposal::factory()->create(['proposal_id' => $this->proposal->id, 'donor_id' => $donor->id, 'budget' => 555000, 'currency' => Currency::Eur]);
    departmentPageDeliverable();

    $html = Livewire::actingAs($this->mediaUser)
        ->test('pages::department-tasks.index')
        ->assertSeeText('Film the distribution')
        ->assertSeeText('Winter Response')
        ->assertSeeHtml('data-proposal-name')
        ->assertDontSeeHtml('data-proposal-link')
        ->html();

    expect($html)->not->toContain('987,654')
        ->not->toContain('987654')
        ->not->toContain('555,000')
        ->not->toContain('555000')
        ->not->toContain('USD')
        ->not->toContain('EUR');
});

test('a proposal name links to the plan for people who can view the proposal', function () {
    departmentPageDeliverable();

    Livewire::actingAs(userWithRole('Public Relations Manager'))
        ->test('pages::department-tasks.index')
        ->assertSeeHtml('data-proposal-link');
});

test('the page is reached from the sidebar route behind tasks.view', function () {
    $this->actingAs($this->mediaUser)->get(route('department-tasks.index'))->assertOk()->assertSee(__('titles.department_tasks'));
    $this->actingAs(User::factory()->create())->get(route('department-tasks.index'))->assertForbidden();
});

test('another department\'s employee sees none of these tasks', function () {
    departmentPageDeliverable();
    $finance = insertHrDepartment('Finance');
    $financeEmployee = insertHrEmployee();
    insertHrContract($financeEmployee, insertHrPosition($finance));

    $component = Livewire::actingAs(userWithRole('Team Member', $financeEmployee))->test('pages::department-tasks.index');

    expect($component->instance()->departmentTasks)->toHaveCount(0);
    $component->assertDontSeeText('Film the distribution');
});

test('filters narrow the list and an empty result offers to clear them', function () {
    departmentPageDeliverable();
    Task::factory()->forProposal($this->proposal, $this->media)->create(['title' => 'Book the hall', 'created_by' => $this->requester->id]);

    Livewire::actingAs($this->mediaUser)
        ->test('pages::department-tasks.index')
        ->set('kind', 'media_deliverable')
        ->assertSeeText('Film the distribution')
        ->assertDontSeeText('Book the hall')
        ->set('state', DeliveryState::Accepted->value)
        ->assertSeeText(__('department_tasks.no_match'))
        ->call('clearFilters')
        ->assertSeeText('Book the hall');
});

test('a media employee delivers a video link through the page and the requester accepts it', function () {
    $task = departmentPageDeliverable();

    Livewire::actingAs($this->mediaUser)
        ->test('pages::department-tasks.index')
        ->assertSeeHtml('data-test="deliver-'.$task->id.'"')
        ->call('openDelivery', $task->id)
        ->assertSet('showDeliveryModal', true)
        ->set('deliveredLink', 'https://video.example/distribution')
        ->call('deliver')
        ->assertHasNoErrors()
        ->assertSet('showDeliveryModal', false);

    expect($task->fresh()->delivery_state)->toBe(DeliveryState::Delivered);

    Livewire::actingAs($this->requester)
        ->test('pages::department-tasks.index')
        ->call('confirmAccept', $task->id)
        ->assertSet('showAcceptModal', true)
        ->call('accept')
        ->assertSet('showAcceptModal', false);

    expect($task->fresh())
        ->delivery_state->toBe(DeliveryState::Accepted)
        ->status->toBe(TaskStatus::Done);
});

test('a delivery without the required evidence or in the future is refused on its fields', function () {
    $task = departmentPageDeliverable([DeliverableKind::Video]);

    Livewire::actingAs($this->mediaUser)
        ->test('pages::department-tasks.index')
        ->call('openDelivery', $task->id)
        ->call('deliver')
        ->assertHasErrors('deliveredLink')
        ->set('deliveredLink', 'https://video.example/a')
        ->set('deliveredOn', '2026-10-20')
        ->call('deliver')
        ->assertHasErrors('deliveredOn')
        ->assertSet('showDeliveryModal', true);

    expect($task->fresh()->delivery_state)->toBe(DeliveryState::Requested);
});

test('photos are delivered as files through the page', function () {
    $task = departmentPageDeliverable([DeliverableKind::Photos]);

    Livewire::actingAs($this->mediaUser)
        ->test('pages::department-tasks.index')
        ->call('openDelivery', $task->id)
        ->call('addDeliveryFileSlot')
        ->assertSet('deliveryFileSlots', 2)
        ->set('deliveryFiles.0', UploadedFile::fake()->image('crowd.jpg'))
        ->set('deliveryFiles.1', UploadedFile::fake()->image('stage.png'))
        ->call('deliver')
        ->assertHasNoErrors();

    expect(DB::table('task_attachments')->where('task_id', $task->id)->count())->toBe(2);
});

test('a return needs a reason and a stale view writes nothing', function () {
    $task = departmentPageDeliverable(attributes: ['delivery_state' => DeliveryState::Delivered, 'status' => TaskStatus::UnderReview, 'delivered_on' => '2026-10-14', 'delivered_link' => 'https://video.example/a']);

    $page = Livewire::actingAs($this->requester)
        ->test('pages::department-tasks.index')
        ->call('confirmReturn', $task->id)
        ->assertSet('showReturnModal', true)
        ->call('returnDelivery')
        ->assertHasErrors('returnReason');

    Task::query()->withoutGlobalScopes()->whereKey($task->id)->update(['delivery_state' => DeliveryState::Accepted->value]);

    $page->set('returnReason', 'The sound is missing.')
        ->call('returnDelivery')
        ->assertHasErrors('returnReason')
        ->assertSee(__('department_tasks.stale_delivery_state'));

    expect($task->fresh()->delivery_state)->toBe(DeliveryState::Accepted)
        ->and($task->fresh()->returned_reason)->toBeNull();
});

test('someone who may not deliver or review is refused and nothing is written', function () {
    $task = departmentPageDeliverable();
    $outsider = userWithRole('Team Member', insertHrEmployee());
    DB::table('tasks')->where('id', $task->id)->update(['created_by' => $outsider->id]);

    Livewire::actingAs($this->requester)
        ->test('pages::department-tasks.index')
        ->call('openDelivery', $task->id)
        ->set('deliveredLink', 'https://video.example/a')
        ->call('deliver')
        ->assertForbidden();

    expect($task->fresh()->delivery_state)->toBe(DeliveryState::Requested);
});

test('a task the viewer cannot see cannot be acted on through the page', function () {
    $finance = insertHrDepartment('Finance');
    $foreign = Task::factory()->forProposal($this->proposal, $finance)->mediaDeliverable()->create(['created_by' => $this->requester->id]);

    Livewire::actingAs($this->mediaUser)
        ->test('pages::department-tasks.index')
        ->call('openDelivery', $foreign->id)
        ->assertStatus(404);

    expect($foreign->fresh()->delivery_state)->toBe(DeliveryState::Requested);
});

test('the page holds a flat query count whatever the number of tasks', function () {
    departmentPageDeliverable();

    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($this->mediaUser)->test('pages::department-tasks.index');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return count($queries);
    };

    $countQueries();
    $small = $countQueries();

    foreach (range(1, 12) as $index) {
        departmentPageDeliverable(attributes: ['title' => 'Deliverable '.$index]);
    }

    expect($countQueries())->toBe($small);
});
