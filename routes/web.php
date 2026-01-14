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

Route::get('/', function () {
    return view('home');
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
    Route::prefix('airtime-data')->group(function () {
        Route::get('/', [AirtimeDataController::class, 'index'])->name('airtime-data.index');
        Route::any('/airtime/purchase', [AirtimeDataController::class, 'purchaseAirtime'])->name('airtime.purchase');
        Route::post('/data/purchase', [AirtimeDataController::class, 'purchaseData'])->name('data.purchase');
        Route::get('/history', [AirtimeDataController::class, 'history'])->name('airtime-data.history');
        Route::get('/networks', [AirtimeDataController::class, 'networks'])->name('airtime-data.networks');
            
    // Transaction result pages
    Route::get('/transaction/success/{reference}', [AirtimeDataController::class, 'success'])->name('transaction.success');
    Route::get('/transaction/failed/{reference}', [AirtimeDataController::class, 'failed'])->name('transaction.failed');
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
Route::get('/privacy-policy', function () {
    return view('privacy-policy');
})->name('privacy-policy');

Route::get('/terms-of-service', function () {
    return view('terms-of-service');
})->name('terms-of-service');
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
Route::middleware(['auth' , 'verified'])->prefix('wallet')->name('wallet.')->group(function () {
    
    // Dashboard & History
    Route::get('/', [WalletController::class, 'index'])->name('index');
    Route::get('/history', [WalletController::class, 'history'])->name('history');
    
    // Funding Routes
    Route::get('/fund', [WalletController::class, 'fund'])->name('fund');
    Route::post('/fund', [WalletController::class, 'processFunding'])->name('process-funding');
    
    // Paystack Routes
    Route::get('/paystack/callback', [WalletController::class, 'handlePaystackCallback'])->name('paystack.callback');
    
    // Bank Transfer Routes
    Route::get('/bank-transfer/details', [WalletController::class, 'showBankTransferDetails'])->name('bank-transfer.details');
    Route::post('/bank-transfer/submit-proof', [WalletController::class, 'submitBankTransferProof'])->name('bank-transfer.submit-proof');
    
    // API/AJAX Endpoints (optional - for real-time balance checks, etc)
    Route::get('/balance', [WalletController::class, 'getBalance'])->name('balance');

    // Add this to your wallet routes group:
Route::get('/payment/status', [WalletController::class, 'paymentStatus'])->name('payment.status');
Route::post('/payment/check-status', [WalletController::class, 'checkPaymentStatus'])->name('payment.check-status');


});

// Admin Routes for Wallet Management (separate these into admin routes file)
Route::middleware(['auth', 'admin'])->prefix('admin/wallet')->name('admin.wallet.')->group(function () {
    
    // View pending bank transfers
    //Route::get('/pending-transfers', [AdminWalletController::class, 'pendingTransfers'])->name('pending-transfers');
    
    // Approve/Reject bank transfer
   // Route::post('/approve/{transaction}', [AdminWalletController::class, 'approveBankTransfer'])->name('approve');
   // Route::post('/reject/{transaction}', [AdminWalletController::class, 'rejectBankTransfer'])->name('reject');
    
});
        
    Route::get('/paystack/callback', [PaystackController::class, 'callback'])
    ->name('paystack.callback');

Route::post('/paystack/webhook', [PaystackController::class, 'webhook'])
    ->name('paystack.webhook');

    // Contact routes
Route::get('/contact', [ContactController::class, 'index'])->name('contact');
Route::post('/contact/submit', [ContactController::class, 'submit'])->name('contact.submit');


require __DIR__.'/auth.php';
// Include admin routes
require __DIR__.'/admin.php';
