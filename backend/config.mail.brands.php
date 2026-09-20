<?php
/**
 * Per-domain mail identity (no passwords).
 * Matched by HTTP_HOST and/or X-SMobile-Brand-Slug from the mobile/web app.
 */
return [
    'ebubeconnect.com' => [
        'slug' => 'ebubeconnect',
        'from_name' => 'EbubeConnect',
        'from_email' => 'no-reply@ebubeconnect.com',
    ],
    'smobileagent.com' => [
        'slug' => 'smobileagent',
        'from_name' => 'SMobile Agent',
        'from_email' => 'no-reply@smobileagent.com',
    ],
    'smobilestarlot.com' => [
        'slug' => 'smobilestarlot',
        'from_name' => 'SMobile Starlot',
        'from_email' => 'no-reply@smobilestarlot.com',
    ],
    'smobileunich.com' => [
        'slug' => 'smobileunich',
        'from_name' => 'SMobile Unich',
        'from_email' => 'no-reply@smobileunich.com',
    ],
    'smobileaccesslink.com' => [
        'slug' => 'smobileaccesslink',
        'from_name' => 'SMobile Accesslink',
        'from_email' => 'no-reply@smobileaccesslink.com',
    ],
    'smobileprochris.com' => [
        'slug' => 'smobileprochris',
        'from_name' => 'SMobile Prochris',
        'from_email' => 'no-reply@smobileprochris.com',
    ],
    'smobileinternetsolutions.com' => [
        'slug' => 'smobileinternetsolutions',
        'from_name' => 'Smobile INT',
        'from_email' => 'no-reply@smobileinternetsolutions.com',
    ],
];
