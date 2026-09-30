<?php

use Illuminate\Support\Facades\Schema;

it('indexes the columns the visibility scope, alert scanners and phase filters query', function (string $table, array $columns) {
    expect(Schema::hasIndex($table, $columns))->toBeTrue();
})->with([
    'task assignees by employee' => ['task_assignees', ['employee_id']],
    'tasks by status and due date' => ['tasks', ['status', 'due_date']],
    'proposals by status' => ['proposals', ['status']],
    'milestones by status' => ['milestones', ['status']],
]);

it('keeps the task assignee pair unique so an employee is never assigned twice', function () {
    expect(Schema::hasIndex('task_assignees', ['task_id', 'employee_id'], 'unique'))->toBeTrue();
});
