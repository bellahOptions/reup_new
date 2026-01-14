<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PaystackController extends Controller
{
    private $secretKey;
    
    public function __construct()
    {
        $this->secretKey = config('services.paystack.secret_key');
        
        Log::info('PaystackController initialized', [
            'key_set' => !empty($this->secretKey),
            'key_prefix' => substr($this->secretKey ?? '', 0, 10) . '...'
        ]);
    }
    
    /**
     * CALLBACK: User redirect after payment
     */
    public function callback(Request $request)
    {
        $reference = $request->query('reference');

        if (!$reference) {
            return redirect()->route('wallet.fund')
                ->with('error', 'Payment reference missing.');
        }

        $response = Http::withToken($this->secretKey)
            ->get("https://api.paystack.co/transaction/verify/{$reference}");

        if (!$response->successful()) {
            return redirect()->route('wallet.fund')
                ->with('error', 'Unable to verify payment.');
        }

        $data = $response->json('data');

        if ($data['status'] !== 'success') {
            return redirect()->route('wallet.fund')
                ->with('error', 'Payment not successful.');
        }

        return redirect()->route('wallet.success')
            ->with('success', 'Payment received. Wallet will be credited shortly.');
    }

    /**
     * WEBHOOK: Paystack server notification
     */
    public function webhook(Request $request)
    {
        $signature = $request->header('x-paystack-signature');

        if (!$signature) {
            abort(400, 'Invalid signature');
        }

        $computedSignature = hash_hmac(
            'sha512',
            $request->getContent(),
            $this->secretKey
        );

        if (!hash_equals($signature, $computedSignature)) {
            abort(401, 'Invalid Paystack signature');
        }

        $event = $request->input('event');
        $data = $request->input('data');

        if ($event === 'charge.success') {
            $this->handleSuccessfulCharge($data);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle successful payment
     */
    protected function handleSuccessfulCharge(array $data)
    {
        $reference = $data['reference'];
        $amount = $data['amount'] / 100;
        $email = $data['customer']['email'];

        $transaction = \App\Models\Transaction::where('reference', $reference)->first();

        if (!$transaction || $transaction->status === 'completed') {
            return;
        }

        $wallet = $transaction->user->wallet;

        $wallet->increment('balance', $amount);
        $wallet->increment('total_funded', $amount);
        $wallet->increment('transaction_count');

        $transaction->update([
            'status' => 'completed',
            'paid_at' => now(),
        ]);
    }

    /**
     * Get Paystack balance - NO AJAX, called on page load only
     * Uses cache to reduce API calls (5 minute cache)
     */
    public function getBalance()
    {
        Log::info('Fetching Paystack balance', [
            'key_available' => !empty($this->secretKey),
            'cache_key' => 'paystack_balance'
        ]);
        
        try {
            // Use 5 minute cache to reduce API calls
            return Cache::remember('paystack_balance', 300, function () {
                return $this->fetchBalanceWithCurl();
            });
            
        } catch (\Exception $e) {
            Log::error('Failed to fetch Paystack balance: ' . $e->getMessage(), [
                'key_set' => !empty($this->secretKey),
                'error_trace' => $e->getTraceAsString()
            ]);
            
            return [
                'amount' => 0,
                'currency' => 'NGN',
                'balance_in_kobo' => 0,
                'ledger_balance' => 0,
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Fetch balance using cURL (Paystack official method)
     */
    private function fetchBalanceWithCurl()
    {
        Log::debug('Starting cURL request to Paystack balance endpoint');
        
        $secretKey = $this->secretKey;
        
        if (!$secretKey) {
            throw new \Exception('Paystack secret key not configured in .env file as PAYSTACK_SECRET_KEY');
        }
        
        $curl = curl_init();
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.paystack.co/balance",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer " . $secretKey,
                "Cache-Control: no-cache",
            ),
        ));
        
        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
        curl_close($curl);
        
        Log::debug('Paystack balance response', [
            'http_code' => $httpCode,
            'curl_error' => $err,
            'response_length' => strlen($response),
            'response_preview' => substr($response, 0, 200)
        ]);
        
        if ($err) {
            throw new \Exception("cURL Error: " . $err);
        }
        
        $data = json_decode($response, true);
        
        if ($httpCode !== 200) {
            throw new \Exception("HTTP Error {$httpCode}: " . ($data['message'] ?? 'Unknown error'));
        }
        
        if (!isset($data['status']) || $data['status'] !== true) {
            throw new \Exception($data['message'] ?? 'Failed to fetch balance');
        }
        
        if (!isset($data['data'][0])) {
            throw new \Exception('No balance data returned from Paystack');
        }
        
        $balanceInKobo = $data['data'][0]['balance'] ?? 0;
        $balanceInNaira = $balanceInKobo / 100;
        
        Log::info('Paystack balance fetched successfully', [
            'balance' => $balanceInNaira,
            'currency' => $data['data'][0]['currency'] ?? 'NGN'
        ]);
        
        return [
            'amount' => $balanceInNaira,
            'currency' => $data['data'][0]['currency'] ?? 'NGN',
            'balance_in_kobo' => $balanceInKobo,
            'ledger_balance' => ($data['data'][0]['ledger_balance'] ?? 0) / 100,
            'success' => true,
            'raw_response' => $data
        ];
    }
    
    /**
     * Get transaction statistics - NO AJAX, called on page load only
     * Uses cache to reduce API calls (5 minute cache)
     */
    public function getTransactionStats($period = 'today')
    {
        try {
            $cacheKey = 'paystack_stats_' . $period;
            return Cache::remember($cacheKey, 300, function () use ($period) {
                return $this->fetchTransactionStatsWithCurl($period);
            });
            
        } catch (\Exception $e) {
            Log::error('Failed to fetch Paystack stats: ' . $e->getMessage());
            return [
                'success' => false,
                'total_transactions' => 0,
                'unique_customers' => 0,
                'total_volume' => 0,
                'pending_transfers' => 0,
                'total_transfers' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Fetch transaction stats using cURL
     */
    private function fetchTransactionStatsWithCurl($period)
    {
        $secretKey = $this->secretKey;
        $today = date('Y-m-d');
        
        if (!$secretKey) {
            throw new \Exception('Paystack secret key not configured');
        }
        
        $fromDate = $period === 'today' ? $today : date('Y-m-d', strtotime('-30 days'));
        
        $curl = curl_init();
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.paystack.co/transaction/totals?from=" . $fromDate . "&to=" . $today,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer " . $secretKey,
                "Cache-Control: no-cache",
            ),
        ));
        
        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
        curl_close($curl);
        
        if ($err) {
            throw new \Exception("cURL Error: " . $err);
        }
        
        $data = json_decode($response, true);
        
        if ($httpCode !== 200) {
            throw new \Exception("HTTP Error {$httpCode}: " . ($data['message'] ?? 'Unknown error'));
        }
        
        if (!isset($data['status']) || $data['status'] !== true) {
            throw new \Exception($data['message'] ?? 'Failed to fetch transaction stats');
        }
        
        return [
            'success' => true,
            'total_transactions' => $data['data']['total_transactions'] ?? 0,
            'unique_customers' => $data['data']['unique_customers'] ?? 0,
            'total_volume' => ($data['data']['total_volume'] ?? 0) / 100,
            'total_volume_by_currency' => $data['data']['total_volume_by_currency'] ?? [],
            'pending_transfers' => ($data['data']['pending_transfers'] ?? 0) / 100,
            'total_transfers' => ($data['data']['total_transfers'] ?? 0) / 100,
            'raw_response' => $data
        ];
    }
    
    // REMOVED AJAX METHODS:
    // - getBalanceHtml() - No longer needed, use page refresh
    // - refreshBalance() - No longer needed, use page refresh
    
    // Note: To get fresh Paystack data, users should refresh the page (F5)
    // The cache will automatically expire after 5 minutes
}