<?php

use App\Actions\DonorReports\ComposeDonorReport;
use App\Actions\DonorReports\RegenerateDonorReport;
use App\Actions\DonorReports\SaveDonorReportSnapshot;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReportingPeriod;
use App\Enums\ReportStatus;
use App\Models\Activity;
use App\Models\DonorReport;
use App\Models\LibraryIndicator;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
| Composing, saving and regenerating a donor report (contracts §14.3; D-27a,
| D-32): reports.aggregate and view on the proposal inside every action, a
| frozen snapshot per version, finance only for funding-agreements.view.
*/

beforeEach(function () {
    $this->travelTo('2026-11-15 10:00:00');
    $this->manager = userWithRole('Public Relations Manager', insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Manager'));
    $this->aggregatorOnly = Activity::withoutRecording(fn (): User => User::factory()->create());
    $this->aggregatorOnly->givePermissionTo(['reports.aggregate', 'proposals.view']);
    $this->proposal = donorReportProposal();
    $this->center = donorReportCenter($this->proposal);
    $this->library = LibraryIndicator::factory()->create();
    $this->target = donorReportTarget($this->proposal, $this->center, $this->library);
    donorReportFigures($this->center, '2026-01', $this->library, [$this->target->id => ['value' => 10]]);
});

/**
 * @return array<string, mixed>
 */
function dbInput(array $overrides = []): array
{
    return ['period_type' => 'quarterly', 'start_month' => '2026-01', 'type' => 'narrative', 'audience' => 'donor', 'due_on' => '2026-04-15', ...$overrides];
}

function dbCompose(User $actor, ?int $proposalId = null, string $startMonth = '2026-01'): array
{
    test()->actingAs($actor);

    return app(ComposeDonorReport::class)->handle($proposalId ?? test()->proposal->id, ReportingPeriod::Quarterly, $startMonth, null, $actor);
}

function dbSave(User $actor, ?Proposal $proposal = null, array $input = []): DonorReport
{
    test()->actingAs($actor);

    return app(SaveDonorReportSnapshot::class)->handle($proposal ?? test()->proposal, dbInput($input), $actor);
}

function dbRegenerate(User $actor, DonorReport $report): DonorReport
{
    test()->actingAs($actor);

    return app(RegenerateDonorReport::class)->handle($report->fresh(), $actor);
}

test('without reports.aggregate every action is refused', function () {
    $financial = userWithRole('Financial');
    $report = DonorReport::factory()->composed()->create(['donor_proposal_id' => $this->proposal->donorProposals()->value('id')]);

    expect(fn () => dbCompose($financial))->toThrow(AuthorizationException::class)
        ->and(fn () => dbSave($financial))->toThrow(AuthorizationException::class)
        ->and(fn () => dbRegenerate($financial, $report))->toThrow(AuthorizationException::class);
});

test('an invisible or unapproved proposal is refused', function () {
    $noProposals = Activity::withoutRecording(fn (): User => User::factory()->create());
    $noProposals->givePermissionTo('reports.aggregate');
    $draft = donorReportProposal(['status' => 'proposal draft']);

    expect(fn () => dbCompose($noProposals))->toThrow(ValidationException::class, __('donor_reports.proposal_required'))
        ->and(fn () => dbCompose($this->manager, $draft->id))->toThrow(ValidationException::class, __('donor_reports.proposal_required'))
        ->and(fn () => dbCompose($this->manager, 999999))->toThrow(ValidationException::class, __('donor_reports.proposal_required'))
        ->and(fn () => dbSave($this->manager, $draft))->toThrow(ValidationException::class, __('donor_reports.proposal_required'));
});

test('a self-funded proposal is refused', function () {
    $selfFunded = donorReportProposal(internal: true);

    expect(fn () => dbCompose($this->manager, $selfFunded->id))->toThrow(ValidationException::class, __('donor_reports.self_funded'))
        ->and(fn () => dbSave($this->manager, $selfFunded))->toThrow(ValidationException::class, __('donor_reports.self_funded'));
});

test('a period outside the grant is refused', function () {
    expect(fn () => dbCompose($this->manager, startMonth: '2027-01'))->toThrow(ValidationException::class, __('donor_reports.period_outside_grant'))
        ->and(fn () => dbSave($this->manager, input: ['start_month' => '2025-10']))->toThrow(ValidationException::class, __('donor_reports.period_outside_grant'));
});

test('finance reaches only funding-agreements.view holders', function () {
    expect(dbCompose($this->manager))->toHaveKey('finance')
        ->and(dbCompose($this->aggregatorOnly))->not->toHaveKey('finance')
        ->and(dbSave($this->aggregatorOnly)->snapshot)->not->toHaveKey('finance')
        ->and(dbSave($this->manager, input: ['type' => 'financial'])->snapshot)->toHaveKey('finance');
});

test('save stores version 1 with its period and snapshot; regenerate adds version 2', function () {
    $first = dbSave($this->manager, input: ['notes' => 'For the board.']);

    expect($first->version)->toBe(1)
        ->and($first->period_type)->toBe(ReportingPeriod::Quarterly)
        ->and($first->period_start->toDateString())->toBe('2026-01-01')
        ->and($first->period_end->toDateString())->toBe('2026-03-31')
        ->and($first->snapshot['schema_version'])->toBe(1)
        ->and($first->snapshot['generated_by'])->toBe($this->manager->id)
        ->and($first->snapshot['period']['type'])->toBe('quarterly')
        ->and($first->title)->not->toBe('');

    $second = dbRegenerate($this->manager, $first);

    expect($second->id)->not->toBe($first->id)
        ->and($second->version)->toBe(2)
        ->and($second->only(['donor_proposal_id', 'title', 'notes', 'due_on']))->toEqual($first->only(['donor_proposal_id', 'title', 'notes', 'due_on']))
        ->and(dbSave($this->manager)->version)->toBe(3)
        ->and(Activity::query()->where('event', 'donor_report.saved')->count())->toBe(3)
        ->and(Activity::query()->where('event', 'donor_report.saved')->get()->every(fn (Activity $activity): bool => ! array_key_exists('snapshot', $activity->changes ?? [])))->toBeTrue();
});

test('a saved snapshot does not change when monthly data changes later', function () {
    $report = dbSave($this->manager);
    $before = $report->fresh()->snapshot;

    Report::query()->where('plan_indicator_id', $this->target->id)->update(['status' => ReportStatus::Draft, 'value' => 999]);
    DB::table('monthly_reports')->update(['status' => MonthlyReportStatus::Draft->value]);

    expect($report->fresh()->snapshot)->toBe($before)
        ->and(collect($before['indicators'])->first()['period']['value'])->toEqual(10.0);
});

test('a submitted report cannot be regenerated', function () {
    $report = dbSave($this->manager);
    $report->update(['submitted_on' => '2026-04-10']);

    expect(fn () => dbRegenerate($this->manager, $report))->toThrow(AuthorizationException::class)
        ->and(DonorReport::query()->count())->toBe(1);
});

test('save without a funding agreement is refused', function () {
    $noAgreement = donorReportProposal(withAgreement: false);

    expect(dbCompose($this->manager, $noAgreement->id)['header']['agreement_id'])->toBeNull()
        ->and(fn () => dbSave($this->manager, $noAgreement))->toThrow(ValidationException::class, __('donor_reports.agreement_required'));
});

test('save validates its input', function (array $input, string $field) {
    try {
        dbSave($this->manager, input: $input);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect(DonorReport::query()->count())->toBe(0);
})->with([
    'unknown period type' => [['period_type' => 'monthly'], 'periodType'],
    'unknown type' => [['type' => 'annual'], 'type'],
    'unknown audience' => [['audience' => 'public'], 'audience'],
    'missing due date' => [['due_on' => ''], 'dueOn'],
    'malformed start month' => [['start_month' => '2026/01'], 'startMonth'],
    'custom end before start' => [['period_type' => 'custom', 'start_month' => '2026-03', 'end_month' => '2026-02'], 'endMonth'],
    'notes too long' => [['notes' => str_repeat('a', 2001)], 'notes'],
]);

test('the responsible person must be an active employee', function () {
    $inactive = insertHrEmployee(firstNameEn: 'Gone', isActive: false);
    $active = insertHrEmployee(firstNameEn: 'Here');

    expect(fn () => dbSave($this->manager, input: ['responsible_employee_id' => $inactive]))->toThrow(ValidationException::class, __('donor_reports.responsible_inactive'))
        ->and(dbSave($this->manager, input: ['responsible_employee_id' => $active])->responsible_employee_id)->toBe($active);
});

test('a vacant default slot leaves the report without a responsible', function () {
    expect(dbSave($this->manager)->responsible_employee_id)->toBeNull();
});
