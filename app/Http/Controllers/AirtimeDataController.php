<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transactions;
use App\Services\ClubKonnectService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Mail\TransactionReceiptMail;
use App\Mail\AdminTransactionNotification;
use Illuminate\Support\Facades\Mail;

class AirtimeDataController extends Controller
{
    protected ClubKonnectService $clubKonnect;

    public function __construct(ClubKonnectService $clubKonnect)
    {
        $this->clubKonnect = $clubKonnect;
    }

    public function index()
    {
        $user = Auth::user();
        $recentTransactions = Transactions::where('user_id', $user->id)
            ->whereIn('service_type', ['airtime', 'data'])
            ->latest()
            ->limit(5)
            ->get();

        return view('airtime-data.index', [
            'user' => $user,
            'recentTransactions' => $recentTransactions,
        ]);
    }
    
    /**
     * Handle Airtime Purchase
     */
    public function purchaseAirtime(Request $request)
    {
        $validated = $request->validate([
            'network' => 'required|string|in:01,02,03,04',
            'phone' => 'required|string|regex:/^0[7-9][0-9]{9}$/',
            'amount' => 'required|numeric|min:100|max:10000',
        ]);

        $user = Auth::user();
        
        // Calculate amounts
        $amount = $validated['amount'];
        $serviceFee = Transactions::calculateServiceFee('airtime', $amount);
        $totalAmount = $amount + $serviceFee;
        
        // Check balance
        if ($user->wallet_balance < $totalAmount) {
            return redirect()->back()
                ->with('error', "Insufficient balance. You need ₦" . number_format($totalAmount, 2) . " but have ₦" . number_format($user->wallet_balance, 2))
                ->withInput();
        }

        DB::beginTransaction();
        
        try {
            // Create transaction
            $transaction = $this->createTransaction([
                'user_id' => $user->id,
                'type' => 'debit',
                'service_type' => 'airtime',
                'description' => 'Airtime purchase - ' . $this->getNetworkName($validated['network']),
                'amount' => $amount,
                'service_fee' => $serviceFee,
                'total_amount' => $totalAmount,
                'balance_before' => $user->wallet_balance,
                'recipient' => $validated['phone'],
                'provider' => $this->getNetworkName($validated['network']),
                'payment_method' => 'wallet',
                'status' => 'pending',
                'meta' => [
                    'network_code' => $validated['network'],
                    'phone_number' => $validated['phone'],
                    'request_amount' => $amount,
                ],
            ]);

            // Mark as processing
            $transaction->update(['status' => 'processing']);

            // Call API
            $response = $this->clubKonnect->purchaseAirtime(
                $validated['network'],
                $validated['phone'],
                $amount,
                $transaction->reference
            );
            
            Log::info('Airtime API Response', [
                'transaction_id' => $transaction->id,
                'response' => $response
            ]);
            
            // Check if successful using helper method
            if ($this->clubKonnect->isSuccessResponse($response)) {
                // Deduct from wallet
                $this->deductFromWallet($transaction);
                
                // Get order reference
                $orderRef = $response['orderid'] ?? $response['OrderID'] ?? $response['order_id'] ?? null;
                
                // Mark as successful
                $transaction->update([
                    'status' => 'success',
                    'payment_status' => 'success',
                    'api_reference' => $orderRef,
                    'api_response' => $response,
                    'completed_at' => now(),
                ]);
                
                DB::commit();
                // In purchaseAirtime method, after marking as success:
if ($this->clubKonnect->isSuccessResponse($response)) {
    // Deduct from wallet
    $this->deductFromWallet($transaction);
    
    $orderRef = $response['orderid'] ?? $response['OrderID'] ?? $response['order_id'] ?? null;
    
    $transaction->update([
        'status' => 'success',
        'payment_status' => 'success',
        'api_reference' => $orderRef,
        'api_response' => $response,
        'completed_at' => now(),
    ]);
    
    // Send receipt email to user
    try {
        Mail::to($user->email)->send(new TransactionReceiptMail($transaction));
        
        // Send notification to admin
        Mail::to(['reup.bellahoptions@gmail.com', 'reup@bellahoptions.com'])
            ->send(new AdminTransactionNotification($transaction));
    } catch (\Exception $e) {
        Log::error('Email sending failed', ['error' => $e->getMessage()]);
    }
    
    DB::commit();
    
    return redirect()->route('transaction.success', $transaction->reference)
        ->with('success', 'Airtime purchase successful! ₦' . number_format($amount, 2) . ' sent to ' . $validated['phone']);
}
                
                return redirect()->route('transaction.success', $transaction->reference)
                    ->with('success', 'Airtime purchase successful! ₦' . number_format($amount, 2) . ' sent to ' . $validated['phone']);
            } else {
                // Transaction failed
                DB::rollBack();
                
                $errorMessage = $this->clubKonnect->getErrorMessage($response);
                
                $transaction->update([
                    'status' => 'failed',
                    'payment_status' => 'failed',
                    'failure_reason' => $errorMessage,
                    'api_response' => $response,
                    'completed_at' => now(),
                ]);
                
                return redirect()->route('transaction.failed', $transaction->reference)
                    ->with('error', $errorMessage);
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Airtime Purchase Exception', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'trace' => $e->getTraceAsString()
            ]);
            
            if (isset($transaction)) {
                $transaction->update([
                    'status' => 'failed',
                    'failure_reason' => 'System error: ' . $e->getMessage()
                ]);
            }
            
            return redirect()->back()
                ->with('error', 'An error occurred. Please try again.')
                ->withInput();
        }
    }
    
    /**
     * Handle Data Purchase
     */
    public function purchaseData(Request $request)
    {
        $validated = $request->validate([
            'data_network' => 'required|string|in:01,02,03,04',
            'data_plan' => 'required|string',
            'phone' => 'required|string|regex:/^0[7-9][0-9]{9}$/',
            'plan_name' => 'required|string',
            'plan_price' => 'required|numeric',
            'plan_type' => 'nullable|string',
        ]);
        
        $user = Auth::user();
        
        // Calculate amounts
        $amount = $validated['plan_price'];
        $serviceFee = Transactions::calculateServiceFee('data', $amount);
        $totalAmount = $amount + $serviceFee;
        
        // Check balance
        if ($user->wallet_balance < $totalAmount) {
            return redirect()->back()
                ->with('error', "Insufficient balance. You need ₦" . number_format($totalAmount, 2) . " but have ₦" . number_format($user->wallet_balance, 2))
                ->withInput();
        }

        DB::beginTransaction();
        
        try {
            // Create transaction
            $transaction = $this->createTransaction([
                'user_id' => $user->id,
                'type' => 'debit',
                'service_type' => 'data',
                'description' => 'Data purchase - ' . $validated['plan_name'],
                'amount' => $amount,
                'service_fee' => $serviceFee,
                'total_amount' => $totalAmount,
                'balance_before' => $user->wallet_balance,
                'recipient' => $validated['phone'],
                'provider' => $this->getNetworkName($validated['data_network']),
                'plan_name' => $validated['plan_name'],
                'plan_type' => $validated['plan_type'] ?? 'Direct Data',
                'payment_method' => 'wallet',
                'status' => 'pending',
                'meta' => [
                    'network_code' => $validated['data_network'],
                    'phone_number' => $validated['phone'],
                    'plan_id' => $validated['data_plan'],
                    'plan_type' => $validated['plan_type'] ?? 'Direct Data',
                ],
            ]);

            // Mark as processing
            $transaction->update(['status' => 'processing']);

            // Call API
            $response = $this->clubKonnect->purchaseData(
                $validated['data_network'],
                $validated['data_plan'],
                $validated['phone'],
                $transaction->reference
            );
            
            Log::info('Data API Response', [
                'transaction_id' => $transaction->id,
                'response' => $response
            ]);
            
            // Check if successful
            if ($this->clubKonnect->isSuccessResponse($response)) {
                // Deduct from wallet
                $this->deductFromWallet($transaction);
                
                // Get order reference
                $orderRef = $response['orderid'] ?? $response['OrderID'] ?? $response['order_id'] ?? null;
                
                // Mark as successful
                $transaction->update([
                    'status' => 'success',
                    'payment_status' => 'success',
                    'api_reference' => $orderRef,
                    'api_response' => $response,
                    'completed_at' => now(),
                ]);
                
                DB::commit();
                
                return redirect()->route('transaction.success', $transaction->reference)
                    ->with('success', 'Data bundle purchased successfully! ' . $validated['plan_name'] . ' sent to ' . $validated['phone']);
            } else {
                // Transaction failed
                DB::rollBack();
                
                $errorMessage = $this->clubKonnect->getErrorMessage($response);
                
                $transaction->update([
                    'status' => 'failed',
                    'payment_status' => 'failed',
                    'failure_reason' => $errorMessage,
                    'api_response' => $response,
                    'completed_at' => now(),
                ]);
                
                return redirect()->route('transaction.failed', $transaction->reference)
                    ->with('error', $errorMessage);
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Data Purchase Exception', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'trace' => $e->getTraceAsString()
            ]);
            
            if (isset($transaction)) {
                $transaction->update([
                    'status' => 'failed',
                    'failure_reason' => 'System error: ' . $e->getMessage()
                ]);
            }
            
            return redirect()->back()
                ->with('error', 'An error occurred. Please try again.')
                ->withInput();
        }
    }
    
   /**
 * Create transaction record
 */
private function createTransaction(array $data): Transactions
{
    // Get FRESH wallet balance at creation time
    $user = User::find($data['user_id']);
    
    return Transactions::create(array_merge([
        'reference' => Transactions::generateReference('TXN'),
        'balance_before' => $user->wallet_balance, // Current balance
        'balance_after' => $user->wallet_balance,   // Will be updated later
        'payment_status' => 'pending',
    ], $data));
}

/**
 * Deduct from wallet
 */
private function deductFromWallet(Transactions $transaction): void
{
    $user = $transaction->user;
    $wallet = $user->wallet;
    
    // CRITICAL: Get fresh balance before deduction
    $user->refresh();
    $currentBalance = $user->wallet_balance;
    
    // Calculate new balance
    $newBalance = $currentBalance - $transaction->total_amount;
    
    // Prevent negative balance
    if ($newBalance < 0) {
        throw new \Exception('Insufficient funds. Current balance: ₦' . number_format($currentBalance, 2));
    }
    
    // Update user wallet
    $user->update(['wallet_balance' => $newBalance]);
    
    // Update wallet model
    if ($wallet) {
        $wallet->update([
            'balance' => $newBalance,
            'total_spent' => $wallet->total_spent + $transaction->total_amount,
            'transaction_count' => $wallet->transaction_count + 1,
        ]);
    }
    
    // Update transaction with ACCURATE balances
    $transaction->update([
        'balance_before' => $currentBalance,  // Update with fresh balance
        'balance_after' => $newBalance,
        'payment_status' => 'success',
        'paid_at' => now(),
    ]);
}
    
    /**
     * Get network name
     */
    private function getNetworkName(string $code): string
    {
        $networks = [
            '01' => 'MTN',
            '02' => 'Glo',
            '03' => '9Mobile',
            '04' => 'Airtel',
        ];
        
        return $networks[$code] ?? 'Unknown Network';
    }
    
    public function success(string $reference)
    {
        $transaction = Transactions::where('reference', $reference)
            ->where('user_id', Auth::id())
            ->firstOrFail();
            
        return view('transactions.success', compact('transaction'));
    }
    
    public function failed(string $reference)
    {
        $transaction = Transactions::where('reference', $reference)
            ->where('user_id', Auth::id())
            ->firstOrFail();
            
        return view('transactions.failed', compact('transaction'));
    }
}