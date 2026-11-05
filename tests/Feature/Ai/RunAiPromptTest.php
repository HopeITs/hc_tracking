<?php

declare(strict_types=1);

use App\Events\AiPromptFinished;
use App\Jobs\RunAiPrompt;
use App\Models\AiRequest;
use App\Models\User;
use App\Support\Ai\AiGateway;
use App\Support\Ai\HcAgent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

class QueuedProbeAgent extends HcAgent
{
    public function instructions(): string
    {
        return 'Answer briefly.';
    }
}

it('queues the prompt on the ai queue and starts it as pending', function (): void {
    Queue::fake();
    $user = User::factory()->create();

    $key = RunAiPrompt::start(new QueuedProbeAgent, 'Hi', 'probe', $user);

    Queue::assertPushedOn('ai', RunAiPrompt::class, fn (RunAiPrompt $job): bool => $job->requestKey === $key
        && $job->userId === $user->id
        && $job->connection === 'ai');

    expect(RunAiPrompt::resultFor($key, $user))->toBe(['status' => 'pending', 'text' => null, 'request_id' => null]);
});

it('stores the cleaned answer and pushes it to the user', function (): void {
    Event::fake([AiPromptFinished::class]);
    QueuedProbeAgent::fake(["thinking...</think>\nمرحبا"]);
    $user = User::factory()->create();

    $job = new RunAiPrompt(new QueuedProbeAgent, 'Hi', 'probe', $user->id, 'key-1');
    $job->handle(app(AiGateway::class));

    expect(RunAiPrompt::resultFor('key-1', $user))->toBe(['status' => 'done', 'text' => 'مرحبا', 'request_id' => AiRequest::sole()->id]);
    Event::assertDispatched(AiPromptFinished::class, fn (AiPromptFinished $event): bool => $event->userId === $user->id
        && $event->succeeded
        && $event->text === 'مرحبا'
        && $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$user->id);
});

it('marks the request failed without exposing the provider error', function (): void {
    Event::fake([AiPromptFinished::class]);
    $user = User::factory()->create();

    (new RunAiPrompt(new QueuedProbeAgent, 'Hi', 'probe', $user->id, 'key-2'))
        ->failed(new RuntimeException('Connection refused'));

    expect(RunAiPrompt::resultFor('key-2', $user))->toBe(['status' => 'failed', 'text' => null, 'request_id' => null]);
    Event::assertDispatched(AiPromptFinished::class, fn (AiPromptFinished $event): bool => ! $event->succeeded
        && $event->broadcastWith() === ['key' => 'key-2', 'succeeded' => false, 'text' => null]);
});

it('never shows one user the answer started by another', function (): void {
    Queue::fake();
    $owner = User::factory()->create();

    $key = RunAiPrompt::start(new QueuedProbeAgent, 'Hi', 'probe', $owner);

    expect(RunAiPrompt::resultFor($key, User::factory()->create()))->toBeNull()
        ->and(RunAiPrompt::resultFor('unknown-key', $owner))->toBeNull();
});

it('keeps the answer when the live push fails', function (): void {
    Event::listen(AiPromptFinished::class, fn () => throw new RuntimeException('Reverb is down'));
    QueuedProbeAgent::fake(['مرحبا']);
    $user = User::factory()->create();

    (new RunAiPrompt(new QueuedProbeAgent, 'Hi', 'probe', $user->id, 'key-3'))->handle(app(AiGateway::class));

    expect(RunAiPrompt::resultFor('key-3', $user)['status'])->toBe('done');
});
