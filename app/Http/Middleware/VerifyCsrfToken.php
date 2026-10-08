<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * URIs excluded from CSRF verification.
     *
     * Only server-to-server callbacks belong here — they carry no session
     * cookie by definition. Every exemption below is authenticated by a
     * provider signature (see PaystackController::webhook) rather than by a
     * token. Nothing user-facing is exempt.
     *
     * @var array<int, string>
     */
    protected $except = [
        'paystack/webhook',
        'bachs/webhook',
        'pairgate/webhook',
    ];
}
