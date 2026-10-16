<?php

use App\Actions\Tasks\AcceptDepartmentTask;
use App\Actions\Tasks\DeliverDepartmentTask;
use App\Actions\Tasks\ReturnDepartmentTask;
use App\Enums\DeliverableKind;
use App\Enums\DeliveryState;
use App\Enums\FileType;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Support\StoredFiles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-15 10:30:00');
});

/**
 * A media deliverable of the Media department asking for the given kinds,
 * assigned to a Media Team employee (who may deliver it) and raised by a
 * plain user (who may review it as its requester).
 *
 * @param  list<DeliverableKind>  $kinds
 * @param  array<string, mixed>  $attributes
 * @return array{task: Task, deliverer: User, reviewer: User}
 */
function deliveryTestDeliverable(array $kinds, array $attributes = []): array
{
    $media = insertHrDepartment('Media');
    $employeeId = insertHrEmployee();
    insertHrContract($employeeId, insertHrPosition($media));
    $deliverer = userWithRole('Media Team', $employeeId);
    $reviewer = User::factory()->create();
    $task = Task::factory()
        ->forProposal(Proposal::factory()->create(), $media)
        ->mediaDeliverable($kinds)
        ->create(['created_by' => $reviewer->id, ...$attributes]);
    $task->taskAssignees()->create(['employee_id' => $employeeId]);

    return ['task' => $task, 'deliverer' => $deliverer, 'reviewer' => $reviewer];
}

/**
 * The attributes of a deliverable already handed over and waiting for review.
 *
 * @return array<string, mixed>
 */
function deliveryTestDelivered(): array
{
    return [
        'delivery_state' => DeliveryState::Delivered,
        'status' => TaskStatus::UnderReview,
        'delivered_on' => '2026-10-14',
        'delivered_text' => 'Caption for the distribution story.',
    ];
}

/**
 * Everything a delivery move can write, to prove a refused call wrote nothing.
 *
 * @return array{task: array<string, mixed>, attachments: int, activities: int, files: list<string>}
 */
function deliveryTestSnapshot(Task $task): array
{
    return [
        'task' => (array) DB::table('tasks')->find($task->id),
        'attachments' => DB::table('task_attachments')->where('task_id', $task->id)->count(),
        'activities' => DB::table('activities')->where('subject_type', 'task')->where('subject_id', $task->id)->count(),
        'files' => Storage::disk('local')->allFiles(),
    ];
}

/**
 * The validation errors a call raises, by field; none when it passes.
 *
 * @return array<string, list<string>>
 */
function deliveryTestErrors(callable $call): array
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

test('a delivery without the evidence a kind needs is refused with that kind\'s message', function (DeliverableKind $kind, string $field, string $message) {
    ['task' => $task, 'deliverer' => $deliverer] = deliveryTestDeliverable([$kind]);
    $before = deliveryTestSnapshot($task);
    $this->actingAs($deliverer);

    $errors = deliveryTestErrors(fn () => app(DeliverDepartmentTask::class)->handle($task, DeliveryState::Requested, ['delivered_on' => '2026-10-15']));

    expect($errors[$field] ?? [])->toContain(__($message));
    expect(deliveryTestSnapshot($task))->toBe($before);
})->with([
    'video' => [DeliverableKind::Video, 'delivered_link', 'department_tasks.video_link_required'],
    'photos' => [DeliverableKind::Photos, 'files', 'department_tasks.photos_required'],
    'file' => [DeliverableKind::File, 'files', 'department_tasks.file_required'],
    'text' => [DeliverableKind::Text, 'delivered_text', 'department_tasks.text_required'],
]);

test('a delivery dated in the future is refused', function () {
    ['task' => $task, 'deliverer' => $deliverer] = deliveryTestDeliverable([DeliverableKind::Text]);
    $before = deliveryTestSnapshot($task);
    $this->actingAs($deliverer);

    $errors = deliveryTestErrors(fn () => app(DeliverDepartmentTask::class)->handle($task, DeliveryState::Requested, [
        'delivered_on' => '2026-10-16',
        'delivered_text' => 'Caption for the distribution story.',
    ]));

    expect($errors['delivered_on'] ?? [])->toBe([__('department_tasks.delivered_on_future')]);
    expect(deliveryTestSnapshot($task))->toBe($before);
});

test('a video handed over as a file alone is refused', function () {
    ['task' => $task, 'deliverer' => $deliverer] = deliveryTestDeliverable([DeliverableKind::Video]);
    $before = deliveryTestSnapshot($task);
    $this->actingAs($deliverer);

    $errors = deliveryTestErrors(fn () => app(DeliverDepartmentTask::class)->handle($task, DeliveryState::Requested, ['delivered_on' => '2026-10-15'], [validUpload('clip.jpg')]));

    expect($errors['delivered_link'] ?? [])->toBe([__('department_tasks.video_link_required')])
        ->and($errors['files'] ?? [])->toBe([__('department_tasks.files_not_accepted')]);
    expect(deliveryTestSnapshot($task))->toBe($before);
});

test('a video is delivered as a link alone', function () {
    ['task' => $task, 'deliverer' => $deliverer] = deliveryTestDeliverable([DeliverableKind::Video]);
    $this->actingAs($deliverer);

    $delivered = app(DeliverDepartmentTask::class)->handle($task, DeliveryState::Requested, [
        'delivered_on' => '2026-10-15',
        'delivered_link' => ' https://videos.example.org/distribution ',
    ]);

    expect($delivered->delivery_state)->toBe(DeliveryState::Delivered)
        ->and($delivered->delivered_link)->toBe('https://videos.example.org/distribution');
    $this->assertDatabaseCount('task_attachments', 0);
});

test('photos are delivered as a jpg or png file', function (string $name, FileType $type) {
    ['task' => $task, 'deliverer' => $deliverer] = deliveryTestDeliverable([DeliverableKind::Photos]);
    $this->actingAs($deliverer);

    app(DeliverDepartmentTask::class)->handle($task, DeliveryState::Requested, ['delivered_on' => '2026-10-15'], [validUpload($name)]);

    expect(TaskAttachment::query()->where('task_id', $task->id)->sole()->type)->toBe($type);
})->with([
    'jpg' => ['site.jpg', FileType::Jpg],
    'png' => ['site.png', FileType::Png],
]);

test('photos handed over as a pdf are refused', function () {
    ['task' => $task, 'deliverer' => $deliverer] = deliveryTestDeliverable([DeliverableKind::Photos]);
    $before = deliveryTestSnapshot($task);
    $this->actingAs($deliverer);

    $errors = deliveryTestErrors(fn () => app(DeliverDepartmentTask::class)->handle($task, DeliveryState::Requested, ['delivered_on' => '2026-10-15'], [validUpload('site.pdf')]));

    expect($errors)->toHaveKey('files.0')
        ->and($errors['files'] ?? [])->toBe([__('department_tasks.photos_required')]);
    expect(deliveryTestSnapshot($task))->toBe($before);
});

test('a file over 5 MB is refused', function () {
    ['task' => $task, 'deliverer' => $deliverer] = deliveryTestDeliverable([DeliverableKind::File]);
    $before = deliveryTestSnapshot($task);
    $this->actingAs($deliverer);

    $errors = deliveryTestErrors(fn () => app(DeliverDepartmentTask::class)->handle($task, DeliveryState::Requested, ['delivered_on' => '2026-10-15'], [validUpload('report.pdf', 5121)]));

    expect($errors)->toHaveKey('files.0');
    expect(deliveryTestSnapshot($task))->toBe($before);
});

test('a valid delivery stores its files encrypted and moves the task to review', function () {
    useFileKey(newFileKey(), 'k7');
    ['task' => $task, 'deliverer' => $deliverer] = deliveryTestDeliverable([DeliverableKind::Photos, DeliverableKind::Text]);
    $photo = validUpload('site.jpg');
    $original = (string) file_get_contents($photo->getRealPath());
    $this->actingAs($deliverer);

    $delivered = app(DeliverDepartmentTask::class)->handle($task, DeliveryState::Requested, [
        'delivered_on' => '2026-10-14',
        'delivered_text' => 'Caption for the distribution story.',
    ], [$photo]);

    $attachment = TaskAttachment::query()->where('task_id', $task->id)->sole();
    expect($delivered->delivery_state)->toBe(DeliveryState::Delivered)
        ->and($delivered->status)->toBe(TaskStatus::UnderReview);
    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'delivery_state' => 'delivered',
        'status' => 'under review',
        'delivered_on' => '2026-10-14',
        'delivered_text' => 'Caption for the distribution story.',
        'delivered_link' => null,
    ]);
    expect($attachment->encryption_key_id)->toBe('k7')
        ->and($attachment->original_name)->toBe('site.jpg')
        ->and($attachment->date->toDateString())->toBe('2026-10-14')
        ->and($attachment->uploaded_by)->toBe($deliverer->id)
        ->and(Storage::disk('local')->get($attachment->path))->not->toBe($original)
        ->and(StoredFiles::contents($attachment->disk, $attachment->path, $attachment->encryption_key_id))->toBe($original);
});

test('a failed delivery leaves no stored file behind', function () {
    ['task' => $task, 'deliverer' => $deliverer] = deliveryTestDeliverable([DeliverableKind::Photos]);
    $before = deliveryTestSnapshot($task);
    Task::updating(fn () => throw new RuntimeException('The database went away.'));
    $this->actingAs($deliverer);

    $call = fn () => app(DeliverDepartmentTask::class)->handle($task, DeliveryState::Requested, ['delivered_on' => '2026-10-15'], [validUpload('site.jpg')]);

    expect($call)->toThrow(RuntimeException::class, 'The database went away.');
    expect(deliveryTestSnapshot($task))->toBe($before);
});

test('delivering twice from the same requested state writes nothing the second time', function () {
    ['task' => $task, 'deliverer' => $deliverer] = deliveryTestDeliverable([DeliverableKind::Photos]);
    $this->actingAs($deliverer);
    app(DeliverDepartmentTask::class)->handle($task, DeliveryState::Requested, ['delivered_on' => '2026-10-15'], [validUpload('site.jpg')]);
    $afterFirst = deliveryTestSnapshot($task);

    $errors = deliveryTestErrors(fn () => app(DeliverDepartmentTask::class)->handle($task, DeliveryState::Requested, ['delivered_on' => '2026-10-15'], [validUpload('again.jpg')]));

    expect($errors['delivery_state'] ?? [])->toBe([__('department_tasks.stale_delivery_state')]);
    expect(deliveryTestSnapshot($task))->toBe($afterFirst);
});

test('a deliverable already handed over cannot be delivered again before it is returned', function (DeliveryState $state) {
    ['task' => $task, 'deliverer' => $deliverer] = deliveryTestDeliverable([DeliverableKind::Text], [...deliveryTestDelivered(), 'delivery_state' => $state]);
    $before = deliveryTestSnapshot($task);
    $this->actingAs($deliverer);

    $errors = deliveryTestErrors(fn () => app(DeliverDepartmentTask::class)->handle($task, $state, [
        'delivered_on' => '2026-10-15',
        'delivered_text' => 'A second caption.',
    ]));

    expect($errors['delivery_state'] ?? [])->toBe([__('department_tasks.already_delivered')]);
    expect(deliveryTestSnapshot($task))->toBe($before);
})->with([
    'delivered' => DeliveryState::Delivered,
    'accepted' => DeliveryState::Accepted,
]);

test('a task that is not a media deliverable cannot be delivered', function () {
    ['task' => $media, 'deliverer' => $deliverer] = deliveryTestDeliverable([DeliverableKind::Text]);
    $general = Task::factory()->forProposal(Proposal::factory()->create(), $media->department_id)->create(['created_by' => $deliverer->id]);
    $before = deliveryTestSnapshot($general);
    $this->actingAs($deliverer);

    $errors = deliveryTestErrors(fn () => app(DeliverDepartmentTask::class)->handle($general, DeliveryState::Requested, [
        'delivered_on' => '2026-10-15',
        'delivered_text' => 'Caption for the distribution story.',
    ]));

    expect($errors['kind'] ?? [])->toBe([__('department_tasks.only_media_deliverables_are_delivered')]);
    expect(deliveryTestSnapshot($general))->toBe($before);
});

test('returning a delivered deliverable needs a reason', function () {
    ['task' => $task, 'reviewer' => $reviewer] = deliveryTestDeliverable([DeliverableKind::Text], deliveryTestDelivered());
    $before = deliveryTestSnapshot($task);
    $this->actingAs($reviewer);

    $errors = deliveryTestErrors(fn () => app(ReturnDepartmentTask::class)->handle($task, DeliveryState::Delivered, '   '));

    expect($errors['reason'] ?? [])->toBe([__('department_tasks.return_reason_required')]);
    expect(deliveryTestSnapshot($task))->toBe($before);
});

test('returning a delivered deliverable sends it back to work with the reason', function () {
    ['task' => $task, 'reviewer' => $reviewer] = deliveryTestDeliverable([DeliverableKind::Text], deliveryTestDelivered());
    $this->actingAs($reviewer);

    $returned = app(ReturnDepartmentTask::class)->handle($task, DeliveryState::Delivered, ' The caption names the wrong village. ');

    expect($returned->delivery_state)->toBe(DeliveryState::Returned);
    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'delivery_state' => 'returned',
        'status' => 'in progress',
        'returned_reason' => 'The caption names the wrong village.',
        'delivered_text' => 'Caption for the distribution story.',
    ]);
});

test('a returned deliverable is delivered again and keeps the last reason for its reviewer', function () {
    ['task' => $task, 'deliverer' => $deliverer] = deliveryTestDeliverable([DeliverableKind::Text], [
        ...deliveryTestDelivered(),
        'delivery_state' => DeliveryState::Returned,
        'status' => TaskStatus::InProgress,
        'returned_reason' => 'The caption names the wrong village.',
    ]);
    $this->actingAs($deliverer);

    app(DeliverDepartmentTask::class)->handle($task, DeliveryState::Returned, [
        'delivered_on' => '2026-10-15',
        'delivered_text' => 'Corrected caption.',
    ]);

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'delivery_state' => 'delivered',
        'status' => 'under review',
        'delivered_on' => '2026-10-15',
        'delivered_text' => 'Corrected caption.',
        'returned_reason' => 'The caption names the wrong village.',
    ]);
});

test('accepting a delivered deliverable completes the task', function () {
    ['task' => $task, 'reviewer' => $reviewer] = deliveryTestDeliverable([DeliverableKind::Text], [
        ...deliveryTestDelivered(),
        'returned_reason' => 'The caption names the wrong village.',
    ]);
    $this->actingAs($reviewer);

    $accepted = app(AcceptDepartmentTask::class)->handle($task, DeliveryState::Delivered);

    expect($accepted->delivery_state)->toBe(DeliveryState::Accepted);
    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'delivery_state' => 'accepted',
        'status' => 'done',
        'completed_date' => '2026-10-15',
        'progress_percentage' => 100,
        'accepted_at' => '2026-10-15 10:30:00',
        'returned_reason' => null,
    ]);
});

test('accepting or returning a deliverable that was not delivered is refused', function (Closure $review) {
    ['task' => $task, 'reviewer' => $reviewer] = deliveryTestDeliverable([DeliverableKind::Text]);
    $before = deliveryTestSnapshot($task);
    $this->actingAs($reviewer);

    $errors = deliveryTestErrors(fn () => $review($task, DeliveryState::Requested));

    expect($errors['delivery_state'] ?? [])->toBe([__('department_tasks.not_delivered_yet')]);
    expect(deliveryTestSnapshot($task))->toBe($before);
})->with([
    'accept' => fn (Task $task, DeliveryState $expected) => app(AcceptDepartmentTask::class)->handle($task, $expected),
    'return' => fn (Task $task, DeliveryState $expected) => app(ReturnDepartmentTask::class)->handle($task, $expected, 'The caption names the wrong village.'),
]);

test('accepting or returning from a state someone else already changed is refused', function (Closure $review) {
    ['task' => $task, 'reviewer' => $reviewer] = deliveryTestDeliverable([DeliverableKind::Text], [
        ...deliveryTestDelivered(),
        'delivery_state' => DeliveryState::Returned,
        'status' => TaskStatus::InProgress,
        'returned_reason' => 'The caption names the wrong village.',
    ]);
    $before = deliveryTestSnapshot($task);
    $this->actingAs($reviewer);

    $errors = deliveryTestErrors(fn () => $review($task, DeliveryState::Delivered));

    expect($errors['delivery_state'] ?? [])->toBe([__('department_tasks.stale_delivery_state')]);
    expect(deliveryTestSnapshot($task))->toBe($before);
})->with([
    'accept' => fn (Task $task, DeliveryState $expected) => app(AcceptDepartmentTask::class)->handle($task, $expected),
    'return' => fn (Task $task, DeliveryState $expected) => app(ReturnDepartmentTask::class)->handle($task, $expected, 'The caption names the wrong village.'),
]);

test('a user with no right on the deliverable cannot deliver it', function () {
    ['task' => $task] = deliveryTestDeliverable([DeliverableKind::Text]);
    $before = deliveryTestSnapshot($task);
    $this->actingAs(User::factory()->create());

    $call = fn () => app(DeliverDepartmentTask::class)->handle($task, DeliveryState::Requested, [
        'delivered_on' => '2026-10-15',
        'delivered_text' => 'Caption for the distribution story.',
    ]);

    expect($call)->toThrow(AuthorizationException::class);
    expect(deliveryTestSnapshot($task))->toBe($before);
});

test('a user with no right on the deliverable can neither accept nor return it', function () {
    ['task' => $task] = deliveryTestDeliverable([DeliverableKind::Text], deliveryTestDelivered());
    $before = deliveryTestSnapshot($task);
    $this->actingAs(User::factory()->create());

    $accept = fn () => app(AcceptDepartmentTask::class)->handle($task, DeliveryState::Delivered);
    $return = fn () => app(ReturnDepartmentTask::class)->handle($task, DeliveryState::Delivered, 'The caption names the wrong village.');

    expect($accept)->toThrow(AuthorizationException::class)
        ->and($return)->toThrow(AuthorizationException::class);
    expect(deliveryTestSnapshot($task))->toBe($before);
});

test('a deliverable the user cannot see is not found and nothing is written', function () {
    ['task' => $task] = deliveryTestDeliverable([DeliverableKind::Text], deliveryTestDelivered());
    $outsiderEmployee = insertHrEmployee();
    insertHrContract($outsiderEmployee, insertHrPosition(insertHrDepartment('Programs')));
    $before = deliveryTestSnapshot($task);
    $this->actingAs(userWithRole('Team Member', $outsiderEmployee));

    $calls = [
        fn () => app(DeliverDepartmentTask::class)->handle($task, DeliveryState::Delivered, ['delivered_on' => '2026-10-15', 'delivered_text' => 'Caption.']),
        fn () => app(AcceptDepartmentTask::class)->handle($task, DeliveryState::Delivered),
        fn () => app(ReturnDepartmentTask::class)->handle($task, DeliveryState::Delivered, 'The caption names the wrong village.'),
    ];

    foreach ($calls as $call) {
        expect($call)->toThrow(ModelNotFoundException::class);
    }
    expect(deliveryTestSnapshot($task))->toBe($before);
});

test('delivering and returning each record one department task activity and nothing else', function () {
    ['task' => $task, 'deliverer' => $deliverer, 'reviewer' => $reviewer] = deliveryTestDeliverable([DeliverableKind::Photos]);
    $lastActivityId = (int) Activity::query()->max('id');

    $this->actingAs($deliverer);
    app(DeliverDepartmentTask::class)->handle($task, DeliveryState::Requested, ['delivered_on' => '2026-10-15'], [validUpload('site.jpg')]);
    $this->actingAs($reviewer);
    app(ReturnDepartmentTask::class)->handle($task, DeliveryState::Delivered, 'The photos are blurry.');

    $recorded = Activity::query()->where('id', '>', $lastActivityId)->orderBy('id')->get();
    expect($recorded->pluck('event')->all())->toBe(['department_task.delivered', 'department_task.returned'])
        ->and($recorded->pluck('subject_id')->all())->toBe([$task->id, $task->id])
        ->and($recorded->pluck('actor_id')->all())->toBe([$deliverer->id, $reviewer->id])
        ->and($recorded[0]->changes)->toEqual([
            'delivery_state' => ['requested', 'delivered'],
            'status' => ['todo', 'under review'],
        ])
        ->and($recorded[1]->changes)->toEqual([
            'delivery_state' => ['delivered', 'returned'],
            'status' => ['under review', 'in progress'],
            'returned_reason' => [null, 'The photos are blurry.'],
        ]);
});
