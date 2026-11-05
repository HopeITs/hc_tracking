<?php

return [
    'enter_english_title' => 'Enter the risk title in English.',
    'english_title_too_long' => 'The English title may not be longer than 255 characters.',
    'english_title_taken' => 'A library risk with this English title already exists.',
    'enter_arabic_title' => 'Enter the risk title in Arabic.',
    'arabic_title_too_long' => 'The Arabic title may not be longer than 255 characters.',
    'category_too_long' => 'The category may not be longer than 40 characters.',
    'rating_between' => 'Choose low, medium or high.',
    'in_use' => 'Risks on proposals were picked from this entry. Deactivate it instead.',
    'stale' => 'Someone else changed this entry. The list is up to date now; try again.',

    'add_entry' => 'Add risk',
    'edit_entry' => 'Edit risk',
    'dialog_description' => 'Proposals pick their risks from this library. A risk keeps the wording it was picked with, even if you edit it here later.',
    'fields' => [
        'title_ar' => 'Title in Arabic',
        'title_en' => 'Title in English',
        'category' => 'Category',
    ],
    'category_help' => 'Optional. Groups the risks in the logframe picker.',

    'empty_heading' => 'No library risks yet',
    'empty_body' => 'Proposals can only pick risks from this library. MEAL and the General Management add them here.',
    'list_label' => 'Library risks, in the order the logframe picker offers them',
    'default_ratings' => 'Likelihood: :likelihood · Severity: :severity',
    'used_by' => '{0} Not used yet|{1} Picked on 1 proposal risk|[2,*] Picked on :count proposal risks',
    'inactive' => 'Inactive',
    'position' => 'Position :position',

    'move_up_name' => 'Move “:name” up',
    'move_down_name' => 'Move “:name” down',
    'moved_to' => '“:name” is now :position of :total.',
    'deactivate_name' => 'Deactivate “:name”',
    'reactivate_name' => 'Reactivate “:name”',

    'deactivate_title' => 'Deactivate this risk?',
    'deactivate_message' => 'It stays on the proposals that have it, but is no longer offered in the logframe picker.',
    'deactivate' => 'Deactivate',
    'delete_title' => 'Delete this risk?',
    'delete_message' => 'No proposal uses it, so it is removed for good.',
    'delete' => 'Delete',
    'deactivate_instead' => 'Deactivate instead',

    'created' => 'Risk “:name” added to the library.',
    'updated' => 'Risk “:name” saved.',
    'deactivated' => 'Risk deactivated.',
    'reactivated' => 'Risk reactivated.',
    'deleted' => 'Risk deleted.',

    'already_on_result' => 'Already on this result.',
    'unavailable' => 'That risk is no longer available. Choose another one.',
    'result_type_unsupported' => 'Risks sit on outcomes and outputs only.',
    'result_not_found' => 'That result does not belong to this proposal.',
    'invalid_rating' => 'Choose low, medium or high.',
    'solution_too_long' => 'The mitigation note is too long.',
];
