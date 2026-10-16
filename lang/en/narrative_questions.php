<?php

return [
    'enter_arabic_text' => 'Enter the question in Arabic.',
    'arabic_text_too_long' => 'The Arabic question may not be longer than 1000 characters.',
    'english_text_too_long' => 'The English question may not be longer than 1000 characters.',
    'choose_frequency' => 'Choose when the question is asked.',
    'in_use' => 'This question is used by a project template. Deactivate it instead.',
    'stale' => 'Someone else changed this question. The list is up to date now; try again.',

    'add_question' => 'Add question',
    'edit_question' => 'Edit question',
    'dialog_description' => 'Every new project report template copies the active questions. Templates already made keep their own copy.',
    'fields' => [
        'text_ar' => 'Question in Arabic',
        'text_en' => 'Question in English',
        'frequency' => 'Asked',
    ],
    'text_en_help' => 'Optional. Without it, the Arabic question shows in English too.',
    'frequency_help' => [
        'monthly' => 'Asked in every monthly report.',
        'quarter_end' => 'Asked only in the March, June, September and December reports.',
    ],

    'filter_label' => 'Asked',
    'all_frequencies' => 'All',

    'empty_heading' => 'No questions yet',
    'empty_body' => 'Project teams answer these questions in their monthly reports. MEAL adds them here.',
    'empty_filtered_heading' => 'No questions asked this often',
    'empty_filtered_body' => 'Choose “All” to see the whole bank, or add a question.',
    'list_label' => ':frequency questions, in the order reports ask them',
    'inactive' => 'Inactive',
    'position' => 'Position :position',

    'move_up_name' => 'Move “:text” up',
    'move_down_name' => 'Move “:text” down',
    'moved_to' => '“:text” is now :position of :total (:frequency).',
    'deactivate_name' => 'Deactivate “:text”',
    'reactivate_name' => 'Reactivate “:text”',

    'deactivate_title' => 'Deactivate this question?',
    'deactivate_message' => 'New project templates stop copying it. Templates that already have it keep it.',
    'deactivate' => 'Deactivate',
    'delete_title' => 'Delete this question?',
    'delete_message' => 'No project template uses it, so it is removed for good.',
    'delete' => 'Delete',
    'deactivate_instead' => 'Deactivate instead',

    'created' => 'Question added.',
    'updated' => 'Question saved.',
    'deactivated' => 'Question deactivated.',
    'reactivated' => 'Question reactivated.',
    'deleted' => 'Question deleted.',
];
