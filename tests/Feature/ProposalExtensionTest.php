<?php

use App\Actions\Proposals\ExtendProposal;
use App\Enums\MediaCollection;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Enums\StatusNoteKind;
use App\Events\ProjectPeriodChanged;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\Media;
use App\Models\MonthlyReport;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-14 10:00:00');
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    $this->user = User::factory()->role('Public Relations Manager')->create();
    $this->proposal = Proposal::factory()->create([
        'status' => ProposalStatus::Active,
        'planned_date_begin' => '2026-11-01',
        'planned_date_end' => '2027-10-31',
        'actual_date_begin' => '2026-11-01',
        'actual_date_end' => '2027-10-31',
    ]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function extLinkedProject(ProjectStatus $state, array $attributes = []): Project
{
    $factory = match ($state) {
        ProjectStatus::Active => Project::factory()->active(),
        ProjectStatus::Approved => Project::factory()->approved(),
        ProjectStatus::Closed => Project::factory()->closed(),
        default => Project::factory()->status($state),
    };

    $project = $factory->create(['start_date' => '2026-11-01', 'end_date' => '2027-10-31', ...$attributes]);
    test()->proposal->projects()->attach($project->id, ['target_beneficiaries' => 100]);

    return $project;
}

/**
 * @param  array<string, mixed>  $input
 */
function extendProposal(array $input = [], ?ProposalStatus $expected = null, ?User $actor = null): ProposalStatusNote
{
    test()->actingAs($actor ?? test()->user);

    return app(ExtendProposal::class)->handle(
        test()->proposal,
        $expected ?? ProposalStatus::Active,
        ['new_date_end' => '2028-03-31', 'reason' => 'The donor approved six more months for the winter phase.', ...$input],
    );
}

test('extending keeps the original end, records the new one, one note and the amendment file', function () {
    $note = extendProposal(['file' => validUpload('amendment.pdf')]);

    $proposal = $this->proposal->fresh();

    expect($proposal->actual_date_end->toDateString())->toBe('2027-10-31')
        ->and($proposal->extended_date_end->toDateString())->toBe('2028-03-31')
        ->and($proposal->extension_reason)->toBe('The donor approved six more months for the winter phase.')
        ->and($proposal->effectiveDateEnd()->toDateString())->toBe('2028-03-31')
        ->and($proposal->status)->toBe(ProposalStatus::Active)
        ->and($note->kind)->toBe(StatusNoteKind::Extension)
        ->and($note->status)->toBe(ProposalStatus::Active)
        ->and($note->is_correction)->toBeFalse()
        ->and($note->context)->toBe('The donor approved six more months for the winter phase.')
        ->and($note->changed_by)->toBe($this->user->id)
        ->and(ProposalStatusNote::query()->where('kind', 'extension')->count())->toBe(1);

    $media = Media::query()->sole();

    expect($media->collection)->toBe(MediaCollection::Amendment)
        ->and($media->mediable_type)->toBe('proposal_status_note')
        ->and($media->mediable_id)->toBe($note->id);
});

test('the amendment file is optional', function () {
    $note = extendProposal();

    expect($note->kind)->toBe(StatusNoteKind::Extension)
        ->and(Media::query()->count())->toBe(0);
});

test('an active project that ends earlier is widened and raises ProjectPeriodChanged', function () {
    $project = extLinkedProject(ProjectStatus::Active);
    Event::fake([ProjectPeriodChanged::class]);

    extendProposal();

    expect($project->fresh()->start_date->toDateString())->toBe('2026-11-01')
        ->and($project->fresh()->end_date->toDateString())->toBe('2028-03-31')
        ->and($project->fresh()->status)->toBe(ProjectStatus::Active);

    Event::assertDispatchedTimes(ProjectPeriodChanged::class, 1);
    Event::assertDispatched(ProjectPeriodChanged::class, fn (ProjectPeriodChanged $event): bool => $event->projectId === $project->id
        && $event->oldEnd->toDateString() === '2027-10-31' && $event->newEnd->toDateString() === '2028-03-31'
        && $event->oldStart->toDateString() === '2026-11-01' && $event->newStart->toDateString() === '2026-11-01');
});

test('an approved project is widened without the event', function () {
    $project = extLinkedProject(ProjectStatus::Approved);
    Event::fake([ProjectPeriodChanged::class]);

    extendProposal();

    expect($project->fresh()->end_date->toDateString())->toBe('2028-03-31')
        ->and($project->fresh()->status)->toBe(ProjectStatus::Approved);

    Event::assertNotDispatched(ProjectPeriodChanged::class);
});

test('a project that already ends later is left alone', function () {
    $project = extLinkedProject(ProjectStatus::Active, ['end_date' => '2028-09-30']);
    Event::fake([ProjectPeriodChanged::class]);

    extendProposal();

    expect($project->fresh()->end_date->toDateString())->toBe('2028-09-30');

    Event::assertNotDispatched(ProjectPeriodChanged::class);
});

test('closed and archived projects are not touched', function () {
    $closed = extLinkedProject(ProjectStatus::Closed, ['end_date' => '2027-10-31']);
    $archived = extLinkedProject(ProjectStatus::Archived);
    Event::fake([ProjectPeriodChanged::class]);

    extendProposal();

    expect($closed->fresh()->end_date->toDateString())->toBe('2027-10-31')
        ->and($archived->fresh()->end_date->toDateString())->toBe('2027-10-31');

    Event::assertNotDispatched(ProjectPeriodChanged::class);
});

test('only an active proposal can be extended', function (ProposalStatus $status) {
    $this->proposal->forceFill(['status' => $status])->save();
    Event::fake([ProjectPeriodChanged::class]);

    expect(fn () => extendProposal(expected: $status))
        ->toThrow(ValidationException::class, __('proposals.extend_only_active'));
    expect(ProposalStatusNote::query()->count())->toBe(0)
        ->and($this->proposal->fresh()->extended_date_end)->toBeNull();

    Event::assertNotDispatched(ProjectPeriodChanged::class);
})->with([ProposalStatus::Submitted, ProposalStatus::Approved, ProposalStatus::Completed]);

test('a new end that is not after the current end is refused', function (string $date) {
    extLinkedProject(ProjectStatus::Active);
    Event::fake([ProjectPeriodChanged::class]);

    expect(fn () => extendProposal(['new_date_end' => $date]))
        ->toThrow(ValidationException::class, __('proposals.extend_date_after', ['date' => 'Oct 31, 2027']));
    expect(ProposalStatusNote::query()->count())->toBe(0)
        ->and($this->proposal->fresh()->extended_date_end)->toBeNull();

    Event::assertNotDispatched(ProjectPeriodChanged::class);
})->with(['2027-10-31', '2027-06-30']);

test('a reason shorter than ten characters is refused', function () {
    expect(fn () => extendProposal(['reason' => 'too short']))->toThrow(ValidationException::class);
    expect(ProposalStatusNote::query()->count())->toBe(0);
});

test('a stale expected status is refused and nothing is written', function () {
    $this->proposal->forceFill(['status' => ProposalStatus::Completed])->save();

    expect(fn () => extendProposal(expected: ProposalStatus::Active))
        ->toThrow(ValidationException::class, __('proposals.stale_status'));
    expect(ProposalStatusNote::query()->count())->toBe(0);
});

test('a team member cannot extend a proposal', function () {
    $member = User::factory()->role('Team Member')->create();

    expect(fn () => extendProposal(actor: $member))->toThrow(AuthorizationException::class);
    expect(ProposalStatusNote::query()->count())->toBe(0);
});

test('a second extension must end later than the first and appends a note', function () {
    extendProposal();

    expect(fn () => extendProposal(['new_date_end' => '2028-03-31']))
        ->toThrow(ValidationException::class, __('proposals.extend_date_after', ['date' => 'Mar 31, 2028']));

    extendProposal(['new_date_end' => '2028-06-30', 'reason' => 'One more quarter was agreed with the donor.']);

    $proposal = $this->proposal->fresh();

    expect($proposal->actual_date_end->toDateString())->toBe('2027-10-31')
        ->and($proposal->extended_date_end->toDateString())->toBe('2028-06-30')
        ->and($proposal->extension_reason)->toBe('One more quarter was agreed with the donor.')
        ->and(ProposalStatusNote::query()->where('kind', 'extension')->count())->toBe(2);
});

/**
 * A confirmed monthly template line for a target of the proposal.
 */
function extTemplateLine(Project $project, string $to, ?ReportTemplateLineStatus $status = null): ReportTemplateLine
{
    return Activity::withoutRecording(function () use ($project, $to, $status): ReportTemplateLine {
        $template = ReportTemplate::query()->where('project_id', $project->id)->first()
            ?? ReportTemplate::factory()->confirmed()->create(['project_id' => $project->id]);
        $indicator = Indicator::factory()->create(['library_indicator_id' => LibraryIndicator::factory()->create()->id]);
        $target = PlanIndicator::factory()->forProposal(test()->proposal)->create(['indicator_id' => $indicator->id, 'project_id' => $project->id]);
        $line = ReportTemplateLine::factory()->confirmed()->monthly()->create([
            'report_template_id' => $template->id,
            'plan_indicator_id' => $target->id,
            'effective_from' => '2026-11-01',
            'effective_to' => $to,
        ]);

        if ($status !== null) {
            $line->forceFill(['status' => $status])->save();
        }

        return $line;
    });
}

test('extending generates the new monthly reports and runs the template lines to the new last month', function () {
    $project = extLinkedProject(ProjectStatus::Active);
    $running = extTemplateLine($project, '2027-10-01');
    $retired = extTemplateLine($project, '2027-10-01', ReportTemplateLineStatus::Retired);
    $ended = extTemplateLine($project, '2027-03-01');

    extendProposal();

    $periods = MonthlyReport::query()->where('project_id', $project->id)->orderBy('period')->get()
        ->map(fn (MonthlyReport $report): string => $report->period->format('Y-m'))->all();

    expect($periods)->toContain('2027-11', '2028-03')
        ->and($periods)->not->toContain('2028-04')
        ->and($running->fresh()->effective_to->toDateString())->toBe('2028-03-01')
        ->and($running->fresh()->isDueIn(CarbonImmutable::parse('2028-03-01')))->toBeTrue()
        ->and($retired->fresh()->effective_to->toDateString())->toBe('2027-10-01')
        ->and($ended->fresh()->effective_to->toDateString())->toBe('2027-03-01');
});

test('the owners of the linked projects hear of the extension, with dates and names only', function () {
    $ownerEmployee = insertHrEmployee(firstNameEn: 'Rami');
    $owner = userWithRole('Managers', $ownerEmployee);
    extLinkedProject(ProjectStatus::Active, ['user_id' => $ownerEmployee]);

    extendProposal();

    $rows = UserNotification::query()->forUser($owner)->get()
        ->filter(fn (UserNotification $row): bool => $row->data['key'] === 'notifications.events.proposal.extended');

    expect($rows)->toHaveCount(1)
        ->and(json_encode($rows->first()->data))->not->toContain('budget');
});

test('the Extend action shows only on an active proposal, for someone who may update it', function () {
    Livewire::actingAs($this->user)
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->assertSeeHtml('data-test="open-extension-modal"');

    $member = User::factory()->role('Team Member')->create();

    Livewire::actingAs($member)
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->assertDontSeeHtml('data-test="open-extension-modal"')
        ->call('saveExtension')
        ->assertForbidden();
    expect(ProposalStatusNote::query()->count())->toBe(0);

    $this->proposal->forceFill(['status' => ProposalStatus::Approved])->save();

    Livewire::actingAs($this->user)
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->assertDontSeeHtml('data-test="open-extension-modal"');
});

test('the page extends the proposal and shows the new end beside the original with the amendment', function () {
    extLinkedProject(ProjectStatus::Active);

    Livewire::actingAs($this->user)
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->call('openExtensionModal')
        ->assertSet('showExtensionModal', true)
        ->set('extensionDateEnd', '2028-03-31')
        ->set('extensionReason', 'The donor approved six more months.')
        ->set('extensionFile', validUpload('amendment.pdf'))
        ->call('saveExtension')
        ->assertHasNoErrors()
        ->assertSet('showExtensionModal', false)
        ->assertSee('Ends Oct 31, 2027 · Extended to Mar 31, 2028')
        ->assertSee(StatusNoteKind::Extension->label());

    $media = Media::query()->sole();

    $this->actingAs($this->user)->get(route('proposals.files.download', [$this->proposal, $media]))->assertOk();
});

test('the page shows the validation message next to the field', function () {
    Livewire::actingAs($this->user)
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->call('openExtensionModal')
        ->set('extensionDateEnd', '2027-06-30')
        ->set('extensionReason', 'The donor approved six more months.')
        ->call('saveExtension')
        ->assertHasErrors(['extensionDateEnd'])
        ->assertSet('showExtensionModal', true);
});

test('?extend=1 opens the dialog only for someone who may extend an active proposal', function () {
    Livewire::withQueryParams(['extend' => '1'])->actingAs($this->user)
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->assertSet('showExtensionModal', true)
        ->assertSet('extendLink', false);

    $member = User::factory()->role('Team Member')->create();

    Livewire::withQueryParams(['extend' => '1'])->actingAs($member)
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->assertSet('showExtensionModal', false);

    $this->proposal->forceFill(['status' => ProposalStatus::Submitted])->save();

    Livewire::withQueryParams(['extend' => '1'])->actingAs($this->user)
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->assertSet('showExtensionModal', false);
});
