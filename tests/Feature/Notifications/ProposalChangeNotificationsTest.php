<?php

use App\Actions\Proposals\RejectProposalChange;
use App\Actions\Proposals\RequestProposalChange;
use App\Actions\Proposals\ReviewProposalChange;
use App\Models\Proposal;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Alerts\NotificationSettings;
use App\Support\EmployeeProfile;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
| Y-09 (contracts §15.2): a change request reaches both review slot holders
| and the deciders other than the requester; the decision reaches the
| requester.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $financeEmployee = insertHrEmployee(firstNameEn: 'Fadi', lastNameEn: 'Finance');
    $mealEmployee = insertHrEmployee(firstNameEn: 'Maha', lastNameEn: 'Manager');
    $financePosition = insertHrPosition(insertHrDepartment('Finance'), 'Finance Manager');
    $mealPosition = insertHrPosition(insertHrDepartment('Monitoring & Evaluation'), 'MEAL Manager');
    insertHrContract($financeEmployee, $financePosition);
    insertHrContract($mealEmployee, $mealPosition);

    app(NotificationSettings::class)->save(['proposal_default_reviewers' => [
        'meal_manager' => $mealPosition, 'finance_manager' => $financePosition, 'fundraising_manager' => null, 'ceo' => null,
    ]]);
    EmployeeProfile::forgetRememberedLookup();

    $this->finance = userWithRole('Financial', $financeEmployee);
    $this->meal = userWithRole('MEAL', $mealEmployee);
    $this->decider = userWithRole('Public Relations Manager');
    $this->requester = userWithRole('Public Relations');
    $this->proposal = Proposal::factory()->running()->create(['name' => 'Water for Aleppo']);
});

/**
 * @return list<string>
 */
function changeKeysFor(User $user): array
{
    return UserNotification::query()->forUser($user)->get()->pluck('data.key')->all();
}

test('a change request reaches both reviewers and the PR Manager, not the requester', function () {
    $this->actingAs($this->requester);

    app(RequestProposalChange::class)->handle($this->proposal, ['name' => 'Water for Aleppo and Idlib'], 'The donor widened the grant to Idlib.');

    $key = 'notifications.events.proposal.change_requested';

    expect(changeKeysFor($this->finance))->toContain($key)
        ->and(changeKeysFor($this->meal))->toContain($key)
        ->and(changeKeysFor($this->decider))->toContain($key)
        ->and(changeKeysFor($this->requester))->not->toContain($key);
});

test('the PR Manager hears "ready" only after the second review, and watchers hear nothing', function () {
    $this->actingAs($this->requester);
    $request = app(RequestProposalChange::class)->handle($this->proposal, ['name' => 'Water for Aleppo and Idlib'], 'The donor widened the grant to Idlib.');
    $key = 'notifications.events.proposal.change_reviewed';
    $watcher = userWithRole('Financial');

    $this->actingAs($this->finance);
    app(ReviewProposalChange::class)->handle($request, 'finance_manager', 'no_objection');

    expect(changeKeysFor($this->decider))->not->toContain($key)
        ->and(changeKeysFor($watcher))->not->toContain($key);

    $this->actingAs($this->meal);
    app(ReviewProposalChange::class)->handle($request, 'meal_manager', 'remarks', 'Check the Idlib targets first.');

    expect(changeKeysFor($this->decider))->toContain($key)
        ->and(changeKeysFor($watcher))->not->toContain($key)
        ->and(changeKeysFor($watcher))->not->toContain('notifications.events.proposal.change_requested');
});

test('the decision reaches the requester', function () {
    $this->actingAs($this->requester);
    $request = app(RequestProposalChange::class)->handle($this->proposal, ['name' => 'Water for Aleppo and Idlib'], 'The donor widened the grant to Idlib.');

    $this->actingAs($this->decider);
    app(RejectProposalChange::class)->handle($request, 'The agreement was not amended yet.');

    expect(changeKeysFor($this->requester))->toContain('notifications.events.proposal.change_rejected');
});
