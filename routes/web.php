<?php

use Illuminate\Support\Facades\Route;


use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AirtimeDataController;
use App\Http\Controllers\CableTvController;
use App\Http\Controllers\WaecPinController;
use App\Http\Controllers\JambPinController;
use App\Http\Controllers\ElectricityController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\PaystackController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\TermsController;
use App\Http\Controllers\PricelistController;
use App\Http\Controllers\HomeController;


Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/llm.txt', [SettingsController::class, 'generateLlmTxt']);
Route::middleware(['auth', 'admin'])->prefix('paystack')->name('paystack.')->group(function () {
        Route::get('/balance', [PaystackController::class, 'getBalance'])->name('balance');
    Route::post('/balance/refresh', [PaystackController::class, 'refreshBalance'])->name('balance.refresh');
    Route::get('/stats/{period?}', [PaystackController::class, 'getTransactionStats'])->name('stats');
    });


Route::middleware('auth', 'verified')->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    //Transaction routes
    Route::get('/transactions', [WalletController::class, 'history'])->name('transactions.index');

        // Live Chat routes
Route::get('/live-chat', [ChatController::class, 'index'])->name('live.chat');
Route::get('/chat/messages', [ChatController::class, 'getMessages'])->name('chat.messages');
Route::any('/chat/send', [ChatController::class, 'sendMessage'])->name('chat.send');
Route::any('/chat/typing', [ChatController::class, 'userTyping'])->name('chat.typing');
Route::post('/chat/close', [ChatController::class, 'closeChat'])->name('chat.close');
Route::get('/chat/admins', [ChatController::class, 'getAvailableAdmins'])->name('chat.admins');

 // Airtime & Data
    Route::prefix('airtime')->group(function () {
        Route::get('/', [AirtimeDataController::class, 'index'])->name('airtime-data.index');
        Route::any('/airtime/purchase', [AirtimeDataController::class, 'purchaseAirtime'])->name('airtime.purchase');
        Route::post('/data/purchase', [AirtimeDataController::class, 'purchaseData'])->name('data.purchase');
        Route::get('/history', [AirtimeDataController::class, 'history'])->name('airtime-data.history');
        Route::get('/networks', [AirtimeDataController::class, 'networks'])->name('airtime-data.networks');
            
    });

Route::prefix('airtime')->group(function () {
    Route::get('/transaction/failed/{reference}', [AirtimeDataController::class, 'failed'])
        ->name('airtime.transaction.failed');
    
    Route::get('/transaction/success/{reference}', [AirtimeDataController::class, 'success'])
        ->name('airtime.transaction.success');
        
});
// For any missing transaction routes
Route::get('/airtime/transaction/{status}/{reference}', function ($status, $reference) {
    if ($status === 'failed') {
        return app(AirtimeDataController::class)->failed($reference);
    }
    if ($status === 'success') {
        return app(AirtimeDataController::class)->success($reference);
    }
    abort(404);
});
    // Cable TV Subscription
    Route::prefix('cable-tv')->group(function () {
        Route::get('/', [CableTvController::class, 'index'])->name('cable-tv.index');
        Route::get('/providers', [CableTvController::class, 'providers'])->name('cable-tv.providers');
        Route::post('/subscribe', [CableTvController::class, 'subscribe'])->name('cable-tv.purchase');
        Route::get('/history', [CableTvController::class, 'history'])->name('cable-tv.history');
        Route::post('/verify', [CableTvController::class, 'verify'])->name('cable-tv.verify');
    });
    
    // WAEC e-PIN Purchase
    Route::prefix('waec-pin')->group(function () {
        Route::get('/', [WaecPinController::class, 'index'])->name('waec-pin.index');
        Route::post('/purchase', [WaecPinController::class, 'purchase'])->name('waec-pin.purchase');
        Route::get('/history', [WaecPinController::class, 'history'])->name('waec-pin.history');
        Route::get('/quantity', [WaecPinController::class, 'quantity'])->name('waec-pin.quantity');
    });
    
    // JAMB e-PIN Purchase
    Route::prefix('jamb-pin')->group(function () {
        Route::get('/', [JambPinController::class, 'index'])->name('jamb-pin.index');
        Route::post('/purchase', [JambPinController::class, 'purchase'])->name('jamb.purchase');
            Route::post('/jamb/verify-profile', [JambPinController::class, 'verifyProfile'])->name('jamb.verify-profile');
        Route::get('/history', [JambPinController::class, 'history'])->name('jamb-pin.history');
        Route::get('/types', [JambPinController::class, 'types'])->name('jamb-pin.types');
    });
    
    // Electricity Bill Payment
    Route::prefix('electricity')->group(function () {
        Route::get('/', [ElectricityController::class, 'index'])->name('electricity.index');
        Route::get('/discos', [ElectricityController::class, 'discos'])->name('electricity.discos');
        Route::post('/pay', [ElectricityController::class, 'pay'])->name('electricity.pay');
        Route::get('/history', [ElectricityController::class, 'history'])->name('electricity.history');
        Route::post('/verify-meter', [ElectricityController::class, 'verifyMeter'])->name('electricity.verify-meter');
    });
    // History
    //Route::get('/history', [TransactionController::class, 'index'])->name('history');
    
    // Profile Routes (if using Breeze)
    //Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    //Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    //Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

       Route::get('/profile', [ProfileController::class, 'index'])->name('profile.index');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/notifications', [ProfileController::class, 'updateNotifications'])->name('profile.notifications');
    Route::post('/profile/request-verification', [ProfileController::class, 'requestPhoneVerification'])->name('profile.request-verification');
    Route::post('/profile/verify-phone', [ProfileController::class, 'verifyPhone'])->name('profile.verify-phone');

    }); 

    // Legal Pages
Route::get('/terms-of-service', [TermsController::class, 'showTerms'])->name('terms-of-service');
Route::get('/privacy-policy', [TermsController::class, 'showPrivacy'])->name('privacy-policy');

Route::get('/faq', function () {
    return view('faq');
})->name('faq');
Route::get('/contact', function () {
    return view('contact');
})->name('contact');

// In web.php
Route::post('/announcements/viewed', function() {
    session(['announcements_viewed' => true]);
    return response()->json(['success' => true]);
})->name('announcements.viewed');

// Wallet Routes - All require authentication
Route::middleware(['auth', 'verified'])->prefix('wallet')->name('wallet.')->group(function () {
    
    // Dashboard & History
    Route::get('/', [WalletController::class, 'index'])->name('index');
    Route::get('/history', [WalletController::class, 'history'])->name('history');
    
    // Funding Routes
    Route::get('/fund', [WalletController::class, 'fund'])->name('fund');
    Route::post('/fund', [WalletController::class, 'processFunding'])->name('process-funding');
    
    // Paystack Routes
    Route::get('/paystack/callback', [WalletController::class, 'handlePaystackCallback'])->name('paystack.callback');
    Route::post('/paystack/webhook', [WalletController::class, 'handlePaystackWebhook'])->name('paystack.webhook');
    
    // Bank Transfer Routes
    Route::get('/bank-transfer/details', [WalletController::class, 'showBankTransferDetails'])->name('bank-transfer.details');
    Route::post('/bank-transfer/submit-proof', [WalletController::class, 'submitBankTransferProof'])->name('bank-transfer.submit-proof');
    
    // Payment Status Routes
    Route::get('/payment/status', [WalletController::class, 'paymentStatus'])->name('payment.status');
    Route::post('/payment/check-status', [WalletController::class, 'checkPaymentStatus'])->name('payment.check-status');
    
    // Success/Failure Pages
    Route::get('/success/{reference}', [WalletController::class, 'success'])->name('success');
    Route::get('/failed/{reference}', [WalletController::class, 'failed'])->name('failed');
    
    // Balance API/AJAX Endpoints
    Route::get('/balance', function() {
        $user = Auth::user();
        $wallet = $user->wallet ?? null;
        return response()->json([
            'balance' => $wallet ? $wallet->balance : 0,
            'currency' => '₦',
            'formatted' => '₦' . number_format($wallet ? $wallet->balance : 0, 2)
        ]);
    })->name('balance');
});


    Route::get('/paystack/callback', [PaystackController::class, 'callback'])
    ->name('paystack.callback');

Route::post('/paystack/webhook', [PaystackController::class, 'webhook'])
    ->name('paystack.webhook');

    // Contact routes
Route::get('/contact', [ContactController::class, 'index'])->name('contact');
Route::post('/contact/submit', [ContactController::class, 'submit'])->name('contact.submit');

// Include pricelist routes

Route::get('/pricelist', [PricelistController::class, 'index'])->name('pricelist');
Route::get('/pricelist/api', [PricelistController::class, 'api'])->name('pricelist.api');
Route::get('/pricelist/refresh', [PricelistController::class, 'refresh'])->name('pricelist.refresh');
Route::post('/pricelist/purchase/data', [PricelistController::class, 'purchaseData'])->name('pricelist.purchase.data');
Route::post('/pricelist/callback', [PricelistController::class, 'callback'])->name('pricelist.callback');
Route::get('/pricelist/query/{orderId}', [PricelistController::class, 'queryTransaction'])->name('pricelist.query');

// routes/web.php
Route::get('/robots.txt', function () {
    $content = "";
    
    if (app()->environment('production')) {
        $content = file_get_contents(public_path('robots.production.txt'));
    } else {
        $content = "User-agent: *\nDisallow: /";
    }
    
    return response($content, 200)
        ->header('Content-Type', 'text/plain');
});

Route::get('/llm.txt', function () {
    $content = file_get_contents(public_path('llm.txt'));
    
    return response($content, 200)
        ->header('Content-Type', 'text/plain');
});
Route::post('/announcements/mark-session-viewed', function() {
    // Set session variable to mark announcements as seen for this session
    session(['has_seen_announcements' => true]);
    return response()->json(['success' => true]);
})->name('announcements.mark-session-viewed');
require __DIR__.'/auth.php';
// Include admin routes
require __DIR__.'/admin.php';
