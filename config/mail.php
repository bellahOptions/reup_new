<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send any email
    | messages sent by your application. Alternative mailers may be setup
    | and used as needed; however, this mailer will be used by default.
    |
    */

    'default' => env('MAIL_MAILER', 'smtp'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers to be used while
    | sending an e-mail. You will specify which one you are using for your
    | mailers below. You are free to add additional mailers as required.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses",
    |            "postmark", "log", "array", "failover"
    |
    */

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST', 'smtp.mailgun.org'),
            'port' => env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'auth_mode' => null,
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'mailgun' => [
            'transport' => 'mailgun',
        ],

        'postmark' => [
            'transport' => 'postmark',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -t -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | Every email the application sends is from this name and address.
    |
    | ## Why this does not simply read APP_NAME
    |
    | It was previously `MAIL_FROM_NAME="${APP_NAME}"`. Dotenv resolves `${...}`
    | against the *process* environment before it reads .env, and Laravel's own
    | default export is APP_NAME=Laravel — so a shell that had it exported
    | branded every customer email from "Laravel". On this machine
    | MAIL_FROM_NAME is itself exported as the literal `${APP_NAME}`, which
    | reached customers verbatim.
    |
    | APP_NAME is not used as the fallback either, because it carries the same
    | shell override. The literal below is the actual wordmark; override it with
    | MAIL_FROM_NAME when a different sender name is genuinely wanted. An
    | unexpanded `${...}` placeholder is treated as "not set" so a leftover shell
    | variable can never put a template token in a customer's inbox.
    |
    */

    'from' => [
        'address' => trim((string) env('MAIL_FROM_ADDRESS', '')) ?: 'no-reply@reup.com.ng',
        'name' => (function () {
            $configured = trim((string) env('MAIL_FROM_NAME', ''));

            // Reject unexpanded ${VAR} / $VAR, and the literal string "null"
            // that a shell writes when a variable is unset.
            if ($configured === '' || str_contains($configured, '${') || strcasecmp($configured, 'null') === 0) {
                return 'ReUp';
            }

            return $configured;
        })(),
    ],

    /*
    |--------------------------------------------------------------------------
    | Markdown Mail Settings
    |--------------------------------------------------------------------------
    |
    | If you are using Markdown based email rendering, you may configure your
    | theme and component paths here, allowing you to customize the design
    | of the emails. Or, you may simply stick with the Laravel defaults!
    |
    */

    'markdown' => [
        'theme' => 'default',

        'paths' => [
            resource_path('views/vendor/mail'),
        ],
    ],

];
