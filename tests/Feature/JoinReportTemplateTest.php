<?php

use App\Actions\ReportTemplates\ConfirmReportTemplate;
use App\Actions\ReportTemplates\JoinReportTemplate;
use App\Models\LibraryIndicator;
use App\Models\ReportTemplate;
use App\Models\ReportTemplatePlacement;
use App\Support\Reporting\ReportPlace;
use App\Support\ReportTemplatePrefiller;
use App\Support\ReportTemplateReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->fixture = placementFixture();
    $this->actingAs(mealManager());
    $prefiller = app(ReportTemplatePrefiller::class);
    $prefiller->prefillForProposal($this->fixture['proposals'][0]);
    $prefiller->prefillForProposal($this->fixture['proposals'][1]);
    $this->template = ReportTemplate::query()->sole();
    $this->proposal = $this->fixture['proposals'][1];
});

test('joining adds the shared target once and prefill retries preserve the selection', function () {
    $extra = donorReportTarget($this->proposal, $this->fixture['project'], LibraryIndicator::factory()->create(['frequency' => 'monthly']));
    $leftOut = donorReportTarget($this->proposal, $this->fixture['project'], LibraryIndicator::factory()->create(['frequency' => 'monthly']));

    app(JoinReportTemplate::class)->handle($this->proposal, $this->template, [$extra->id]);
    app(ReportTemplatePrefiller::class)->prefillForProposal($this->proposal);

    expect($this->template->lines()->pluck('plan_indicator_id')->sort()->values()->all())
        ->toBe(collect([$this->fixture['targets'][0]->id, $this->fixture['targets'][1]->id, $extra->id])->sort()->values()->all())
        ->and($this->template->lines()->where('plan_indicator_id', $leftOut->id)->exists())->toBeFalse()
        ->and(collect(app(ReportTemplateReader::class)->tabItems($this->template)['notReportedTargets'])->pluck('id')->all())->toBe([$leftOut->id])
        ->and($this->template->proposals()->count())->toBe(2)
        ->and(ReportTemplatePlacement::query()->where('proposal_id', $this->proposal->id)->sole()->status->value)->toBe('joined');
});

test('a decided placement rejects another decision without adding rows', function () {
    $action = app(JoinReportTemplate::class);
    $action->handle($this->proposal, $this->template, []);

    expect(fn () => $action->handle($this->proposal, $this->template, []))->toThrow(ValidationException::class)
        ->and($this->template->lines()->count())->toBe(2);
});

test('targets from another proposal are refused atomically', function () {
    expect(fn () => app(JoinReportTemplate::class)->handle($this->proposal, $this->template, [$this->fixture['targets'][0]->id]))->toThrow(ValidationException::class)
        ->and($this->template->proposals()->count())->toBe(1);
});

test('a template at another place is refused', function () {
    $other = ReportTemplate::factory()->create();

    expect(fn () => app(JoinReportTemplate::class)->handle($this->proposal, $other, []))->toThrow(ModelNotFoundException::class);
});

test('joining is refused without management permission or coverage', function (string $role) {
    $this->actingAs(userWithRole($role, insertHrEmployee()));

    expect(fn () => app(JoinReportTemplate::class)->handle($this->proposal, $this->template, []))->toThrow(AuthorizationException::class);
})->with(['Team Member', 'MEAL']);

test('a stale expected status is refused', function () {
    expect(fn () => app(JoinReportTemplate::class)->handle($this->proposal, $this->template, [], 'joined'))->toThrow(ValidationException::class);
});

test('zero chosen targets needs an overlapping indicator', function () {
    $this->fixture['targets'][1]->indicator->update(['library_indicator_id' => LibraryIndicator::factory()->create()->id]);

    expect(fn () => app(JoinReportTemplate::class)->handle($this->proposal, $this->template, []))->toThrow(ValidationException::class);
});

test('field-work proposals join their matching city-wide template', function () {
    $fixture = placementFixture(cityWide: true);
    app(ReportTemplatePrefiller::class)->prefillForProposal($fixture['proposals'][0]);
    app(ReportTemplatePrefiller::class)->prefillForProposal($fixture['proposals'][1]);
    $template = ReportTemplate::query()->atPlace(new ReportPlace($fixture['project']->city_id, $fixture['program']->id, null, true))->sole();

    app(JoinReportTemplate::class)->handle($fixture['proposals'][1], $template, []);

    expect($template->proposals()->count())->toBe(2);
});

test('joining a later proposal generates its extra months only after confirming its lines', function () {
    app(ConfirmReportTemplate::class)->handle($this->template, $this->template->lines()->pluck('id')->all());
    $this->proposal->forceFill(['actual_date_end' => '2027-02-28'])->save();

    app(JoinReportTemplate::class)->handle($this->proposal, $this->template, []);
    expect($this->template->monthlyReports()->where('period', '2027-02-01')->exists())->toBeFalse();
    app(ConfirmReportTemplate::class)->handle($this->template, $this->template->lines()->where('status', 'pending')->pluck('id')->all());

    expect($this->template->monthlyReports()->where('period', '2027-02-01')->count())->toBe(1);
});

test('an unset center cannot be joined even if a pending placement is forged', function () {
    $this->template->forceFill(['center_id' => null, 'city_wide' => false])->save();
    $placement = ReportTemplatePlacement::query()->where('proposal_id', $this->proposal->id)->sole();
    $placement->forceFill(['center_id' => null, 'city_wide' => false])->save();

    expect(fn () => app(JoinReportTemplate::class)->handle($this->proposal, $this->template, []))
        ->toThrow(ValidationException::class);
});

test('a proposal that is no longer donor-approved cannot join', function () {
    $this->proposal->forceFill(['status' => 'proposal draft'])->save();

    expect(fn () => app(JoinReportTemplate::class)->handle($this->proposal, $this->template, []))
        ->toThrow(ValidationException::class);
});

test('a joined proposal project stays approved until its own pending lines are confirmed', function () {
    // Y-17: the place is the row, and a joining proposal's own place stays
    // approved until its own pending lines are confirmed.
    app(ConfirmReportTemplate::class)->handle($this->template, $this->template->lines()->pluck('id')->all());
    $joining = $this->proposal->locations()->sole();
    $joining->forceFill(['status' => 'approved'])->save();

    app(JoinReportTemplate::class)->handle($this->proposal, $this->template, []);
    expect($joining->fresh()->status->value)->toBe('approved');
    app(ConfirmReportTemplate::class)->handle($this->template, $this->template->lines()->where('status', 'pending')->pluck('id')->all());

    expect($joining->fresh()->status->value)->toBe('active');
});

test('prefill previews preserve pending choices and joined selections', function () {
    $prefiller = app(ReportTemplatePrefiller::class);
    $leftOut = donorReportTarget($this->proposal, $this->fixture['project'], LibraryIndicator::factory()->create(['frequency' => 'monthly']));

    $pending = $prefiller->previewProject($this->fixture['project']->id);
    expect($pending['createsTemplate'])->toBeFalse()
        ->and($pending['newLinePlanIndicatorIds'])->toBe([]);

    app(JoinReportTemplate::class)->handle($this->proposal, $this->template, []);
    $joined = $prefiller->previewProject($this->fixture['project']->id);

    expect($joined['createsTemplate'])->toBeFalse()
        ->and($joined['newLinePlanIndicatorIds'])->toBe([])
        ->and($joined['existingLineCount'])->toBe(2)
        ->and($this->template->lines()->where('plan_indicator_id', $leftOut->id)->exists())->toBeFalse();
});

test('city-wide placement cannot join a centered template in the same city and program', function () {
    $fixture = placementFixture(cityWide: true);
    $prefiller = app(ReportTemplatePrefiller::class);
    $prefiller->prefillForProposal($fixture['proposals'][0]);
    $prefiller->prefillForProposal($fixture['proposals'][1]);
    $centered = ReportTemplate::factory()->create([
        'city_id' => $fixture['project']->city_id,
        'program_id' => $fixture['program']->id,
        'center_id' => insertHrCenter($fixture['project']->city_id),
        'city_wide' => false,
    ]);

    expect(fn () => app(JoinReportTemplate::class)->handle($fixture['proposals'][1], $centered, []))
        ->toThrow(ModelNotFoundException::class);
});

test('prefill refreshes a selected pending fixed denominator without adding omitted targets', function () {
    $fixedLibrary = LibraryIndicator::factory()->create([
        'frequency' => 'monthly', 'measure' => 'percentage', 'unit' => null, 'denominator_basis' => 'fixed',
    ]);
    $selected = donorReportTarget($this->proposal, $this->fixture['project'], $fixedLibrary);
    $leftOut = donorReportTarget($this->proposal, $this->fixture['project'], LibraryIndicator::factory()->create(['frequency' => 'monthly']));
    $this->proposal->projects()->updateExistingPivot($this->fixture['project']->id, ['target_beneficiaries' => null]);
    app(JoinReportTemplate::class)->handle($this->proposal, $this->template, [$selected->id]);
    $line = $this->template->lines()->where('plan_indicator_id', $selected->id)->sole();
    expect($line->fixed_denominator)->toBeNull();
    $this->proposal->projects()->updateExistingPivot($this->fixture['project']->id, ['target_beneficiaries' => 250]);

    $prefiller = app(ReportTemplatePrefiller::class);
    expect($prefiller->previewProject($this->fixture['project']->id)['refreshedLineIds'])->toBe([$line->id]);
    $prefiller->prefillForProposal($this->proposal);

    expect($line->fresh()->fixed_denominator)->toBe(250)
        ->and($this->template->lines()->where('plan_indicator_id', $leftOut->id)->exists())->toBeFalse();
});
