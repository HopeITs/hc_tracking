<?php

return [
    'enter_arabic_text' => 'Enter the question in Arabic.',
    'arabic_text_too_long' => 'The Arabic question may not be longer than 1000 characters.',
    'english_text_too_long' => 'The English question may not be longer than 1000 characters.',
    'choose_frequency' => 'Choose when the question is asked.',
    'in_use' => 'This question is used by a project template. Switch it off instead.',
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

    'search' => 'Search questions',
    'search_placeholder' => 'Words in the question',
    'no_match' => 'No questions match',

    'empty_heading' => 'No questions yet',
    'empty_body' => 'Project teams answer these questions in their monthly reports. MEAL adds them here.',
    'list_label' => ':frequency questions, in the order reports ask them',
    'status_on' => 'On',
    'status_off' => 'Switched off',
    'focus_showing' => 'Showing :shown of :total questions.',
    'focus_show_all' => 'Show all',
    'focus_press_again' => 'Press again to show all questions',
    'position' => 'Position :position',

    'move_up_name' => 'Move “:text” up',
    'move_down_name' => 'Move “:text” down',
    'moved_to' => '“:text” is now :position of :total (:frequency).',
    'deactivate_name' => 'Switch off “:text”',
    'more_actions_for_name' => 'More actions for “:text”',
    'clear_to_reorder' => 'Clear the search or filter to change the order.',
    'reactivate_name' => 'Switch on “:text”',

    'deactivate_title' => 'Switch off “:name”?',
    'deactivate_message' => 'New project templates stop copying it. Templates that already have it keep it.',
    'deactivate' => 'Switch off',
    'reactivate' => 'Switch on',
    'edit' => 'Edit',
    'delete_title' => 'Delete “:name”?',
    'delete_message' => 'No project template uses it, so it is removed for good.',
    'delete' => 'Delete',
    'deactivate_instead' => 'Switch off instead',

    'created' => 'Question added.',
    'updated' => 'Question saved.',
    'deactivated' => 'Question switched off.',
    'reactivated' => 'Question switched on.',
    'deleted' => 'Question deleted.',
];
