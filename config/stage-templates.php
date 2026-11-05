<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Stage task templates (D-63, W-12)
|--------------------------------------------------------------------------
|
| The starting templates the seeder writes into
| `proposal_stage_task_templates`. Departments are named by their HR
| `departments.code`, a stable key, never by a name copied into our tables:
| the seeder resolves each code against HR once, and logs and skips a code
| HR does not have. «Media» in D-63 is HR's Communication department.
|
| After the seeder has run, the PR Manager edits the templates on
| /admin/stage-task-templates. This file only decides what a fresh database
| starts with.
|
*/

return [

    'departments' => [
        'media' => 'Comm',
        'finance' => 'Fin',
        'meal' => 'MEAL',
    ],

    /*
     | status · department slug · kind · creates · titles · due offset in days
     | from the effective date of the move. `sort_order` is the order here.
     */
    'templates' => [
        [
            'status' => 'proposal review',
            'department' => 'media',
            'kind' => 'media_deliverable',
            'creates' => 'task',
            'title_en' => 'Media review of the proposal',
            'title_ar' => 'مراجعة إعلامية للمقترح',
            'due_offset_days' => 7,
        ],
        [
            'status' => 'proposal review',
            'department' => 'finance',
            'kind' => 'financial',
            'creates' => 'task',
            'title_en' => 'Finance review of the proposal',
            'title_ar' => 'مراجعة مالية للمقترح',
            'due_offset_days' => 7,
        ],
        [
            'status' => 'proposal review',
            'department' => 'meal',
            'kind' => 'general',
            'creates' => 'task',
            'title_en' => 'MEAL review of the proposal',
            'title_ar' => 'مراجعة المتابعة والتقييم للمقترح',
            'due_offset_days' => 7,
        ],
        [
            'status' => 'approved',
            'department' => 'meal',
            'kind' => 'general',
            'creates' => 'final_report_milestone',
            'title_en' => 'Prepare the final report',
            'title_ar' => 'تجهيز التقرير النهائي',
            'due_offset_days' => 30,
        ],
    ],

];
