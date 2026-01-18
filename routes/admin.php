<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\BankTransferController;
use App\Http\Controllers\Admin\ContactController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminChatController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\SettingsController;


// Admin Auth Routes (no middleware)
Route::prefix('admin')->name('admin.')->group(function () {
    // Login Routes
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post');
    
    // Registration Routes (only accessible if no admins exist)
    Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.post');
    
    // Logout
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});

// Protected Admin Routes
Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    
    // Dashboard Stats
    Route::get('/stats/transactions-by-type', [DashboardController::class, 'transactionsByType'])->name('stats.transactions-by-type');
    Route::get('/stats/transactions-by-status', [DashboardController::class, 'transactionsByStatus'])->name('stats.transactions-by-status');
    Route::get('/stats/revenue', [DashboardController::class, 'revenueStats'])->name('stats.revenue');

    
    // User Management
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('/{user}', [UserController::class, 'show'])->name('show');
        Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit');
        Route::put('/{user}', [UserController::class, 'update'])->name('update');
        Route::post('/{user}/wallet', [UserController::class, 'updateWallet'])->name('wallet.update');
        Route::post('/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('toggle-status');
        Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy');
        Route::get('/export', [UserController::class, 'export'])->name('export');
    });
    

    
    // Bank Transfer Management
    Route::prefix('bank-transfers')->name('bank-transfers.')->group(function () {
        Route::get('/', [BankTransferController::class, 'index'])->name('index');
        Route::get('/{transfer}', [BankTransferController::class, 'show'])->name('show');
        Route::post('/{transfer}/approve', [BankTransferController::class, 'approve'])->name('approve');
        Route::post('/{transfer}/reject', [BankTransferController::class, 'reject'])->name('reject');
        Route::post('/{transfer}/fraudulent', [BankTransferController::class, 'markFraudulent'])->name('mark-fraudulent');
        Route::get('/{transfer}/proof', [BankTransferController::class, 'viewProof'])->name('proof.view');
        Route::get('/{transfer}/proof/download', [BankTransferController::class, 'downloadProof'])->name('proof.download');
    });
});
// Transaction Management
// Transaction Management
Route::prefix('admin')->middleware(['auth', 'admin'])->group(function () {
    // Transactions
    Route::get('/transactions', [TransactionController::class, 'index'])->name('admin.transactions.index');
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('admin.transactions.show'); // Changed to {transaction}
    Route::get('/transactions/export', [TransactionController::class, 'export'])->name('admin.transactions.export');
    
    // Transaction actions
    Route::put('/transactions/{transaction}/force-success', [TransactionController::class, 'forceSuccess'])->name('admin.transactions.force-success');
    Route::put('/transactions/{transaction}/force-failed', [TransactionController::class, 'forceFailed'])->name('admin.transactions.force-failed');
    Route::put('/transactions/{transaction}/cancel', [TransactionController::class, 'cancel'])->name('admin.transactions.cancel');
});

// Admin management routes (only for super admin)
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
     // AJAX suggestions
    Route::get('/users/suggestions', [UserController::class, 'suggestions'])->name('users.suggestions');
    // Admin Management
    Route::resource('admins', AdminController::class)->except(['show']);
    Route::post('admins/{admin}/toggle-status', [AdminController::class, 'toggleStatus'])->name('admins.toggle-status');
    Route::post('update-activity', [AdminController::class, 'updateActivity'])->name('update.activity');
    
    // Admin Chat Routes
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('/', [AdminChatController::class, 'index'])->name('index');
        Route::get('/sessions', [AdminChatController::class, 'getSessions'])->name('sessions');
        Route::get('/sessions/{session}/messages', [AdminChatController::class, 'getSessionMessages'])->name('messages');
        Route::post('/send', [AdminChatController::class, 'sendMessage'])->name('send');
        Route::get('/online-admins', [AdminChatController::class, 'getOnlineAdmins'])->name('online-admins');
        Route::get('/stats', [AdminChatController::class, 'getStats'])->name('stats');
        Route::post('/availability', [AdminChatController::class, 'updateAvailability'])->name('availability');
        Route::post('/typing', [AdminChatController::class, 'typingStatus'])->name('typing');
        Route::post('/close', [AdminChatController::class, 'closeSession'])->name('close');
        Route::post('/transfer', [AdminChatController::class, 'transferSession'])->name('transfer');
        // Admin logs
    });

    // Notifications
Route::prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/unread', [NotificationController::class, 'getUnreadNotifications'])->name('unread');
    Route::post('/mark-read', [NotificationController::class, 'markAsRead'])->name('mark-read');
    Route::get('/', [NotificationController::class, 'index'])->name('index');

    
    
    Route::get('/dashboard/online-users', [DashboardController::class, 'onlineUsers'])
         ->name('dashboard.online-users');
    
    Route::get('/dashboard/recent-chats', [DashboardController::class, 'recentChats'])
         ->name('dashboard.recent-chats');
});


    // Admin activity update
    Route::post('/update-activity', [DashboardController::class, 'updateActivity'])->name('update.activity');

    // Contact Messages
    Route::get('/contact', [ContactController::class, 'index'])->name('contact.index');
    Route::get('/contact/{id}', [ContactController::class, 'show'])->name('contact.show');
    Route::post('/contact/{id}/reply', [ContactController::class, 'reply'])->name('contact.reply');
    Route::post('/contact/{id}/mark-read', [ContactController::class, 'markAsRead'])->name('contact.mark-read');
    Route::post('/contact/{id}/mark-responded', [ContactController::class, 'markAsResponded'])->name('contact.mark-responded');
    Route::delete('/contact/{id}', [ContactController::class, 'delete'])->name('contact.delete');
    
});


// Legal Documents
Route::middleware(['auth', 'admin'])->prefix('admin')->name('terms.')->group(function () {
    Route::get('/terms', [AdminController::class, 'editTerms'])->name('index');
    Route::post('/terms/update', [AdminController::class, 'updateTerms'])->name('update');
    Route::get('/terms/get/{type}', [AdminController::class, 'getDocument'])->name('get');
    Route::get('/terms/preview/{type}', [AdminController::class, 'previewTerms'])->name('preview');
    Route::get('/terms/history/{type}', [AdminController::class, 'termsHistory'])->name('history');
    Route::post('/terms/restore/{id}', [AdminController::class, 'restoreVersion'])->name('restore');
    Route::post('/terms/toggle-status/{id}', [AdminController::class, 'toggleTermsStatus'])->name('toggle-status'); 
    Route::get('/admin/terms-of-service/get-privacy', [AdminController::class, 'getPrivacyForTerms'])->name('get-privacy');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function() {
    // Settings routes
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('/settings/data', [SettingsController::class, 'data'])->name('settings.data');
    Route::post('/settings/update', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/seed-defaults', [SettingsController::class, 'seedDefaults'])->name('settings.seed');
    Route::get('/settings/maintenance-status', [SettingsController::class, 'getMaintenanceStatus'])->name('settings.maintenance');
    Route::get('/settings/check', [SettingsController::class, 'check'])->name('settings.check');
    Route::get('/settings/check-exists', [SettingsController::class, 'checkExists'])->name('settings.check.exists');
});



