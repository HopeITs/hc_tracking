<?php

return [
    'future_month' => 'You cannot open a report for a future month.',
    'outside_period' => 'The month is outside the project period.',
    'submit_previous_first' => 'Submit the :month report first.',
    'submit_too_early' => 'You can submit this report from the last working day of its month.',
    'value_required' => 'Enter a value for every indicator (zero is allowed).',
    'zero_denominator' => 'The denominator cannot be zero; leave the line empty if nothing was measured this month.',
    'above_fixed_denominator' => 'The count is greater than the project\'s beneficiaries in the proposal (:count).',
    'disabled_above_gender' => 'The number of persons with disabilities is greater than the number of males/females.',
    'not_measured' => 'Not measured this month',
    'locked' => 'The report is submitted and locked.',
    'stale' => 'The report status just changed. Reload the page.',
    'console' => [
        'backfill_row' => 'Project :project: :created months created, :skipped already there, :lines lines built.',
        'prepare_row' => 'Project :project: :lines lines built for this month.',
        'dry_run' => 'Dry run: nothing was written.',
        'invalid_from' => 'Use --from=YYYY-MM, for example --from=2026-10.',
    ],
];
