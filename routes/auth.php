<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\OtpLoginController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

/*
| Authentication routes.
|
| Throttles were absent entirely, so both credential endpoints and the
| verification-resend endpoint could be hammered. Limits now mirror the admin
| sign-in (5 per minute per email+IP) and are enforced by Laravel's default
| key, which includes the client IP.
*/
Route::middleware('guest')->group(function () {
    /*
    | Customer registration.
    |
    | `customer` on top of `guest` is what stops an administrator opening a
    | second, customer account with the same address: `guest` is skipped for an
    | authenticated visitor, so on its own it redirects an admin to the customer
    | dashboard rather than refusing the form. With both, an administrator
    | reaches neither the form nor the POST and is sent to the console instead.
    */
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->middleware('customer')
        ->name('register');
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware(['customer', 'throttle:5,1']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:10,1');

    /*
    | Password-alternative sign-in by one-time email code.
    |
    | Registered BEFORE nothing in particular competes for these paths, and all
    | under `guest` so an authenticated visitor is bounced away rather than
    | starting a second sign-in. Throttles here are coarse per-IP limits; the
    | per-account limits live in OtpLoginService, because Laravel's default
    | throttle key cannot see the submitted email.
    |
    | `login/code` is listed before `login/code/verify` for readability only —
    | the paths do not overlap, so ordering is not load-bearing.
    */
    Route::get('login/code', [OtpLoginController::class, 'showRequestForm'])
        ->name('login.code');

    Route::post('login/code', [OtpLoginController::class, 'sendCode'])
        ->middleware('throttle:10,1')
        ->name('login.code.send');

    Route::get('login/code/verify', [OtpLoginController::class, 'showVerifyForm'])
        ->name('login.code.verify');

    Route::post('login/code/verify', [OtpLoginController::class, 'verify'])
        ->middleware('throttle:15,1')
        ->name('login.code.verify.post');

    Route::post('login/code/resend', [OtpLoginController::class, 'resend'])
        ->middleware('throttle:5,1')
        ->name('login.code.resend');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->middleware('throttle:10,1')
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.update');
});

Route::middleware('auth')->group(function () {
    /*
    | The `auth`-only tail of the authentication flow.
    |
    | `customer` is applied here as well as to the main customer group in
    | web.php: these routes sit outside that group but are still part of the
    | customer application, so an administrator who reached one — from a stale
    | tab, a bookmarked verification link, a browser autocomplete — must be sent
    | to the console rather than shown a customer screen.
    */
    Route::get('verify-email', [EmailVerificationPromptController::class, '__invoke'])
        ->middleware('customer')
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', [VerifyEmailController::class, '__invoke'])
        ->middleware(['customer', 'signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware(['customer', 'throttle:6,1'])
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->middleware('customer')
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware(['customer', 'throttle:6,1']);

    // POST only. The verify-email view previously linked to this route with a
    // GET <a href>, which threw MethodNotAllowedHttpException.
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
