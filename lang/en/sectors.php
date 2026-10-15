<?php

return [
    'enter_arabic_name' => 'Enter the sector name in Arabic.',
    'arabic_name_too_long' => 'The Arabic name may not be longer than 255 characters.',
    'english_name_too_long' => 'The English name may not be longer than 255 characters.',
    'choose_at_least_one' => 'Choose at least one sector.',
    'unavailable' => 'That sector is no longer available. Choose another one.',
    'in_use' => 'This sector is in use. Deactivate it instead.',
    'stale' => 'Someone else changed this sector. The list is up to date now; try again.',

    'add_sector' => 'Add sector',
    'edit_sector' => 'Edit sector',
    'dialog_description' => 'Sectors describe what a project covers. Every new project needs at least one.',
    'fields' => [
        'name_ar' => 'Name in Arabic',
        'name_en' => 'Name in English',
    ],
    'name_en_help' => 'Optional. Without it, the Arabic name shows in English too.',

    'empty_heading' => 'No sectors yet',
    'empty_body' => 'Sectors describe what a project covers. MEAL and the General Management add them here.',
    'read_only_note' => 'Only MEAL and the General Management change this list.',
    'list_label' => 'Sectors, in the order the project form offers them',
    'used_by' => '{0} Not used yet|{1} Used by 1 project|[2,*] Used by :count projects',
    'inactive' => 'Inactive',
    'position' => 'Position :position',

    'move_up_name' => 'Move “:name” up',
    'move_down_name' => 'Move “:name” down',
    'moved_to' => '“:name” is now :position of :total.',
    'deactivate_name' => 'Deactivate “:name”',
    'reactivate_name' => 'Reactivate “:name”',

    'deactivate_title' => 'Deactivate this sector?',
    'deactivate_message' => 'It stays on the projects that have it, but is no longer offered for new projects.',
    'deactivate' => 'Deactivate',
    'delete_title' => 'Delete this sector?',
    'delete_message' => 'No project uses it, so it is removed for good.',
    'delete' => 'Delete',
    'deactivate_instead' => 'Deactivate instead',

    'created' => 'Sector “:name” added.',
    'updated' => 'Sector “:name” saved.',
    'deactivated' => 'Sector deactivated.',
    'reactivated' => 'Sector reactivated.',
    'deleted' => 'Sector deleted.',

    'sectors' => 'Sectors',
    'choose_sectors' => 'Choose sectors',
    'picker_help' => 'What the project covers. Choose at least one.',
    'no_sectors_available' => 'No active sector yet. Ask MEAL to add one.',
    'all_sectors' => 'All sectors',
    'none' => 'No sector',
    'list_separator' => ', ',
];
