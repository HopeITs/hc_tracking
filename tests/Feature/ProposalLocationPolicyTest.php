<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\Program;
use App\Models\ProposalLocation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;

/**
 * Y-16 slice 2 (D-93): the abilities that gated a project now gate its
 * location row, rule for rule — the owner-rejected block, `projects.update`
 * or answering for the row, `report-templates.manage` to activate.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->program = Program::factory()->create();
    $this->row = ProposalLocation::factory()->create([
        'program_id' => $this->program->id,
        'responsible_employee_id' => insertHrEmployee(),
    ]);
});

/**
 * A rejected owner assignment on the row.
 */
function rejectRowOwner(ProposalLocation $row): Assignment
{
    return Assignment::factory()->create([
        'assignable_type' => 'proposal_location',
        'assignable_id' => $row->id,
        'field' => 'responsible_employee_id',
        'employee_id' => $row->responsible_employee_id,
        'status' => AssignmentStatus::Rejected,
    ]);
}

test('projects.view holders may see any row', function () {
    $viewer = userWithRole('Team Member');

    expect(Gate::forUser($viewer)->allows('viewAny', ProposalLocation::class))->toBe($viewer->can('projects.view'))
        ->and(Gate::forUser($viewer)->allows('view', $this->row))->toBe($viewer->can('projects.view'));
});

test('projects.update holders may update a row', function () {
    $manager = userWithRole('General Manager');

    expect($manager->can('projects.update'))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('update', $this->row))->toBeTrue();
});

test('the responsible employee may update their own row without projects.update', function () {
    $owner = User::factory()->create(['employee_id' => $this->row->responsible_employee_id]);

    expect($owner->can('projects.update'))->toBeFalse()
        ->and(in_array($this->row->id, $owner->ownedLocationIds(), true))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $this->row))->toBeTrue();
});

test('someone who answers for neither the row nor projects may not update it', function () {
    $stranger = User::factory()->create(['employee_id' => insertHrEmployee()]);

    expect(Gate::forUser($stranger)->allows('update', $this->row))->toBeFalse();
});

test('a rejected owner blocks update and reassign for everyone', function () {
    rejectRowOwner($this->row);
    $this->row->refresh()->load('ownerAssignment');
    $manager = userWithRole('General Manager');

    expect($this->row->isOwnerRejected())->toBeTrue()
        ->and(Gate::forUser($manager)->allows('update', $this->row))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('reassign', $this->row))->toBeFalse();
});

test('reassign stays with projects.update, never the row owner', function () {
    $owner = User::factory()->create(['employee_id' => $this->row->responsible_employee_id]);
    $manager = userWithRole('General Manager');

    expect(Gate::forUser($owner)->allows('reassign', $this->row))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('reassign', $this->row))->toBeTrue();
});

test('reopening a closed row as a draft follows proposals.update', function () {
    $pr = userWithRole('Public Relations');
    $teamMember = userWithRole('Team Member');

    expect(Gate::forUser($pr)->allows('reopenAsDraft', $this->row))->toBe($pr->can('proposals.update'))
        ->and(Gate::forUser($teamMember)->allows('reopenAsDraft', $this->row))->toBe($teamMember->can('proposals.update'));
});

test('activating a row is MEAL confirming its template', function () {
    $meal = mealManager();
    $manager = userWithRole('General Manager');

    expect(Gate::forUser($meal)->allows('activate', $this->row))->toBe($meal->can('report-templates.manage'))
        ->and(Gate::forUser($manager)->allows('activate', $this->row))->toBe($manager->can('report-templates.manage'));
});

test('archiving a row follows projects.delete, and a row is never restored or force deleted', function () {
    $manager = userWithRole('General Manager');

    expect(Gate::forUser($manager)->allows('delete', $this->row))->toBe($manager->can('projects.delete'))
        ->and(Gate::forUser($manager)->allows('restore', $this->row))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('forceDelete', $this->row))->toBeFalse();
});
