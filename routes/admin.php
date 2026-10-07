<?php

use App\Http\Controllers\Admin\AdminChatController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BankTransferController;
use App\Http\Controllers\Admin\ContactController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\PaystackController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin authentication
|--------------------------------------------------------------------------
| Registration is intentionally reachable ONLY while the platform has no
| admin at all — the bootstrap case. Once an administrator exists, new admins
| are created by a super admin from inside the console.
|
| The previous guard was
|     if (Auth::check() && !Auth::user()->isSuperAdmin() && User::admins()->exists())
| which short-circuits to `false` for anonymous visitors, so anyone could
| POST /admin/register and mint themselves an admin account.
*/
Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:5,1')
            ->name('login.post');
    });

    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware(['auth', 'admin'])
        ->name('logout');

    // Bootstrap-only registration, enforced in the controller.
    Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:3,1')
        ->name('register.post');
});

/*
|--------------------------------------------------------------------------
| Admin console
|--------------------------------------------------------------------------
| Every group below is gated by `auth` + `admin` and, where the surface is
| destructive or sensitive, by a named permission via `admin:<permission>`.
| AdminMiddleware previously accepted a permission argument that no route
| ever passed, so moderators and support accounts could reach everything.
*/
Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function () {

    /* ---- Dashboard -------------------------------------------------- */
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::prefix('stats')->name('stats.')->group(function () {
        Route::get('/transactions-by-type', [DashboardController::class, 'transactionsByType'])->name('transactions-by-type');
        Route::get('/transactions-by-status', [DashboardController::class, 'transactionsByStatus'])->name('transactions-by-status');
        Route::get('/revenue', [DashboardController::class, 'revenueStats'])->name('revenue');
    });

    Route::get('/dashboard/online-users', [DashboardController::class, 'onlineUsers'])->name('dashboard.online-users');
    Route::get('/dashboard/recent-chats', [DashboardController::class, 'recentChats'])->name('dashboard.recent-chats');
    Route::post('/update-activity', [DashboardController::class, 'updateActivity'])
        ->middleware('throttle:10,1')->name('update.activity');

    /* ---- Users ------------------------------------------------------ */
    Route::prefix('users')->name('users.')->middleware('admin:manage_users')->group(function () {
        // Static segments first: `{user}` would otherwise swallow them.
        Route::get('/export', [UserController::class, 'export'])->name('export');
        Route::get('/suggestions', [UserController::class, 'suggestions'])->name('suggestions');

        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('/{user}', [UserController::class, 'show'])->name('show');
        Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit');
        Route::put('/{user}', [UserController::class, 'update'])->name('update');

        Route::post('/{user}/wallet', [UserController::class, 'updateWallet'])
            ->middleware('admin:manage_wallets')->name('wallet.update');
        Route::post('/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('toggle-status');
        Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy');
    });

    /* ---- Transactions ----------------------------------------------- */
    Route::prefix('transactions')->name('transactions.')->middleware('admin:view_transactions')->group(function () {
        Route::get('/export', [TransactionController::class, 'export'])->name('export');
        Route::get('/', [TransactionController::class, 'index'])->name('index');
        Route::get('/{transaction}', [TransactionController::class, 'show'])->name('show');

        Route::middleware('admin:manage_transactions')->group(function () {
            Route::put('/{transaction}/force-success', [TransactionController::class, 'forceSuccess'])->name('force-success');
            Route::put('/{transaction}/force-failed', [TransactionController::class, 'forceFailed'])->name('force-failed');
            Route::put('/{transaction}/cancel', [TransactionController::class, 'cancel'])->name('cancel');
        });
    });

    /* ---- Bank transfers --------------------------------------------- */
    Route::prefix('bank-transfers')->name('bank-transfers.')
        ->middleware('admin:manage_wallets')
        ->group(function () {
            Route::get('/', [BankTransferController::class, 'index'])->name('index');
            Route::get('/{transfer}', [BankTransferController::class, 'show'])->name('show');
            Route::post('/{transfer}/approve', [BankTransferController::class, 'approve'])->name('approve');
            Route::post('/{transfer}/reject', [BankTransferController::class, 'reject'])->name('reject');
            Route::post('/{transfer}/fraudulent', [BankTransferController::class, 'markFraudulent'])->name('mark-fraudulent');
            Route::get('/{transfer}/proof', [BankTransferController::class, 'viewProof'])->name('proof.view');
            Route::get('/{transfer}/proof/download', [BankTransferController::class, 'downloadProof'])->name('proof.download');
        });

    /* ---- Admin management (super admin only) ------------------------ */
    Route::middleware('admin:manage_admins')->group(function () {
        Route::resource('admins', AdminController::class)->except(['show']);
        Route::post('admins/{admin}/toggle-status', [AdminController::class, 'toggleStatus'])->name('admins.toggle-status');
        Route::post('update-activity', [AdminController::class, 'updateActivity'])->name('update.activity');
    });

    /* ---- Live chat -------------------------------------------------- */
    Route::prefix('chat')->name('chat.')->middleware('admin:chat')->group(function () {
        Route::get('/', [AdminChatController::class, 'index'])->name('index');
        Route::get('/sessions', [AdminChatController::class, 'getSessions'])->name('sessions');
        Route::get('/sessions/{session}/messages', [AdminChatController::class, 'getSessionMessages'])->name('messages');
        Route::post('/send', [AdminChatController::class, 'sendMessage'])
            ->middleware('throttle:90,1')->name('send');
        Route::get('/online-admins', [AdminChatController::class, 'getOnlineAdmins'])->name('online-admins');
        Route::get('/stats', [AdminChatController::class, 'getStats'])->name('stats');
        Route::post('/availability', [AdminChatController::class, 'updateAvailability'])->name('availability');
        Route::post('/typing', [AdminChatController::class, 'typingStatus'])->name('typing');
        Route::post('/close', [AdminChatController::class, 'closeSession'])->name('close');
        Route::post('/transfer', [AdminChatController::class, 'transferSession'])->name('transfer');
    });

    /* ---- Notifications ---------------------------------------------- */
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/unread', [NotificationController::class, 'getUnreadNotifications'])->name('unread');
        Route::post('/mark-read', [NotificationController::class, 'markAsRead'])
            ->middleware('throttle:60,1')->name('mark-read');
    });

    /* ---- Contact messages ------------------------------------------- */
    Route::prefix('contact')->name('contact.')->middleware('admin:view_contacts')->group(function () {
        Route::get('/', [ContactController::class, 'index'])->name('index');
        Route::get('/{id}', [ContactController::class, 'show'])->name('show');
        Route::post('/{id}/reply', [ContactController::class, 'reply'])
            ->middleware('admin:manage_contacts')->name('reply');
        Route::post('/{id}/mark-read', [ContactController::class, 'markAsRead'])->name('mark-read');
        Route::post('/{id}/mark-responded', [ContactController::class, 'markAsResponded'])->name('mark-responded');
        Route::delete('/{id}', [ContactController::class, 'delete'])
            ->middleware('admin:manage_contacts')->name('delete');
    });

    /* ---- Legal documents -------------------------------------------- */
    Route::prefix('terms')->name('terms.')->middleware('admin:manage_settings')->group(function () {
        Route::get('/', [AdminController::class, 'editTerms'])->name('index');
        Route::post('/update', [AdminController::class, 'updateTerms'])->name('update');
        Route::get('/get/{type}', [AdminController::class, 'getDocument'])->name('get');
        Route::get('/history/{type}', [AdminController::class, 'termsHistory'])->name('history');
        Route::post('/restore/{id}', [AdminController::class, 'restoreVersion'])->name('restore');
        Route::post('/toggle-status/{id}', [AdminController::class, 'toggleTermsStatus'])->name('toggle-status');
    });

    /* ---- Settings --------------------------------------------------- */
    Route::prefix('settings')->name('settings.')->middleware('admin:manage_settings')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::get('/data', [SettingsController::class, 'data'])->name('data');
        Route::post('/update', [SettingsController::class, 'update'])->name('update');
        Route::post('/seed-defaults', [SettingsController::class, 'seedDefaults'])->name('seed');
        Route::get('/maintenance-status', [SettingsController::class, 'getMaintenanceStatus'])->name('maintenance');
        Route::get('/check', [SettingsController::class, 'check'])->name('check');
        Route::get('/check-exists', [SettingsController::class, 'checkExists'])->name('check.exists');
    });

    /* ---- Announcements ---------------------------------------------- */
    Route::prefix('announcements')->name('announcement.')->middleware('admin:manage_settings')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/create', [NotificationController::class, 'create'])->name('create');
        Route::post('/', [NotificationController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [NotificationController::class, 'edit'])->name('edit');
        Route::put('/{id}', [NotificationController::class, 'update'])->name('update');
        Route::delete('/{id}', [NotificationController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/toggle-status', [NotificationController::class, 'toggleStatus'])->name('toggle-status');
        Route::get('/api/active', [NotificationController::class, 'getActiveAnnouncements'])->name('api.active');
    });

    /* ---- Paystack reporting ----------------------------------------- */
    Route::prefix('paystack')->name('paystack.')->middleware('admin:manage_payments')->group(function () {
        Route::get('/balance', [PaystackController::class, 'getBalance'])->name('balance');
        Route::post('/balance/refresh', [PaystackController::class, 'refreshBalance'])->name('balance.refresh');
        Route::get('/stats/{period?}', [PaystackController::class, 'getTransactionStats'])->name('stats');
    });
});
