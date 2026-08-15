<?php

declare(strict_types=1);

return [
    'default' => env('MAIL_MAILER', 'smtp'),

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST', 'mailhog'),
            'port' => (int) env('MAIL_PORT', 1025),
            'encryption' => env('MAIL_ENCRYPTION'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],
        'log' => ['transport' => 'log', 'channel' => env('MAIL_LOG_CHANNEL')],
        'array' => ['transport' => 'array'],
        'failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']],
    ],

    'from' => [
        /*
         * On the organisation's own domain, which is what makes delivery
         * possible at all: a relay will only send as a domain you control and
         * have verified with it, and SPF and DKIM are published against that
         * domain rather than against the mailbox. A consumer address such as
         * gmail.com fails both, whoever owns it.
         */
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@nelleeph.com'),
        'name' => env('MAIL_FROM_NAME', 'Fuel Intelligence Platform'),
    ],
];
