<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * Flashed input is written to the session and rendered back into the form.
     * The framework only excluded passwords, so a failed purchase (or a failed
     * PIN change) put the customer's 4-digit transaction PIN — which authorises
     * every debit — and the emailed one-time code into the session store and
     * back into the HTML.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
        'pin',
        'current_pin',
        'pin_confirmation',
        'pin_code',
        'code',
        'otp',
        'token',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $exception)
    {
        /*
         * Debug output (stack traces, the environment excerpt and every
         * config value that the whoops/Ignition page renders) is decided
         * solely by config('app.debug'). A production deployment that inherits
         * a development .env — this repository ships .env with APP_DEBUG=true —
         * would therefore hand an attacker the application's configuration on
         * the first 500. Force it off anywhere that is not a developer's
         * machine; `config/app.php` already defaults it to false when the
         * variable is absent.
         */
        if (! app()->environment(['local', 'development', 'testing'])) {
            config(['app.debug' => false]);
        }

        // Custom error pages for specific HTTP status codes
        if ($this->isHttpException($exception)) {
            $statusCode = $exception->getStatusCode();
            
            // Check if custom view exists for this status code
            if (view()->exists("errors.{$statusCode}")) {
                return response()->view("errors.{$statusCode}", [
                    'exception' => $exception
                ], $statusCode);
            }
        }

        return parent::render($request, $exception);
    }
}
