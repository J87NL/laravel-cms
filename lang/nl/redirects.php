<?php

declare(strict_types=1);

return [
    'sources' => [
        'auto' => 'Automatisch',
        'manual' => 'Handmatig',
        'import' => 'Import',
    ],
    'import' => [
        'missing_columns' => 'Vul zowel het oude adres als het nieuwe adres in.',
        'invalid_status' => 'Gebruik statuscode 301 of 302.',
        'from_too_long' => 'Het oude adres mag maximaal :max tekens zijn.',
        'to_too_long' => 'Het nieuwe adres mag maximaal :max tekens zijn.',
        'invalid_target' => 'Het nieuwe adres moet met / of met http:// of https:// beginnen.',
    ],
];
