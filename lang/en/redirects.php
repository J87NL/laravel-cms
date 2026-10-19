<?php

declare(strict_types=1);

return [
    'sources' => [
        'auto' => 'Automatic',
        'manual' => 'Manual',
        'import' => 'Import',
    ],
    'import' => [
        'missing_columns' => 'Fill in both the old and the new address.',
        'invalid_status' => 'Use status code 301 or 302.',
        'from_too_long' => 'The old address may be at most :max characters.',
        'to_too_long' => 'The new address may be at most :max characters.',
        'invalid_target' => 'The new address must start with / or with http:// or https://.',
    ],
];
