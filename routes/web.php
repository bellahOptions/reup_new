<?php

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\AirtimeDataController;
use App\Http\Controllers\BettingController;
use App\Http\Controllers\CableTvController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ElectricityController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\JambPinController;
use App\Http\Controllers\PaystackController;
use App\Http\Controllers\PairgateWebhookController;
use App\Http\Controllers\PricelistController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TermsController;
use App\Http\Controllers\WaecPinController;
use App\Http\Controllers\AffiliateController;
use App\Http\Controllers\TipsController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/terms-of-service', [TermsController::class, 'showTerms'])->name('terms-of-service');
Route::get('/privacy-policy', [TermsController::class, 'showPrivacy'])->name('privacy-policy');
Route::view('/faq', 'faq')->name('faq');

Route::get('/contact', [ContactController::class, 'index'])->name('contact');
Route::post('/contact/submit', [ContactController::class, 'submit'])
    ->middleware('throttle:5,1')
    ->name('contact.submit');

Route::get('/llm.txt', [SettingsController::class, 'generateLlmTxt'])->name('llm');

Route::get('/robots.txt', function () {
    $path = public_path('robots.production.txt');

    if (! app()->environment('production') || ! is_file($path)) {
        return response("User-agent: *\nDisallow: /", 200)->header('Content-Type', 'text/plain');
    }

    return response(file_get_contents($path), 200)->header('Content-Type', 'text/plain');
})->name('robots');

/*
|--------------------------------------------------------------------------
| Paystack gateway endpoints
|--------------------------------------------------------------------------
| The webhook is server-to-server. It must NOT sit behind `auth` (Paystack
| holds no session) and cannot carry a CSRF token, so it is both outside the
| auth group and listed in VerifyCsrfToken::$except. It is authenticated by
| HMAC signature against the raw request body instead.
|
| Previously this route pointed at a controller method that did not exist and
| was nested inside the auth+verified group, so it could never fire.
*/
Route::post('/paystack/webhook', [PaystackController::class, 'webhook'])
    ->middleware('throttle:120,1')
    ->name('paystack.webhook');

/*
|--------------------------------------------------------------------------
| Pairgate vending webhook
|--------------------------------------------------------------------------
| Also server-to-server, and also outside `auth` + CSRF, authenticated by HMAC
| signature (see PairgateWebhookController::handle). This is where a vended
| electricity token or exam PIN arrives, and where an order Pairgate accepted
| and later failed is reported — neither is visible on the purchase call itself.
|
| It refuses to run unsigned: PAIRGATE_WEBHOOK_SECRET must be set, and the same
| secret entered in the Pairgate dashboard. The URL to paste there is this path
| on the public domain, e.g. https://your-domain/pairgate/webhook.
*/
Route::post('/pairgate/webhook', [PairgateWebhookController::class, 'handle'])
    ->middleware('throttle:120,1')
    ->name('pairgate.webhook');

/*
|--------------------------------------------------------------------------
| Authenticated customer routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/transactions', [WalletController::class, 'history'])->name('transactions.index');

    /*
    |----------------------------------------------------------------------
    | First sign-in tips and affiliate programme
    |----------------------------------------------------------------------
    | Both POST because they change state. `tips.replay` deliberately clears
    | the seen timestamp so a user can walk the introduction again.
    */
    Route::post('/tips/dismiss', [TipsController::class, 'dismiss'])->name('tips.dismiss');
    Route::post('/tips/replay', [TipsController::class, 'replay'])->name('tips.replay');

    Route::get('/refer', [AffiliateController::class, 'index'])->name('affiliate.index');

    /*
    |----------------------------------------------------------------------
    | Live chat
    |----------------------------------------------------------------------
    */
    Route::get('/live-chat', [ChatController::class, 'index'])->name('live.chat');
    Route::get('/chat/messages', [ChatController::class, 'getMessages'])->name('chat.messages');
    Route::post('/chat/send', [ChatController::class, 'sendMessage'])
        ->middleware('throttle:60,1')->name('chat.send');
    Route::post('/chat/typing', [ChatController::class, 'userTyping'])
        ->middleware('throttle:120,1')->name('chat.typing');
    Route::post('/chat/close', [ChatController::class, 'closeChat'])->name('chat.close');
    Route::get('/chat/admins', [ChatController::class, 'getAvailableAdmins'])->name('chat.admins');

    /*
    |----------------------------------------------------------------------
    | Airtime & data
    |----------------------------------------------------------------------
    | Route names keep the historical `airtime.*` / `data.*` keys that the
    | views already call; the URI is grouped under /airtime.
    */
    Route::prefix('airtime')->group(function () {
        Route::get('/', [AirtimeDataController::class, 'index'])->name('airtime-data.index');
        Route::post('/purchase', [AirtimeDataController::class, 'purchaseAirtime'])
            ->middleware('throttle:20,1')->name('airtime.purchase');
        Route::post('/data/purchase', [AirtimeDataController::class, 'purchaseData'])
            ->middleware('throttle:20,1')->name('data.purchase');
        Route::get('/history', [AirtimeDataController::class, 'history'])->name('airtime-data.history');
        Route::get('/networks', [AirtimeDataController::class, 'networks'])->name('airtime-data.networks');
    });

    // Transaction outcome pages. Ownership is asserted in the controller.
    Route::get('/transactions/{reference}/success', [AirtimeDataController::class, 'success'])
        ->name('transactions.success');
    Route::get('/transactions/{reference}/failed', [AirtimeDataController::class, 'failed'])
        ->name('transactions.failed');

    /*
    |----------------------------------------------------------------------
    | Cable TV
    |----------------------------------------------------------------------
    */
    Route::prefix('cable-tv')->name('cable-tv.')->group(function () {
        Route::get('/', [CableTvController::class, 'index'])->name('index');
        Route::get('/providers', [CableTvController::class, 'providers'])->name('providers');
        Route::get('/packages', [CableTvController::class, 'packages'])->name('packages');
        Route::post('/verify', [CableTvController::class, 'verify'])
            ->middleware('throttle:30,1')->name('verify');
        Route::post('/subscribe', [CableTvController::class, 'subscribe'])
            ->middleware('throttle:20,1')->name('purchase');
        Route::get('/history', [CableTvController::class, 'history'])->name('history');
    });

    /*
    |----------------------------------------------------------------------
    | Exam PINs
    |----------------------------------------------------------------------
    */
    Route::prefix('waec-pin')->name('waec-pin.')->group(function () {
        Route::get('/', [WaecPinController::class, 'index'])->name('index');
        Route::post('/purchase', [WaecPinController::class, 'purchase'])
            ->middleware('throttle:20,1')->name('purchase');
        Route::get('/history', [WaecPinController::class, 'history'])->name('history');
        Route::get('/quantity', [WaecPinController::class, 'quantity'])->name('quantity');
    });

    Route::prefix('jamb-pin')->group(function () {
        Route::get('/', [JambPinController::class, 'index'])->name('jamb-pin.index');
        Route::post('/verify-profile', [JambPinController::class, 'verifyProfile'])
            ->middleware('throttle:30,1')->name('jamb.verify-profile');
        Route::post('/purchase', [JambPinController::class, 'purchase'])
            ->middleware('throttle:20,1')->name('jamb.purchase');
        Route::get('/history', [JambPinController::class, 'history'])->name('jamb-pin.history');
        Route::get('/types', [JambPinController::class, 'types'])->name('jamb-pin.types');
    });

    /*
    |----------------------------------------------------------------------
    | Electricity
    |----------------------------------------------------------------------
    */
    Route::prefix('electricity')->name('electricity.')->group(function () {
        Route::get('/', [ElectricityController::class, 'index'])->name('index');
        Route::get('/discos', [ElectricityController::class, 'discos'])->name('discos');
        Route::post('/verify-meter', [ElectricityController::class, 'verifyMeter'])
            ->middleware('throttle:30,1')->name('verify-meter');
        Route::post('/pay', [ElectricityController::class, 'pay'])
            ->middleware('throttle:20,1')->name('pay');
        Route::get('/history', [ElectricityController::class, 'history'])->name('history');
    });

    /*
    |----------------------------------------------------------------------
    | Betting wallet funding
    |----------------------------------------------------------------------
    */
    Route::prefix('betting')->name('betting.')->group(function () {
        Route::get('/', [BettingController::class, 'index'])->name('index');
        Route::get('/providers', [BettingController::class, 'providers'])->name('providers');
        Route::post('/verify', [BettingController::class, 'verify'])
            ->middleware('throttle:30,1')->name('verify');
        Route::post('/fund', [BettingController::class, 'fund'])
            ->middleware('throttle:20,1')->name('fund');
        Route::get('/history', [BettingController::class, 'history'])->name('history');
    });

    /*
    |----------------------------------------------------------------------
    | Wallet
    |----------------------------------------------------------------------
    */
    Route::prefix('wallet')->name('wallet.')->group(function () {
        Route::get('/', [WalletController::class, 'index'])->name('index');
        Route::get('/history', [WalletController::class, 'history'])->name('history');

        Route::get('/fund', [WalletController::class, 'fund'])->name('fund');
        Route::post('/fund', [WalletController::class, 'processFunding'])
            ->middleware('throttle:10,1')->name('process-funding');

        Route::get('/paystack/callback', [WalletController::class, 'handlePaystackCallback'])
            ->name('paystack.callback');

        Route::get('/bank-transfer/details', [WalletController::class, 'showBankTransferDetails'])
            ->name('bank-transfer.details');
        Route::post('/bank-transfer/submit-proof', [WalletController::class, 'submitBankTransferProof'])
            ->middleware('throttle:10,1')->name('bank-transfer.submit-proof');

        Route::get('/payment/status', [WalletController::class, 'paymentStatus'])->name('payment.status');
        Route::post('/payment/check-status', [WalletController::class, 'checkPaymentStatus'])
            ->middleware('throttle:60,1')->name('payment.check-status');

        Route::get('/balance', function () {
            $wallet = Auth::user()->wallet;

            return response()->json([
                'balance' => (float) ($wallet->balance ?? 0),
                'currency' => 'NGN',
                'formatted' => '₦' . number_format((float) ($wallet->balance ?? 0), 2),
            ]);
        })->name('balance');
    });

    /*
    |----------------------------------------------------------------------
    | Profile
    |----------------------------------------------------------------------
    */
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile.index');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/notifications', [ProfileController::class, 'updateNotifications'])->name('profile.notifications');
    Route::put('/profile/pin', [ProfileController::class, 'updatePin'])->name('profile.pin');
    Route::post('/profile/pin/code', [ProfileController::class, 'requestPinOtp'])
        ->middleware('throttle:5,1')->name('profile.pin.code');
    Route::post('/profile/request-verification', [ProfileController::class, 'requestPhoneVerification'])
        ->middleware('throttle:5,1')->name('profile.request-verification');
    Route::post('/profile/verify-phone', [ProfileController::class, 'verifyPhone'])
        ->middleware('throttle:10,1')->name('profile.verify-phone');

    /*
    |----------------------------------------------------------------------
    | Pricelist
    |----------------------------------------------------------------------
    | These were previously public. `purchaseData` and `callback` move money,
    | so the whole group now requires an authenticated, verified session.
    */
    Route::prefix('pricelist')->name('pricelist.')->group(function () {
        Route::post('/purchase/data', [PricelistController::class, 'purchaseData'])
            ->middleware('throttle:20,1')->name('purchase.data');
        Route::post('/callback', [PricelistController::class, 'callback'])->name('callback');
        Route::get('/refresh', [PricelistController::class, 'refresh'])->name('refresh');
        Route::get('/query/{orderId}', [PricelistController::class, 'queryTransaction'])->name('query');
    });

    Route::post('/announcements/mark-session-viewed', function () {
        session(['has_seen_announcements' => true]);

        return response()->json(['success' => true]);
    })->name('announcements.mark-session-viewed');
});

// Public pricelist browsing (read-only).
Route::get('/pricelist', [PricelistController::class, 'index'])->name('pricelist');
Route::get('/pricelist/api', [PricelistController::class, 'api'])->name('pricelist.api');

/*
|--------------------------------------------------------------------------
| Admin console
|--------------------------------------------------------------------------
*/
require __DIR__ . '/admin.php';

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
| Required last so that the `login` route it defines is registered before any
| `guest`-middleware redirect targets it.
*/
require __DIR__ . '/auth.php';
