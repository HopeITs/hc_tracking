<?php

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\User;
use App\Notifications\QuickActions;
use Livewire\Livewire;

beforeEach(function () {
    $this->author = userWithRole('Media Team');
    $this->draft = Report::factory()->create(['user_id' => $this->author->id]);
    $this->show = fn (User $user, ?Report $report = null) => Livewire::actingAs($user)->test('pages::reports.show', ['report' => $report ?? $this->draft]);
    $this->index = fn (User $user) => Livewire::actingAs($user)->test('pages::reports.index');
});

test('the author edits and submits their own draft', function () {
    ($this->index)($this->author)->call('openEditModal', $this->draft->id)->assertOk()->assertSet('editingReportId', $this->draft->id);
    ($this->show)($this->author)->call('submitForReview')->assertOk();

    expect($this->draft->fresh()->status)->toBe(ReportStatus::Reviewed);
});

test('a colleague with the same role cannot edit or submit someone else\'s draft', function () {
    $colleague = userWithRole('Media Team');

    ($this->index)($colleague)->call('openEditModal', $this->draft->id)->assertForbidden();
    ($this->show)($colleague)->call('submitForReview')->assertForbidden();

    expect($this->draft->fresh()->status)->toBe(ReportStatus::Draft);
});

test('MEAL approves another author\'s reviewed report but never their own', function () {
    $this->draft->update(['status' => ReportStatus::Reviewed, 'submit_date' => today()]);
    $meal = userWithRole('MEAL');

    ($this->show)($meal)->call('approve')->assertOk();
    expect($this->draft->fresh()->status)->toBe(ReportStatus::Approved);

    $own = Report::factory()->create(['user_id' => $meal->id, 'status' => ReportStatus::Reviewed, 'submit_date' => today()]);
    ($this->show)($meal, $own)->call('approve')->assertForbidden();

    expect($own->fresh()->status)->toBe(ReportStatus::Reviewed);
});

test('the author pulls back a report still under review; a Team Member cannot', function () {
    $this->draft->update(['status' => ReportStatus::Reviewed, 'submit_date' => today()]);

    ($this->show)(userWithRole('Team Member'))->call('returnToDraft')->assertForbidden();
    ($this->show)($this->author)->call('returnToDraft')->assertOk();

    expect($this->draft->fresh()->status)->toBe(ReportStatus::Draft);
});

test('the author cannot pull back a report once it is approved', function () {
    $this->draft->update(['status' => ReportStatus::Approved, 'submit_date' => today()]);

    ($this->show)($this->author)->call('returnToDraft')->assertForbidden();

    expect($this->draft->fresh()->status)->toBe(ReportStatus::Approved);
});

test('only mark-seen holders acknowledge for MEAL', function () {
    ($this->show)($this->author)->call('markSeenByMeal')->assertForbidden();
    ($this->show)(userWithRole('MEAL'))->call('markSeenByMeal')->assertOk();

    expect($this->draft->fresh()->is_seen_by_meal)->toBeTrue();
});

test('reports.delete removes another author\'s draft; a colleague without it cannot', function () {
    ($this->index)(userWithRole('Media Team'))->call('confirmDeleteReport', $this->draft->id)->assertForbidden();

    ($this->index)(userWithRole('Public Relations Manager'))->call('confirmDeleteReport', $this->draft->id)->call('deleteReport')->assertOk();

    expect(Report::query()->find($this->draft->id))->toBeNull();
});

test('nobody deletes an approved report', function () {
    $approved = Report::factory()->create(['user_id' => $this->author->id, 'status' => ReportStatus::Approved]);

    ($this->index)(userWithRole('General Manager'))->call('confirmDeleteReport', $approved->id)->call('deleteReport');

    expect(Report::query()->find($approved->id))->not->toBeNull();
});

test('a Team Member cannot write reports', function () {
    ($this->index)(userWithRole('Team Member'))->call('openCreateModal')->assertForbidden();
});

test('the report page offers each workflow button only to whoever may use it', function () {
    ($this->show)($this->author)
        ->assertSeeHtml('data-test="edit-report-button"')
        ->assertSeeHtml('data-test="submit-for-review-button"')
        ->assertSeeHtml('data-test="create-file-button"');

    ($this->show)(userWithRole('Media Team'))
        ->assertDontSeeHtml('data-test="edit-report-button"')
        ->assertDontSeeHtml('data-test="submit-for-review-button"')
        ->assertDontSeeHtml('data-test="create-file-button"')
        ->assertDontSeeHtml('data-test="mark-seen-button"');

    $this->draft->update(['status' => ReportStatus::Reviewed, 'submit_date' => today()]);

    ($this->show)(userWithRole('MEAL'))->assertSeeHtml('data-test="approve-button"')->assertSeeHtml('data-test="mark-seen-button"');
    ($this->show)($this->author)->assertDontSeeHtml('data-test="approve-button"')->assertSeeHtml('data-test="return-to-draft-button"');
});

test('quick actions follow the same rules', function () {
    $toAuthor = app(QuickActions::class)->forSubject('report', $this->draft->id, $this->author);
    $toColleague = app(QuickActions::class)->forSubject('report', $this->draft->id, userWithRole('Media Team'));

    expect(array_column($toAuthor, 'type'))->toBe(['report.mark_reviewed'])
        ->and($toColleague)->toBe([]);

    $this->draft->update(['status' => ReportStatus::Reviewed]);

    expect(array_column(app(QuickActions::class)->forSubject('report', $this->draft->id, userWithRole('MEAL')), 'type'))->toBe(['report.approve'])
        ->and(app(QuickActions::class)->forSubject('report', $this->draft->id, $this->author))->toBe([]);
});
