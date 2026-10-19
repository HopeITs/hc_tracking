<?php

use App\Actions\DonorReports\SaveDonorReportSnapshot;
use App\Enums\MonthlyReportStatus;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\LibraryIndicator;
use App\Models\TransfersTracking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
| The Donor Report Builder, the saved report page, its CSV export and its
| print view (contracts §14.3): validated figures only, the finance block
| and every amount only for funding-agreements.view holders, everything
| after Save read from the frozen snapshot.
*/

beforeEach(function () {
    $this->travelTo('2026-11-15 10:00:00');
    $this->moneyHolder = userWithRole('Public Relations Manager', insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Manager'));
    $this->aggregatorOnly = Activity::withoutRecording(fn (): User => User::factory()->create());
    $this->aggregatorOnly->givePermissionTo(['reports.aggregate', 'proposals.view']);
    $this->proposal = donorReportProposal();
    $this->center = donorReportCenter($this->proposal);
    $this->library = LibraryIndicator::factory()->create(['name_en' => 'Children enrolled', 'name_ar' => 'أطفال مسجلون']);
    $this->target = donorReportTarget($this->proposal, $this->center, $this->library, indicator: ['name' => 'Children enrolled']);
    donorReportFigures($this->center, '2026-01', $this->library, [$this->target->id => ['value' => 17]]);

    $agreementId = DonorProposal::query()->where('proposal_id', $this->proposal->id)->value('id');
    Activity::withoutRecording(fn () => TransfersTracking::factory()->received()->create([
        'donor_proposal_id' => $agreementId, 'scheduled_date' => '2026-02-10', 'receive_date' => '2026-02-12',
        'amount_transfered_currency' => 'USD', 'amount_received_currency' => 'USD', 'fees_currency' => 'USD',
        'payment_amount' => 98765.43, 'amount_received' => 98765.43, 'fees' => 12.5, 'net_amount_received' => 98752.93,
    ]));
});

function drpBuilder(User $user)
{
    return Livewire::actingAs($user)->test('pages::donor-reports.compose')
        ->set('proposalId', (string) test()->proposal->id)
        ->call('compose')
        ->assertHasNoErrors();
}

function drpSaved(User $user): DonorReport
{
    drpBuilder($user)->set('dueOn', '2026-12-01')->call('save')->assertHasNoErrors();

    return DonorReport::query()->latest('id')->firstOrFail();
}

test('the builder needs reports.aggregate', function () {
    $this->actingAs(userWithRole('Financial'))->get(route('donor-reports.compose'))->assertForbidden();
    $this->actingAs($this->aggregatorOnly)->get(route('donor-reports.compose', ['proposal' => $this->proposal->id]))->assertOk()->assertSeeText($this->proposal->name)->assertSee('data-test="compose-preview"', false);
});

test('a builder user without funding-agreements.view sees the figures but no amount', function () {
    drpBuilder($this->aggregatorOnly)
        ->assertSeeHtml('data-donor-report-snapshot')
        ->assertSeeText('Children enrolled')
        ->assertSeeHtml('data-period-value')
        ->assertDontSeeHtml('data-snapshot-finance')
        ->assertDontSeeText('98,765.43')
        ->assertDontSeeText('98,752.93');
});

test('a money holder sees received, fees and net per currency in a separate block', function () {
    drpBuilder($this->moneyHolder)
        ->assertSeeHtml('data-snapshot-finance')
        ->assertSeeHtml('data-finance-currency="USD"')
        ->assertSeeText('98,765.43')
        ->assertSeeText('12.50 USD')
        ->assertSeeText('98,752.93');
});

test('months not yet validated raise the completeness warning at the top', function () {
    donorReportFigures($this->center, '2026-02', $this->library, [$this->target->id => ['value' => 5]], MonthlyReportStatus::Submitted);

    drpBuilder($this->aggregatorOnly)
        ->assertSeeHtml('data-completeness-warning')
        ->assertSeeHtml('data-missing-month="'.$this->center->id.'-2026-02"')
        ->assertSeeText(__('donor_reports.states.not_validated'));
});

test('a period without validated data says so and can still be saved', function () {
    $builder = Livewire::actingAs($this->aggregatorOnly)->test('pages::donor-reports.compose')
        ->set('proposalId', (string) $this->proposal->id)
        ->set('startMonth', '2026-07')
        ->call('compose')
        ->assertSeeHtml('data-no-validated-data')
        ->assertSeeText(__('donor_reports.no_validated_data'));

    $builder->set('dueOn', '2026-12-01')->call('save')->assertHasNoErrors();

    expect(DonorReport::query()->sole()->period_start->toDateString())->toBe('2026-07-01');
});

test('without a funding agreement the builder previews but cannot save', function () {
    $proposal = donorReportProposal(withAgreement: false);

    Livewire::actingAs($this->aggregatorOnly)->test('pages::donor-reports.compose')
        ->set('proposalId', (string) $proposal->id)
        ->call('compose')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-no-agreement')
        ->assertSeeHtml('data-test="save-donor-report"')
        ->set('dueOn', '2026-12-01')
        ->call('save')
        ->assertHasErrors(['proposalId' => __('donor_reports.agreement_required')]);

    expect(DonorReport::query()->count())->toBe(0);
});

test('a period outside the grant is refused next to the field', function () {
    Livewire::actingAs($this->aggregatorOnly)->test('pages::donor-reports.compose')
        ->set('proposalId', (string) $this->proposal->id)
        ->set('startMonth', '2027-02')
        ->call('compose')
        ->assertHasErrors(['startMonth' => __('donor_reports.period_outside_grant')])
        ->assertDontSeeHtml('data-donor-report-snapshot');
});

test('saving lands on the saved report, read from its snapshot', function () {
    $report = drpSaved($this->aggregatorOnly);

    expect($report->version)->toBe(1)
        ->and($report->snapshot)->not->toHaveKey('finance');

    Livewire::actingAs($this->aggregatorOnly)->test('pages::donor-reports.show', ['donorReport' => $report])
        ->assertSeeText('Children enrolled')
        ->assertSeeHtml('data-report-version>v1<')
        ->assertSeeHtml('data-test="regenerate-donor-report"');
});

test('regenerate adds the next version and keeps the first', function () {
    $report = drpSaved($this->aggregatorOnly);

    Livewire::actingAs($this->aggregatorOnly)->test('pages::donor-reports.show', ['donorReport' => $report])
        ->call('openRegenerate')
        ->assertSet('showRegenerateModal', true)
        ->call('regenerate')
        ->assertRedirect(route('donor-reports.show', DonorReport::query()->where('version', 2)->sole()));

    expect(DonorReport::query()->pluck('version')->all())->toEqualCanonicalizing([1, 2]);
});

test('the saved report opens for its responsible person without money, and for nobody unrelated', function () {
    $responsibleEmployeeId = insertHrEmployee();
    $responsible = userWithRole('Team Member', $responsibleEmployeeId);
    $report = drpSaved($this->moneyHolder);
    $report->update(['responsible_employee_id' => $responsibleEmployeeId]);

    $this->actingAs($responsible)->get(route('donor-reports.show', $report))
        ->assertOk()
        ->assertSee('Children enrolled')
        ->assertDontSee('98,765.43')
        ->assertDontSee('data-snapshot-finance', false)
        ->assertDontSee('data-agreement-link', false)
        ->assertDontSee('data-test="regenerate-donor-report"', false)
        ->assertSee('data-test="submit-donor-report"', false);

    $this->actingAs(userWithRole('Team Member', insertHrEmployee()))->get(route('donor-reports.show', $report))->assertForbidden();
});

test('a regenerate call without reports.aggregate is refused', function () {
    $report = drpSaved($this->aggregatorOnly);
    $viewer = userWithRole('Financial');

    Livewire::actingAs($viewer)->test('pages::donor-reports.show', ['donorReport' => $report])->call('regenerate')->assertForbidden();

    expect(DonorReport::query()->count())->toBe(1);
});

test('the CSV export reads the snapshot with the view permission, finance only for money holders', function () {
    $report = drpSaved($this->moneyHolder);

    $holderCsv = $this->actingAs($this->moneyHolder)->get(route('donor-reports.export', $report))->assertOk()->streamedContent();
    $plainCsv = $this->actingAs($this->aggregatorOnly)->get(route('donor-reports.export', $report))->assertOk()->streamedContent();

    expect($holderCsv)->toContain('Children enrolled')->toContain('98765.43')->toContain('Finance')
        ->and($plainCsv)->toContain('Children enrolled')->not->toContain('98765')->not->toContain('Finance');

    $this->actingAs(userWithRole('Team Member', insertHrEmployee()))->get(route('donor-reports.export', $report))->assertForbidden();

    expect(Route::getRoutes()->getByName('donor-reports.export')->gatherMiddleware())->toContain('throttle:files')
        ->and(Route::getRoutes()->getByName('donor-reports.print')->gatherMiddleware())->toContain('throttle:files');
});

test('the print view is a plain page of the snapshot, finance only for money holders', function () {
    $report = drpSaved($this->moneyHolder);

    $this->actingAs($this->moneyHolder)->get(route('donor-reports.print', $report))
        ->assertOk()
        ->assertSee('data-snapshot-finance', false)
        ->assertDontSee('data-navigation-sidebar', false);

    $this->actingAs($this->aggregatorOnly)->get(route('donor-reports.print', $report))
        ->assertOk()
        ->assertSee('Children enrolled')
        ->assertDontSee('data-snapshot-finance', false)
        ->assertDontSee('98,765.43');
});

test('the saved report page runs a fixed number of queries however many centers it holds', function () {
    $count = function (int $centers): int {
        $proposal = donorReportProposal();
        $library = LibraryIndicator::factory()->create();

        for ($i = 0; $i < $centers; $i++) {
            $center = donorReportCenter($proposal);
            $target = donorReportTarget($proposal, $center, $library);
            donorReportFigures($center, '2026-01', $library, [$target->id => ['value' => 3]]);
        }

        test()->actingAs($this->aggregatorOnly);
        $report = app(SaveDonorReportSnapshot::class)->handle($proposal, [
            'period_type' => 'quarterly', 'start_month' => '2026-01', 'type' => 'narrative', 'audience' => 'donor', 'due_on' => '2026-12-01',
        ], $this->aggregatorOnly);

        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get(route('donor-reports.show', $report))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count(1); // warm the per-request lookups

    expect($count(10))->toBe($count(2));
});
