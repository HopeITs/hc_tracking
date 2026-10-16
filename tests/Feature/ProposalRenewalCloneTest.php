<?php

use App\Actions\Proposals\CloneProposalForRenewal;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ResultType;
use App\Exceptions\InvalidParentException;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\Report;
use App\Models\Result;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

const RENEWAL_TREE_COPIER = 'App\Actions\Results\CopyResultTree';

beforeEach(function () {
    $this->travelTo('2026-10-14 10:00:00');

    // T32's CopyResultTree is not merged yet: stand in for it with the same
    // contract (handle(Result $sourceRoot, Proposal $proposal): Result) until
    // the real class exists.
    if (! class_exists(RENEWAL_TREE_COPIER)) {
        app()->bind(RENEWAL_TREE_COPIER, fn () => new class
        {
            public function handle(Result $sourceRoot, Proposal $proposal): Result
            {
                $copy = function (Result $source, ?int $parentId) use (&$copy, $proposal): Result {
                    $node = Result::query()->create([
                        'statement' => $source->statement,
                        'type' => $source->type,
                        'program_id' => $source->program_id,
                        'proposal_id' => $proposal->id,
                        'parent_id' => $parentId,
                        'copied_from_id' => $source->id,
                    ]);

                    foreach ($source->children()->get() as $child) {
                        $copy($child, $node->id);
                    }

                    return $node;
                };

                return $copy($sourceRoot, null);
            }
        });
    }
});

/**
 * An approved proposal on one project with a framework, a milestone, a task,
 * a target with an approved report, and the things a renewal must not copy.
 *
 * @return array{previous: Proposal, project: Project, root: Result, target: PlanIndicator}
 */
function renewalSource(array $projectState = []): array
{
    return Activity::withoutRecording(function () use ($projectState): array {
        $program = Program::factory()->create();
        $centerId = insertHrCenter(insertHrCity());
        $project = Project::factory()->status(ProjectStatus::Active)->create([
            'program_id' => $program->id,
            'center_id' => $centerId,
            ...$projectState,
        ]);

        $previous = Proposal::factory()->running()->create([
            'name' => 'Winter Shelter 2026',
            'internal_project_number' => 'HC-2026-310',
            'planned_date_begin' => '2026-01-01',
            'planned_date_end' => '2026-12-31',
            'actual_date_begin' => '2026-02-01',
            'notes' => 'Last year notes',
        ]);
        $previous->projects()->attach($project->id, ['target_beneficiaries' => 400]);

        $root = Result::factory()->type(ResultType::Impact)->create(['program_id' => $program->id, 'proposal_id' => $previous->id]);
        $outcome = Result::factory()->childOf($root)->type(ResultType::Outcome)->create(['program_id' => $program->id, 'proposal_id' => $previous->id]);

        $library = LibraryIndicator::factory()->create();
        $indicator = Indicator::factory()->create(['result_id' => $outcome->id, 'library_indicator_id' => $library->id, 'project_id' => $project->id]);
        $target = PlanIndicator::factory()->forProposal($previous)->create(['indicator_id' => $indicator->id, 'project_id' => $project->id, 'target' => 100]);
        Report::factory()->approved()->create(['plan_indicator_id' => $target->id, 'value' => 60]);

        $parentMilestone = Milestone::factory()->create(['project_id' => $project->id, 'proposal_id' => $previous->id, 'planned_start_date' => '2026-03-01', 'planned_end_date' => '2026-04-01', 'name' => 'Baseline']);
        Milestone::factory()->childOf($parentMilestone)->create(['proposal_id' => $previous->id, 'planned_start_date' => '2026-03-10', 'name' => 'Survey']);

        Task::factory()->create(['source_type' => 'proposal', 'source_id' => $previous->id, 'title' => 'Verify list', 'start_date' => '2026-03-01', 'due_date' => '2026-03-31', 'status' => 'done']);

        DonorProposal::factory()->create(['proposal_id' => $previous->id, 'donor_id' => $previous->donor_id]);
        ProposalReviewer::factory()->create(['proposal_id' => $previous->id]);

        return compact('previous', 'project', 'root', 'target');
    });
}

/**
 * A user holding only the given permissions, with no role.
 *
 * @param  list<string>  $permissions
 */
function renewalBareUser(array $permissions): User
{
    userWithRole('Team Member');
    $user = Activity::withoutRecording(fn (): User => User::factory()->create());
    $user->givePermissionTo($permissions);
    test()->actingAs($user);

    return $user;
}

function renewalActor(string $role = 'General Manager'): User
{
    $user = userWithRole($role);
    test()->actingAs($user);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function renewalData(array $overrides = []): array
{
    return ['internalProjectNumber' => 'HC-2027-001', ...$overrides];
}

test('a renewal is a concept note linked to the source with its donor, projects, beneficiaries and notes', function () {
    ['previous' => $previous, 'project' => $project] = renewalSource();
    $actor = renewalActor();

    $result = app(CloneProposalForRenewal::class)->handle($previous, renewalData(), $actor);
    $renewal = $result['proposal'];

    expect($renewal->status)->toBe(ProposalStatus::ConceptNote)
        ->and($renewal->previous_proposal_id)->toBe($previous->id)
        ->and($renewal->donor_id)->toBe($previous->donor_id)
        ->and($renewal->name)->toBe('Winter Shelter 2026')
        ->and($renewal->notes)->toBe('Last year notes')
        ->and($renewal->actual_date_begin)->toBeNull()
        ->and($renewal->planned_date_begin->toDateString())->toBe('2027-01-01')
        ->and($renewal->planned_date_end->toDateString())->toBe('2027-12-31')
        ->and(DB::table('project_proposal')->where('proposal_id', $renewal->id)->pluck('target_beneficiaries', 'project_id')->all())->toBe([$project->id => 400])
        ->and(DB::table('proposal_status_notes')->where('proposal_id', $renewal->id)->count())->toBe(1)
        ->and($result['skippedProjects'])->toBe([])
        ->and($result['copied']['results'])->toBe(2);
});

test('no project is created and the previous proposal is left alone', function () {
    ['previous' => $previous] = renewalSource();
    $projects = DB::table('projects')->count();

    app(CloneProposalForRenewal::class)->handle($previous, renewalData(), renewalActor());

    expect(DB::table('projects')->count())->toBe($projects)
        ->and($previous->fresh()->status)->toBe(ProposalStatus::Approved)
        ->and(Schema::hasColumn('projects', 'previous_project_id'))->toBeFalse();
});

test('files, agreement, reviewers, targets and reports are not copied', function () {
    ['previous' => $previous] = renewalSource();

    $renewal = app(CloneProposalForRenewal::class)->handle($previous, renewalData(), renewalActor())['proposal'];

    expect(DB::table('donor_proposal')->where('proposal_id', $renewal->id)->count())->toBe(0)
        ->and(DB::table('proposal_reviewers')->where('proposal_id', $renewal->id)->count())->toBe(0)
        ->and(DB::table('plan_indicators')->where('source_type', 'proposal')->where('source_id', $renewal->id)->count())->toBe(0)
        ->and(DB::table('media')->where('mediable_type', 'proposal')->where('mediable_id', $renewal->id)->count())->toBe(0)
        ->and(DB::table('reports')->count())->toBe(1);
});

test('the framework is copied with copied_from_id and remapped parents', function () {
    ['previous' => $previous, 'root' => $root] = renewalSource();

    $renewal = app(CloneProposalForRenewal::class)->handle($previous, renewalData(), renewalActor())['proposal'];

    $copied = Result::query()->where('proposal_id', $renewal->id)->orderBy('id')->get();
    $newRoot = $copied->firstWhere('parent_id', null);
    $child = $copied->firstWhere('parent_id', '!=', null);

    expect($copied)->toHaveCount(2)
        ->and($newRoot->copied_from_id)->toBe($root->id)
        ->and($child->parent_id)->toBe($newRoot->id)
        ->and($child->copied_from_id)->not->toBe($child->id);
});

test('milestones and tasks are copied with planned dates only and a reset status', function () {
    ['previous' => $previous, 'project' => $project] = renewalSource();

    $result = app(CloneProposalForRenewal::class)->handle($previous, renewalData(), renewalActor());
    $renewal = $result['proposal'];

    $milestones = Milestone::query()->where('proposal_id', $renewal->id)->orderBy('id')->get();
    $task = Task::query()->where('source_type', 'proposal')->where('source_id', $renewal->id)->sole();

    expect($milestones)->toHaveCount(2)
        ->and($milestones[0]->project_id)->toBe($project->id)
        ->and($milestones[0]->planned_start_date->toDateString())->toBe('2027-03-01')
        ->and($milestones[0]->actual_start_date)->toBeNull()
        ->and($milestones[1]->parent_milestone_id)->toBe($milestones[0]->id)
        ->and(DB::table('milestones')->where('proposal_id', $renewal->id)->whereNotNull('copied_from_id')->count())->toBe(2)
        ->and($task->status->value)->toBe('todo')
        ->and($task->start_date->toDateString())->toBe('2027-03-01')
        ->and($task->completed_date)->toBeNull()
        ->and(DB::table('tasks')->where('id', $task->id)->value('copied_from_id'))->not->toBeNull()
        ->and($result['copied'])->toMatchArray(['results' => 2, 'milestones' => 2, 'tasks' => 1]);
});

test('without results.create the framework is skipped and the rest is created', function () {
    ['previous' => $previous] = renewalSource();
    $actor = renewalBareUser(['proposals.create', 'proposals.update', 'proposals.view', 'projects.view']);

    $result = app(CloneProposalForRenewal::class)->handle($previous, renewalData(), $actor);

    expect($result['copied'])->toBe(['results' => 0, 'indicators' => 0, 'milestones' => 2, 'tasks' => 1])
        ->and($result['proposal']->exists)->toBeTrue()
        ->and($result['skippedParts'])->toBe(['framework']);
});

test('an inactive center skips its project and names it', function () {
    ['previous' => $previous, 'project' => $project] = renewalSource();
    $other = Project::factory()->create(['program_id' => $project->program_id, 'center_id' => insertHrCenter(insertHrCity('Idlib', 'إدلب'), 'Hope Idlib', 'أمل إدلب', false)]);
    $previous->projects()->attach($other->id, ['target_beneficiaries' => 10]);

    $result = app(CloneProposalForRenewal::class)->handle($previous, renewalData(), renewalActor());

    expect($result['skippedProjects'])->toBe([$other->name])
        ->and($result['proposal']->projects()->pluck('projects.id')->all())->toBe([$project->id]);
});

test('an inactive library indicator is skipped and named', function () {
    ['previous' => $previous, 'target' => $target] = renewalSource();
    $library = LibraryIndicator::query()->findOrFail($target->indicator->library_indicator_id);
    $library->forceFill(['is_active' => false])->save();

    $result = app(CloneProposalForRenewal::class)->handle($previous, renewalData(), renewalActor());

    expect($result['skippedIndicators'])->toHaveCount(1)
        ->and($result['copied']['indicators'])->toBe(0);
});

test('a closed project with a newer open sibling is replaced by the open one', function () {
    ['previous' => $previous, 'project' => $project] = renewalSource(['status' => ProjectStatus::Closed, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $open = Project::factory()->create(['program_id' => $project->program_id, 'center_id' => $project->center_id]);

    $result = app(CloneProposalForRenewal::class)->handle($previous, renewalData(), renewalActor());

    expect($result['proposal']->projects()->pluck('projects.id')->all())->toBe([$open->id])
        ->and($result['replacedProjects'])->toBe([$project->name]);
});

test('a double click returns the same draft and a second user gets their own', function () {
    ['previous' => $previous] = renewalSource();
    $actor = renewalActor();

    $first = app(CloneProposalForRenewal::class)->handle($previous, renewalData(), $actor)['proposal'];
    $again = app(CloneProposalForRenewal::class)->handle($previous, renewalData(), $actor)['proposal'];

    expect($again->id)->toBe($first->id)
        ->and(DB::table('proposals')->where('previous_proposal_id', $previous->id)->count())->toBe(1);

    $other = renewalActor('Public Relations Manager');
    $second = app(CloneProposalForRenewal::class)->handle($previous, renewalData(['internalProjectNumber' => 'HC-2027-002']), $other)['proposal'];

    expect($second->id)->not->toBe($first->id)
        ->and(CloneProposalForRenewal::existingDraftFor($previous)?->internal_project_number)->not->toBeNull();
});

test('a failure rolls the whole clone back', function () {
    ['previous' => $previous] = renewalSource();
    $actor = renewalActor();
    $before = [DB::table('proposals')->count(), DB::table('results')->count(), DB::table('milestones')->count()];

    app()->bind(RENEWAL_TREE_COPIER, fn () => new class
    {
        public function handle(Result $sourceRoot, Proposal $proposal): Result
        {
            Result::query()->create(['statement' => 'half', 'type' => ResultType::Impact, 'proposal_id' => $proposal->id, 'program_id' => $sourceRoot->program_id]);

            throw new RuntimeException('boom');
        }
    });

    expect(fn () => app(CloneProposalForRenewal::class)->handle($previous, renewalData(), $actor))->toThrow(RuntimeException::class)
        ->and([DB::table('proposals')->count(), DB::table('results')->count(), DB::table('milestones')->count()])->toBe($before);
});

test('a duplicate internal number is refused with nothing written', function () {
    ['previous' => $previous] = renewalSource();

    expect(fn () => app(CloneProposalForRenewal::class)->handle($previous, renewalData(['internalProjectNumber' => 'HC-2026-310']), renewalActor()))
        ->toThrow(ValidationException::class)
        ->and(DB::table('proposals')->where('previous_proposal_id', $previous->id)->count())->toBe(0);
});

test('a proposal cannot renew itself or one of its successors', function () {
    ['previous' => $previous] = renewalSource();
    $next = Proposal::factory()->create(['previous_proposal_id' => $previous->id]);

    expect(fn () => $previous->forceFill(['previous_proposal_id' => $previous->id])->save())->toThrow(InvalidParentException::class)
        ->and(fn () => $previous->forceFill(['previous_proposal_id' => $next->id])->save())->toThrow(InvalidParentException::class);
});

test('deleting the previous proposal keeps the renewal and clears the link', function () {
    $previous = Activity::withoutRecording(function (): Proposal {
        $proposal = Proposal::factory()->running()->create();
        $proposal->projects()->attach(Project::factory()->create()->id, ['target_beneficiaries' => 5]);

        return $proposal;
    });
    $renewal = app(CloneProposalForRenewal::class)->handle($previous, renewalData(), renewalActor())['proposal'];

    DB::table('proposals')->where('id', $previous->id)->delete();

    expect(DB::table('proposals')->where('id', $renewal->id)->value('previous_proposal_id'))->toBeNull();
});

test('proposal.created carries renewed_from', function () {
    ['previous' => $previous] = renewalSource();

    $renewal = app(CloneProposalForRenewal::class)->handle($previous, renewalData(), renewalActor())['proposal'];
    $activity = Activity::query()->where('event', 'proposal.created')->where('subject_id', $renewal->id)->sole();

    expect($activity->properties['renewed_from'])->toBe($previous->id)
        ->and(Activity::query()->where('event', 'proposal.created')->where('subject_id', $renewal->id)->count())->toBe(1);
});

test('a user who cannot create proposals gets a 403', function () {
    ['previous' => $previous] = renewalSource();
    $actor = userWithRole('Team Member');
    test()->actingAs($actor);

    expect(fn () => app(CloneProposalForRenewal::class)->handle($previous, renewalData(), $actor))->toThrow(AuthorizationException::class)
        ->and(DB::table('proposals')->where('previous_proposal_id', $previous->id)->count())->toBe(0);
});

test('a previous proposal the user cannot view gets a 403', function () {
    ['previous' => $previous] = renewalSource();
    $actor = renewalBareUser(['proposals.create']);

    expect(fn () => app(CloneProposalForRenewal::class)->handle($previous, renewalData(), $actor))->toThrow(AuthorizationException::class);
});

test('last year achievement equals achievedValue and percentAchieved of the matched target', function () {
    ['previous' => $previous, 'target' => $oldTarget, 'project' => $project] = renewalSource();
    $renewal = app(CloneProposalForRenewal::class)->handle($previous, renewalData(), renewalActor())['proposal'];

    $newTarget = PlanIndicator::factory()->forProposal($renewal)->create([
        'indicator_id' => Indicator::factory()->create([
            'result_id' => Result::factory()->type(ResultType::Impact)->create(['proposal_id' => $renewal->id])->id,
            'library_indicator_id' => $oldTarget->indicator->library_indicator_id,
        ])->id,
        'project_id' => $project->id,
        'target' => 200,
    ]);

    $reference = PlanIndicator::previousYearReference($newTarget);

    expect($reference)->toBe(['value' => $oldTarget->achievedValue(), 'percent' => $oldTarget->percentAchieved()])
        ->and($reference['value'])->toBe(60.0)
        ->and(PlanIndicator::previousYearReference($oldTarget))->toBeNull();
});

test('a lock held by the same user and source makes a parallel call return the existing draft', function () {
    ['previous' => $previous] = renewalSource();
    $actor = renewalActor();
    $first = app(CloneProposalForRenewal::class)->handle($previous, renewalData(), $actor)['proposal'];

    $lock = Cache::lock('renewal-clone:'.$actor->id.':'.$previous->id, 10);
    $lock->get();

    $again = app(CloneProposalForRenewal::class)->handle($previous, renewalData(), $actor)['proposal'];
    $lock->release();

    expect($again->id)->toBe($first->id);
});
