<?php

/*
 * Encryption at rest for uploaded documents (D-19, contracts §16).
 *
 * Every upload is stored encrypted with its own key, independent from APP_KEY,
 * through App\Support\StoredFiles; App\Support\FileKeyring validates these
 * values and refuses to store anything when they are missing or malformed.
 * Previous keys are decrypt-only, written "id:key,id:key", and stay listed
 * until `security:reencrypt --files` has moved every file to the current key.
 */
return [
    'encryption' => [
        'cipher' => 'aes-256-gcm',

        'key' => env('FILE_ENCRYPTION_KEY'),

        'key_id' => env('FILE_ENCRYPTION_KEY_ID') ?: 'k1',

        'previous_keys' => [
            ...array_filter(
                explode(',', (string) env('FILE_ENCRYPTION_PREVIOUS_KEYS', '')),
            ),
        ],
    ],
];
