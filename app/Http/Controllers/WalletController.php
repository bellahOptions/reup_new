<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class WalletController extends Controller
{
    
    /**
     * DEBUG VERSION - Check logs at storage/logs/laravel.log
     */
    
    // Process funding request
    public function processFunding(Request $request)
    {
        Log::info('=== FUNDING REQUEST RECEIVED ===', [
            'user_id' => Auth::id(),
            'request_data' => $request->all()
        ]);
        
        // Validate
        try {
            $validated = $request->validate([
                'amount' => [
                    'required',
                    'numeric',
                    'min:' . config('wallet.minimum_funding', 100),
                    'max:' . config('wallet.maximum_funding', 1000000)
                ],
                'payment_method' => 'required|in:paystack,bank_transfer'
            ]);
            
            Log::info('Validation passed', $validated);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation failed', [
                'errors' => $e->errors()
            ]);
            return redirect()->back()
                ->withInput()
                ->withErrors($e->errors());
        }
        
        $user = Auth::user();
        
        // Check if user exists
        if (!$user) {
            Log::error('User not authenticated');
            return redirect()->route('login')
                ->withErrors(['error' => 'Please login to continue']);
        }
        
        Log::info('User authenticated', ['user_id' => $user->id, 'email' => $user->email]);
        
        // Get or create wallet
        try {
            $wallet = $this->getOrCreateWallet($user);
            Log::info('Wallet retrieved/created', [
                'wallet_id' => $wallet->id,
                'balance' => $wallet->balance
            ]);
        } catch (\Exception $e) {
            Log::error('Wallet creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()
                ->withInput()
                ->withErrors(['error' => 'Wallet initialization failed. Contact support.']);
        }
        
        DB::beginTransaction();
        
        try {
            // Calculate fee
            $fee = $this->calculateFee($request->amount, $request->payment_method);
            $totalAmount = $request->amount + $fee;
            
            Log::info('Fee calculated', [
                'amount' => $request->amount,
                'fee' => $fee,
                'total' => $totalAmount
            ]);
            
            // Generate reference
            $reference = $this->generateReference('FND');
            
            Log::info('Reference generated', ['reference' => $reference]);
            
            // Create transaction
            $transaction = Transactions::create([
                'user_id' => $user->id,
                'reference' => $reference,
                'type' => 'credit',
                'service_type' => 'funding',
                'description' => 'Wallet Funding - ' . ucfirst(str_replace('_', ' ', $request->payment_method)),
                'amount' => $request->amount,
                'service_fee' => $fee,
                'total_amount' => $totalAmount,
                'balance_before' => $wallet->balance,
                'balance_after' => null,
                'recipient' => $user->email,
                'provider' => $request->payment_method,
                'payment_method' => $request->payment_method,
                'payment_status' => 'pending',
                'status' => 'pending',
                'api_reference' => null,
                'meta' => json_encode([
                    'funding_amount' => $request->amount,
                    'processing_fee' => $fee,
                    'total_paid' => $totalAmount,
                    'initiated_at' => now()->toDateTimeString()
                ])
            ]);
            
            Log::info('Transaction created', [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference
            ]);
            
            DB::commit();
            
            // Route to payment method
            if ($request->payment_method === 'bank_transfer') {
                Log::info('Routing to bank transfer');
                return $this->initiateBankTransfer($transaction);
            } else {
                Log::info('Routing to Paystack');
                return $this->initiatePaystackPayment($transaction);
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('=== FUNDING FAILED ===', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $user->id ?? null
            ]);
            
            // Return more detailed error in development
            $errorMessage = app()->environment('local') 
                ? 'Error: ' . $e->getMessage() . ' (Line: ' . $e->getLine() . ')'
                : 'Unable to process request. Please try again.';
            
            return redirect()->back()
                ->withInput()
                ->withErrors(['error' => $errorMessage]);
        }
    }
    
    // Bank Transfer
    private function initiateBankTransfer($transaction)
    {
        try {
            Log::info('Initiating bank transfer', ['transaction_id' => $transaction->id]);
            
            $narration = 'REUP-' . substr($transaction->reference, -8);
            
            $transaction->update([
                'meta' => json_encode(array_merge(
                    json_decode($transaction->meta, true) ?? [],
                    [
                        'narration' => $narration,
                        'bank_details_shown_at' => now()->toDateTimeString()
                    ]
                ))
            ]);
            
            Log::info('Bank transfer initiated successfully', [
                'transaction_id' => $transaction->id,
                'narration' => $narration
            ]);
            
            // Check if view exists
            if (!view()->exists('wallet.bank-transfer')) {
                Log::error('View not found: wallet.bank-transfer');
                return redirect()->route('wallet.fund')
                    ->withErrors(['error' => 'Bank transfer page not available. Contact support.']);
            }
            
            return view('wallet.bank-transfer', [
                'transaction' => $transaction,
                'bank_details' => config('wallet.bank'),
                'narration' => $narration,
                'amount' => $transaction->total_amount
            ]);
            
        } catch (\Exception $e) {
            Log::error('Bank transfer initiation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'transaction_id' => $transaction->id ?? null
            ]);
            
            return redirect()->route('wallet.fund')
                ->withErrors(['error' => 'Unable to display bank details: ' . $e->getMessage()]);
        }
    }
    
    // Paystack
    private function initiatePaystackPayment($transaction)
    {
        try {
            Log::info('Initiating Paystack payment', ['transaction_id' => $transaction->id]);
            
            $user = $transaction->user;
            $amountInKobo = intval($transaction->total_amount * 100);
            
            // Check if Paystack key is configured
            $paystackKey = config('services.paystack.secret_key');
            if (!$paystackKey) {
                throw new \Exception('Paystack secret key not configured. Add PAYSTACK_SECRET_KEY to .env');
            }
            
            Log::info('Paystack config check passed', [
                'key_set' => !empty($paystackKey),
                'amount_kobo' => $amountInKobo
            ]);
            
            $payload = [
                'email' => $user->email,
                'amount' => $amountInKobo,
                'currency' => 'NGN',
                'reference' => $transaction->reference,
                'callback_url' => route('wallet.paystack.callback'),
                'metadata' => [
                    'user_id' => $user->id,
                    'transaction_id' => $transaction->id,
                    'funding_amount' => $transaction->amount,
                    'service_fee' => $transaction->service_fee
                ]
            ];
            
            Log::info('Paystack payload prepared', [
                'email' => $payload['email'],
                'amount' => $payload['amount'],
                'reference' => $payload['reference']
            ]);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $paystackKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)
              ->post('https://api.paystack.co/transaction/initialize', $payload);
            
            Log::info('Paystack API response', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            
            if (!$response->successful()) {
                throw new \Exception('Paystack API error: ' . $response->body());
            }
            
            $result = $response->json();
            
            if (!($result['status'] ?? false)) {
                throw new \Exception($result['message'] ?? 'Payment initialization failed');
            }
            
            // Update transaction
            $transaction->update([
                'api_reference' => $result['data']['reference'],
                'meta' => json_encode(array_merge(
                    json_decode($transaction->meta, true) ?? [],
                    [
                        'paystack_reference' => $result['data']['reference'],
                        'paystack_access_code' => $result['data']['access_code'],
                        'paystack_authorization_url' => $result['data']['authorization_url']
                    ]
                ))
            ]);
            
            Log::info('Paystack initialization successful', [
                'transaction_id' => $transaction->id,
                'paystack_ref' => $result['data']['reference'],
                'redirect_url' => $result['data']['authorization_url']
            ]);
            
            // Redirect to Paystack
            return redirect($result['data']['authorization_url']);
            
        } catch (\Exception $e) {
            Log::error('Paystack initialization failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'transaction_id' => $transaction->id ?? null
            ]);
            
            // Mark transaction as failed
            if (isset($transaction)) {
                $transaction->update([
                    'status' => 'failed',
                    'payment_status' => 'failed',
                    'status_message' => $e->getMessage()
                ]);
            }
            
            $errorMessage = app()->environment('local')
                ? 'Paystack Error: ' . $e->getMessage()
                : 'Payment initialization failed. Please try again.';
            
            return redirect()->route('wallet.fund')
                ->withErrors(['error' => $errorMessage]);
        }
    }
    
    // Helper methods
    private function getOrCreateWallet($user)
    {
        Log::info('Getting/creating wallet for user', ['user_id' => $user->id]);
        
        // Check if wallet relationship exists
        if (!method_exists($user, 'wallet')) {
            throw new \Exception('Wallet relationship not defined on User model');
        }
        
        $wallet = $user->wallet;
        
        if (!$wallet) {
            Log::info('Creating new wallet');
            $wallet = Wallet::create([
                'user_id' => $user->id,
                'balance' => 0,
                'pending_balance' => 0,
                'total_funded' => 0,
                'total_spent' => 0,
                'transaction_count' => 0,
            ]);
            Log::info('Wallet created', ['wallet_id' => $wallet->id]);
        }
        
        return $wallet;
    }
    
    private function calculateFee($amount, $method)
    {
        Log::info('Calculating fee', ['amount' => $amount, 'method' => $method]);
        
        if ($method === 'paystack') {
            $config = config('wallet.fees.paystack', [
                'percentage' => 1.5,
                'additional' => 100,
                'cap' => 2000
            ]);
            
            $percentageFee = ($amount * $config['percentage'] / 100);
            $totalFee = $percentageFee + $config['additional'];
            
            if (isset($config['cap']) && $totalFee > $config['cap']) {
                $totalFee = $config['cap'];
            }
            
            $fee = round($totalFee, 2);
            Log::info('Paystack fee calculated', ['fee' => $fee]);
            return $fee;
        }
        
        if ($method === 'bank_transfer') {
            $fee = config('wallet.fees.bank_transfer.fixed', 0);
            Log::info('Bank transfer fee', ['fee' => $fee]);
            return $fee;
        }
        
        return 0;
    }
    
    private function generateReference($prefix = 'TXN')
    {
        return $prefix . '-' . strtoupper(Str::random(8)) . '-' . time();
    }

    // Fund wallet page
    public function fund()
    {
        $user = Auth::user();
        $wallet = $this->getOrCreateWallet($user);
        
        $recent_funding = Transactions::where('user_id', $user->id)
            ->where('service_type', 'funding')
            ->whereIn('status', ['success', 'pending'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
            
        return view('wallet.fund', [
            'wallet' => $wallet,
            'bank_details' => config('wallet.bank'),
            'paystack_fee' => config('wallet.fees.paystack'),
            'bank_fee' => config('wallet.fees.bank_transfer'),
            'min_amount' => config('wallet.minimum_funding', 100),
            'max_amount' => config('wallet.maximum_funding', 1000000),
            'recent_funding' => $recent_funding
        ]);
    }
    
   
    
    // User uploads proof of bank transfer
    public function submitBankTransferProof(Request $request)
    {
        $request->validate([
            'transaction_reference' => 'required|exists:transactions,reference',
            'proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120', // 5MB
            'remarks' => 'nullable|string|max:500'
        ]);
        
        $user = Auth::user();
        
        $transaction = Transactions::where('reference', $request->transaction_reference)
            ->where('user_id', $user->id)
            ->where('payment_method', 'bank_transfer')
            ->where('status', 'pending')
            ->firstOrFail();
        
        DB::beginTransaction();
        
        try {
            // Store proof file
            $proofPath = $request->file('proof')->store(
                'payment-proofs/' . date('Y/m'), 
                'public'
            );
            
            // Update transaction to "verifying" status
            $transaction->update([
                'status' => 'verifying',
                'payment_status' => 'verifying',
                'meta' => json_encode(array_merge(
                    json_decode($transaction->meta, true) ?? [],
                    [
                        'proof_path' => $proofPath,
                        'proof_remarks' => $request->remarks,
                        'proof_submitted_at' => now()->toDateTimeString()
                    ]
                ))
            ]);
            
            DB::commit();
            
            Log::info('Bank transfer proof submitted', [
                'transaction_id' => $transaction->id,
                'user_id' => $user->id
            ]);
            
            // Notify admin for verification
            $this->notifyAdminForVerification($transaction);
            
            return redirect()->route('wallet.history')
                ->with('success', 'Payment proof submitted! We will verify and credit your wallet within 24 hours.');
                
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Proof submission failed', [
                'error' => $e->getMessage(),
                'transaction_id' => $transaction->id ?? null
            ]);
            
            return redirect()->back()
                ->withErrors(['error' => 'Unable to submit proof. Please try again.']);
        }
    }
    
    // Admin approves bank transfer (call this from admin panel)
    public function approveBankTransfer(Request $request, $transactionId)
    {
        // This should be in AdminController, but included here for completeness
        
        $transaction = Transactions::where('id', $transactionId)
            ->where('payment_method', 'bank_transfer')
            ->where('status', 'verifying')
            ->firstOrFail();
        
        DB::beginTransaction();
        
        try {
            $user = $transaction->user;
            $wallet = $this->getOrCreateWallet($user);
            
            // Credit wallet
            $newBalance = $wallet->balance + $transaction->amount;
            
            $wallet->update([
                'balance' => $newBalance,
                'total_funded' => $wallet->total_funded + $transaction->amount,
                'transaction_count' => $wallet->transaction_count + 1
            ]);
            
            // Update transaction
            $transaction->update([
                'status' => 'success',
                'payment_status' => 'success',
                'balance_after' => $newBalance, // NOW we set the final balance
                'completed_at' => now(),
                'verified_by' => Auth::id(),
                'meta' => json_encode(array_merge(
                    json_decode($transaction->meta, true) ?? [],
                    [
                        'verified_at' => now()->toDateTimeString(),
                        'verified_by' => Auth::user()->name
                    ]
                ))
            ]);
            
            DB::commit();
            
            Log::info('Bank transfer approved', [
                'transaction_id' => $transaction->id,
                'user_id' => $user->id,
                'amount' => $transaction->amount
            ]);
            
            // Notify user
            $this->notifyUserOfSuccess($user, $transaction);
            
            return redirect()->back()
                ->with('success', 'Transaction approved and wallet credited.');
                
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Bank transfer approval failed', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId
            ]);
            
            return redirect()->back()
                ->withErrors(['error' => 'Unable to approve transaction.']);
        }
    }
    
   
   // Handle Paystack callback
public function handlePaystackCallback(Request $request)
{
    $reference = $request->query('reference');
    
    if (!$reference) {
        Log::warning('Paystack callback: No reference provided');
        return redirect()->route('wallet.fund')
            ->withErrors(['error' => 'Invalid payment reference.']);
    }
    
    Log::info('Paystack callback received', ['reference' => $reference]);
    
    try {
        // Verify with Paystack
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.paystack.secret_key'),
        ])->timeout(30)
          ->get("https://api.paystack.co/transaction/verify/{$reference}");
        
        if (!$response->successful()) {
            throw new \Exception('Verification request failed: ' . $response->body());
        }
        
        $result = $response->json();
        
        if (!($result['status'] ?? false)) {
            throw new \Exception($result['message'] ?? 'Verification failed');
        }
        
        $paystackData = $result['data'];
        
        // Find transaction
        $transaction = Transactions::where(function($query) use ($reference) {
                $query->where('reference', $reference)
                      ->orWhere('api_reference', $reference);
            })
            ->where('payment_method', 'paystack')
            ->first();
        
        if (!$transaction) {
            throw new \Exception('Transaction not found: ' . $reference);
        }
        
        // IDEMPOTENCY CHECK: Prevent double-processing
        if ($transaction->status === 'success') {
            Log::info('Transaction already processed', ['transaction_id' => $transaction->id]);
            return redirect()->route('wallet.fund')
                ->with('modal_success', 'This transaction has already been completed successfully.');
        }
        
        // Process based on Paystack status
        if ($paystackData['status'] === 'success') {
            return $this->completePaystackPayment($transaction, $paystackData);
        } else {
            return $this->handlePaystackFailure($transaction, $paystackData);
        }
        
    } catch (\Exception $e) {
        Log::error('Paystack callback error', [
            'error' => $e->getMessage(),
            'reference' => $reference,
            'trace' => $e->getTraceAsString()
        ]);
        
        return redirect()->route('wallet.fund')
            ->with('modal_error', 'Payment verification failed. Please contact support if you were debited.');
    }
}
    
   private function completePaystackPayment($transaction, $paystackData)
{
    DB::beginTransaction();
    
    try {
        $user = $transaction->user;
        $user->refresh(); // Get fresh data
        
        $wallet = $this->getOrCreateWallet($user);
        $wallet->refresh(); // Get fresh wallet data
        
        // Get current balance
        $currentBalance = $wallet->balance;
        
        // Credit wallet
        $newBalance = $currentBalance + $transaction->amount;
        
        $wallet->update([
            'balance' => $newBalance,
            'total_funded' => $wallet->total_funded + $transaction->amount,
            'transaction_count' => $wallet->transaction_count + 1
        ]);
        
        // Update user
        $user->update(['wallet_balance' => $newBalance]);
        
        // Update transaction with ACCURATE balances
        $transaction->update([
            'status' => 'success',
            'payment_status' => 'success',
            'balance_before' => $currentBalance,  // Accurate before
            'balance_after' => $newBalance,       // Accurate after
            'paid_at' => now(),
            'completed_at' => now(),
            'api_response' => json_encode($paystackData),
            'meta' => json_encode(array_merge(
                json_decode($transaction->meta, true) ?? [],
                [
                    'paystack_status' => 'success',
                    'gateway_response' => $paystackData['gateway_response'] ?? null,
                    'channel' => $paystackData['channel'] ?? null,
                    'paid_at' => $paystackData['paid_at'] ?? null,
                    'completed_at' => now()->toDateTimeString()
                ]
            ))
        ]);
        
        DB::commit();
        
        Log::info('Paystack payment completed', [
            'transaction_id' => $transaction->id,
            'user_id' => $user->id,
            'amount' => $transaction->amount,
            'balance_before' => $currentBalance,
            'balance_after' => $newBalance
        ]);
        
        $this->notifyUserOfSuccess($user, $transaction);
        
        return redirect()->route('wallet.fund')
            ->with('modal_success', 'Payment successful! ₦' . number_format($transaction->amount, 2) . ' has been added to your wallet.');
            
    } catch (\Exception $e) {
        DB::rollBack();
        
        Log::error('Payment completion failed', [
            'error' => $e->getMessage(),
            'transaction_id' => $transaction->id
        ]);
        
        return redirect()->route('wallet.fund')
            ->with('modal_error', 'Unable to complete payment. Please contact support.');
    }
}
    
    private function handlePaystackFailure($transaction, $paystackData)
{
    $transaction->update([
        'status' => 'failed',
        'payment_status' => 'failed',
        'status_message' => $paystackData['gateway_response'] ?? 'Payment failed',
        'api_response' => json_encode($paystackData),
        'meta' => json_encode(array_merge(
            json_decode($transaction->meta, true) ?? [],
            [
                'paystack_status' => 'failed',
                'gateway_response' => $paystackData['gateway_response'] ?? null,
                'failed_at' => now()->toDateTimeString()
            ]
        ))
    ]);
    
    Log::info('Paystack payment failed', [
        'transaction_id' => $transaction->id,
        'reason' => $paystackData['gateway_response'] ?? 'Unknown'
    ]);
    
    // Redirect back to funding page with error modal
    return redirect()->route('wallet.fund')
        ->with('modal_error', 'Payment failed: ' . ($paystackData['gateway_response'] ?? 'Unknown error'));
}
    
   
    
   
    // ========================================
    // VIEW METHODS
    // ========================================
    
    public function index()
    {
        $user = Auth::user();
        $wallet = $this->getOrCreateWallet($user);
        
        $recent_transactions = Transactions::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();
            
        $today = Carbon::today();
        $monthlyStats = [
            'spent' => Transactions::where('user_id', $user->id)
                ->where('type', 'debit')
                ->where('status', 'success')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('amount'),
            
            'funded' => Transactions::where('user_id', $user->id)
                ->where('service_type', 'wallet_funding')
                ->where('status', 'success')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('amount'),
            
            'transactions' => Transactions::where('user_id', $user->id)
                ->where('status', 'success')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),

            'today_volume' => Transactions::where('user_id', $user->id)
            ->where('status', 'success')
            ->whereDate('created_at', $today)
            ->sum('amount') ?? 0,
        ];
        
        return view('wallet.index', compact('wallet', 'recent_transactions', 'monthlyStats'));
    }
    
    public function history(Request $request)
    {
        $user = Auth::user();
        $wallet = $this->getOrCreateWallet($user);
        
        $query = Transactions::where('user_id', $user->id);
        
        // Apply filters
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }
        
        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
                $q->where('reference', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%")
                  ->orWhere('api_reference', 'like', "%{$request->search}%");
            });
        }
        
        $transactions = $query->orderBy('created_at', 'desc')->paginate(20);
        
        return view('wallet.history', compact('wallet', 'transactions'));
    }
    
    // ========================================
    // NOTIFICATION METHODS
    // ========================================
    
    private function notifyUserOfSuccess($user, $transaction)
    {
        try {
            // Implement your notification logic
            // $user->notify(new WalletFunded($transaction));
            Log::info('Success notification sent', ['user_id' => $user->id]);
        } catch (\Exception $e) {
            Log::error('Notification failed', ['error' => $e->getMessage()]);
        }
    }
    
    private function notifyAdminForVerification($transaction)
    {
        try {
            // Implement admin notification
            // Admin::notify(new BankTransferProofSubmitted($transaction));
            Log::info('Admin notified for verification', ['transaction_id' => $transaction->id]);
        } catch (\Exception $e) {
            Log::error('Admin notification failed', ['error' => $e->getMessage()]);
        }
    }

    // Add this to WalletController
public function showBankTransferDetails(Request $request)
{
    $transaction = Transactions::where('reference', $request->query('ref'))
        ->where('user_id', Auth::id())
        ->where('payment_method', 'bank_transfer')
        ->where('status', 'pending')
        ->firstOrFail();
    
    return view('wallet.bank-transfer', [
        'transaction' => $transaction,
        'bank_details' => config('wallet.bank')
    ]);
}

public function paymentStatus(Request $request)
{
    $reference = $request->query('reference');
    $status = $request->query('status', 'pending');
    $message = $request->query('message', 'Processing your payment...');
    
    if (!$reference) {
        return redirect()->route('wallet.fund')
            ->with('error', 'Payment reference not found.');
    }
    
    // Try to get transaction
    $transaction = Transactions::where(function($query) use ($reference) {
            $query->where('reference', $reference)
                  ->orWhere('api_reference', $reference);
        })
        ->where('user_id', Auth::id())
        ->first();
    
    return view('wallet.payment-status', [
        'reference' => $reference,
        'status' => $status,
        'message' => $message,
        'transaction' => $transaction
    ]);
}

/**
 * Check payment status via AJAX
 */
public function checkPaymentStatus(Request $request)
{
    $request->validate([
        'reference' => 'required|string'
    ]);
    
    $transaction = Transactions::where(function($query) use ($request) {
            $query->where('reference', $request->reference)
                  ->orWhere('api_reference', $request->reference);
        })
        ->where('user_id', Auth::id())
        ->first();
    
    if (!$transaction) {
        return response()->json([
            'status' => 'not_found',
            'message' => 'Transaction not found'
        ]);
    }
    
    return response()->json([
        'status' => $transaction->status,
        'payment_status' => $transaction->payment_status,
        'amount' => $transaction->amount,
        'currency' => '₦',
        'message' => $this->getStatusMessage($transaction->status),
        'redirect_url' => route('wallet.history')
    ]);
}

private function getStatusMessage($status)
{
    $messages = [
        'success' => 'Payment successful! Your wallet has been credited.',
        'pending' => 'Payment is being processed. Please wait...',
        'verifying' => 'Payment is being verified.',
        'failed' => 'Payment failed. Please try again.',
        'cancelled' => 'Payment was cancelled.'
    ];
    
    return $messages[$status] ?? 'Payment status: ' . ucfirst($status);
}
}