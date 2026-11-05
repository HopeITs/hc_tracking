<?php

declare(strict_types=1);

use App\Enums\MilestoneStatus;
use App\Jobs\RunAiPrompt;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Support\Ai\Agents\RecordSummarizer;
use App\Support\Ai\AiGateway;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
| The AI brief on project and proposal pages (AI trial, step 7): made from
| what the page shows the viewer, kept while those facts stay the same.
*/

/**
 * Run the summary job the page queued, with the summarizer answering $reply.
 *
 * @param  array<string, mixed>|string  $reply
 */
function runQueuedSummary(array|string $reply): void
{
    RecordSummarizer::fake([is_string($reply) ? $reply : json_encode($reply)]);

    Queue::pushed(RunAiPrompt::class)->each(fn (RunAiPrompt $job) => $job->handle(app(AiGateway::class)));
}

function briefReply(): array
{
    return [
        'verdict' => 'watch',
        'headline' => 'Delivery is on plan but the survey milestone is late.',
        'points' => ['Baseline assessment is reached.', '  ', 'Household survey is late.'],
        'next_step' => 'Agree a new survey date with the owner.',
    ];
}

function summaryProject(): Project
{
    return Activity::withoutRecording(function (): Project {
        $project = Project::factory()->create(['name' => 'Aleppo Wells']);
        Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Household survey', 'status' => MilestoneStatus::Planned, 'planned_end_date' => '2026-01-15']);

        return $project;
    });
}

test('the project brief is made from the page facts on the fast model and kept for the same facts', function () {
    Queue::fake();
    $manager = userWithRole('Managers');
    $project = summaryProject();

    $page = Livewire::actingAs($manager)
        ->test('pages::projects.show', ['project' => $project])
        ->assertSeeText(__('ai.summarize'))
        ->call('summarizeWithAi')
        ->assertSeeText(__('ai.summarizing'));

    Queue::assertPushedOn('ai', RunAiPrompt::class, fn (RunAiPrompt $job): bool => $job->feature === 'project-'.$project->id.'-summary'
        && $job->tier === 'fast'
        && str_contains($job->prompt, 'RECORD: project "Aleppo Wells"')
        && str_contains($job->prompt, 'Household survey'));

    runQueuedSummary(briefReply());

    $page->call('collectAiSummary')
        ->assertSeeText('Delivery is on plan but the survey milestone is late.')
        ->assertSeeText(__('ai.verdicts.watch'))
        ->assertSeeText('Agree a new survey date with the owner.')
        ->assertDontSeeText(__('ai.summarize'));

    Livewire::actingAs($manager)
        ->test('pages::projects.show', ['project' => $project])
        ->assertSeeText('Household survey is late.')
        ->call('summarizeWithAi');

    Queue::assertPushed(RunAiPrompt::class, 1);
});

test('a change the viewer can see on the page retires the brief', function () {
    Queue::fake();
    $manager = userWithRole('Managers');
    $project = summaryProject();

    Livewire::actingAs($manager)->test('pages::projects.show', ['project' => $project])->call('summarizeWithAi');
    runQueuedSummary(briefReply());
    Livewire::actingAs($manager)->test('pages::projects.show', ['project' => $project])->call('collectAiSummary');

    Activity::withoutRecording(fn () => Milestone::query()->update(['status' => MilestoneStatus::Reached]));

    Livewire::actingAs($manager)
        ->test('pages::projects.show', ['project' => $project])
        ->assertDontSeeText('Delivery is on plan but the survey milestone is late.')
        ->assertSeeText(__('ai.summarize'));
});

test('a brief in the wrong shape is not kept and says so', function () {
    Queue::fake();
    $manager = userWithRole('Managers');

    $page = Livewire::actingAs($manager)->test('pages::projects.show', ['project' => summaryProject()])->call('summarizeWithAi');
    runQueuedSummary(['verdict' => 'excellent', 'headline' => 'x', 'points' => [], 'next_step' => '']);

    $page->call('collectAiSummary')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['slots']['text'] ?? null) === __('ai.summary_failed'))
        ->assertSeeText(__('ai.summarize'));
});

test('viewers without ai.summarize see no brief and cannot ask for one', function () {
    Queue::fake();

    Livewire::actingAs(userWithRole('Team Member'))
        ->test('pages::projects.show', ['project' => summaryProject()])
        ->assertDontSeeText(__('ai.summarize'))
        ->call('summarizeWithAi');

    Queue::assertNothingPushed();
});

test('the proposal brief reads the proposal page and is offered to the General Manager', function () {
    Queue::fake();
    $proposal = Activity::withoutRecording(fn (): Proposal => Proposal::factory()->create(['name' => 'Winter Response Grant']));

    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->assertSeeText(__('ai.summarize'))
        ->call('summarizeWithAi');

    Queue::assertPushed(RunAiPrompt::class, fn (RunAiPrompt $job): bool => $job->feature === 'proposal-'.$proposal->id.'-summary'
        && str_contains($job->prompt, 'RECORD: grant proposal "Winter Response Grant"'));
});
