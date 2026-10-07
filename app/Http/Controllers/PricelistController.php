<?php

namespace App\Http\Controllers;

use App\Models\Transactions;
use App\Services\BillPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PricelistController extends Controller
{
    private $profitMargin = 0.015; // 1.5% profit

    public function __construct(private readonly BillPaymentService $bills)
    {
    }

    // Network mapping based on the API response
    private $networkMapping = [
        '01' => 'MTN',
        '02' => 'GLO',
        '03' => '9MOBILE',
        '04' => 'AIRTEL'
    ];
    
    public function index()
    {
        // Cache for 10 minutes (600 seconds)
        $data = Cache::remember('clubkonnect_data_plans', 600, function () {
            return $this->fetchDataPlans();
        });
        
        return view('pricelist.index', compact('data'));
    }
    
    private function fetchDataPlans()
    {
        try {
            // The config key is `client_id`; this previously read `user_id`,
            // which does not exist, so every catalogue fetch went upstream
            // unauthenticated and returned nothing.
            $userId = config('services.clubkonnect.client_id');

            if (empty($userId)) {
                throw new \Exception('Airtime provider is not configured.');
            }

            // Fetch data plans from the upstream catalogue
            $response = Http::timeout(30)->get('https://www.nellobytesystems.com/APIDatabundlePlansV2.asp', [
                'UserID' => $userId,
            ]);

            if (!$response->successful()) {
                throw new \Exception('Catalogue request failed with status ' . $response->status());
            }

            $apiData = $response->json();

            $processedPlans = $this->processPlans($apiData);

            $payload = [
                'data_plans' => $processedPlans,
                'last_updated' => now()->toDateTimeString(),
                'total_plans' => count($processedPlans),
            ];

            // Keep a fallback copy so a transient upstream outage does not
            // empty the price list.
            Cache::put('clubkonnect_data_plans_fallback', $payload, 86400);

            return $payload;

        } catch (\Exception $e) {
            Log::error('Data plan catalogue fetch failed: ' . $e->getMessage());

            return Cache::get('clubkonnect_data_plans_fallback', [
                'data_plans' => [],
                'last_updated' => null,
                'total_plans' => 0,
                'error' => 'Live rates are temporarily unavailable. Please try again shortly.',
            ]);
        }
    }
    
    private function processPlans($apiData)
    {
        $processedPlans = [];
        
        if (empty($apiData) || !isset($apiData['MOBILE_NETWORK'])) {
            Log::error('Invalid API response structure:', $apiData);
            return $processedPlans;
        }
        
        // Process each network
        foreach ($apiData['MOBILE_NETWORK'] as $networkKey => $networkDataArray) {
            // Get network name
            $networkName = $this->normalizeNetworkName($networkKey);
            
            // Each network is an array with one element
            if (!is_array($networkDataArray) || empty($networkDataArray)) {
                continue;
            }
            
            // Get the first (and only) element from the array
            $networkData = $networkDataArray[0];
            
            if (!isset($networkData['ID']) || !isset($networkData['PRODUCT'])) {
                Log::warning("Missing ID or PRODUCT for network: {$networkKey}");
                continue;
            }
            
            $networkCode = $networkData['ID'];
            $networkName = $this->networkMapping[$networkCode] ?? $networkName;
            
            foreach ($networkData['PRODUCT'] as $product) {
                // Extract product details
                $basePrice = floatval($product['PRODUCT_AMOUNT'] ?? 0);
                $productId = $product['PRODUCT_ID'] ?? '';
                $productName = $product['PRODUCT_NAME'] ?? '';
                $productCode = $product['PRODUCT_CODE'] ?? '';
                
                // Skip if price is zero or invalid
                if ($basePrice <= 0) {
                    continue;
                }
                
                // Extract plan details from product name
                $planDetails = $this->extractPlanDetails($productName);
                
                // Calculate price with 1.5% profit
                $yourPrice = $this->calculateWithProfit($basePrice);
                $profitAmount = $yourPrice - $basePrice;
                
                // Determine plan type
                $planType = $this->determinePlanType($productName);
                
                $processedPlans[] = [
                    'network_code' => $networkCode,
                    'network' => $networkName,
                    'plan_id' => $productId,
                    'plan_code' => $productCode,
                    'plan_name' => $productName,
                    'data_volume' => $planDetails['data_volume'] ?? 'N/A',
                    'validity' => $planDetails['validity'] ?? 'N/A',
                    'plan_type' => $planType,
                    'clubkonnect_price' => $basePrice,
                    'your_price' => $yourPrice,
                    'profit_margin' => '1.5%',
                    'profit_amount' => $profitAmount,
                    'sort_key' => $this->createSortKey($basePrice, $networkName),
                ];
            }
        }
        
        // Sort plans: by network, then by price
        usort($processedPlans, function($a, $b) {
            if ($a['network'] === $b['network']) {
                return $a['clubkonnect_price'] <=> $b['clubkonnect_price'];
            }
            return $a['network'] <=> $b['network'];
        });
        
        Log::info('Total processed plans:', ['count' => count($processedPlans)]);
        
        return $processedPlans;
    }
    
    private function normalizeNetworkName($networkKey)
    {
        $mapping = [
            'm_9mobile' => '9MOBILE',
            'Airtel' => 'AIRTEL',
            'MTN' => 'MTN',
            'Glo' => 'GLO',
        ];
        
        return $mapping[$networkKey] ?? strtoupper($networkKey);
    }
    
    private function extractPlanDetails($productName)
    {
        $details = [
            'data_volume' => 'N/A',
            'validity' => 'N/A',
        ];
        
        // Extract data volume (e.g., 500 MB, 1 GB, 2GB)
        if (preg_match('/(\d+(\.\d+)?)\s*(GB|MB|TB)/i', $productName, $matches)) {
            $details['data_volume'] = $matches[1] . ' ' . strtoupper($matches[3]);
        }
        
        // Extract validity (e.g., 7 days, 30 days, 1 day)
        if (preg_match('/(\d+)\s*(day|days|month|months|year|years)/i', $productName, $matches)) {
            $days = intval($matches[1]);
            $unit = strtolower($matches[2]);
            
            if ($days == 1) {
                $details['validity'] = "{$days} day";
            } elseif ($days < 30) {
                $details['validity'] = "{$days} days";
            } elseif ($days == 30) {
                $details['validity'] = "30 days";
            } elseif ($days == 60) {
                $details['validity'] = "60 days";
            } elseif ($days == 90) {
                $details['validity'] = "90 days";
            } elseif ($days == 180) {
                $details['validity'] = "180 days";
            } elseif ($days == 365) {
                $details['validity'] = "365 days";
            } else {
                $details['validity'] = "{$days} {$unit}";
            }
        }
        
        return $details;
    }
    
    private function determinePlanType($productName)
    {
        $productName = strtoupper($productName);
        
        if (strpos($productName, 'SME') !== false) {
            return 'SME Data';
        } elseif (strpos($productName, 'AWOOF') !== false) {
            return 'Awoof Data';
        } elseif (strpos($productName, 'DIRECT') !== false) {
            return 'Direct Data';
        } elseif (strpos($productName, 'NIGHT') !== false) {
            return 'Night Plan';
        } elseif (strpos($productName, 'WEEKEND') !== false) {
            return 'Weekend Plan';
        } elseif (strpos($productName, 'DAILY') !== false) {
            return 'Daily Plan';
        } elseif (strpos($productName, 'WEEKLY') !== false) {
            return 'Weekly Plan';
        } elseif (strpos($productName, 'MONTHLY') !== false) {
            return 'Monthly Plan';
        } else {
            return 'Direct Data';
        }
    }
    
    private function createSortKey($price, $network)
    {
        $networkOrder = ['MTN' => 1, 'AIRTEL' => 2, 'GLO' => 3, '9MOBILE' => 4];
        $networkValue = $networkOrder[$network] ?? 99;
        
        return sprintf('%02d-%010d', $networkValue, $price * 100);
    }
    
    private function calculateWithProfit($price)
    {
        $price = floatval($price);
        if ($price <= 0) return 0;
        return round($price * (1 + $this->profitMargin), 2);
    }
    
    // API endpoint for AJAX calls
    public function api()
    {
        $data = $this->fetchDataPlans();
        return response()->json($data);
    }
    
    // Manual refresh endpoint
    public function refresh()
    {
        Cache::forget('clubkonnect_data_plans');
        Cache::forget('clubkonnect.data_plans');

        return redirect()->route('pricelist')
            ->with('success', 'Rates refreshed from the provider.');
    }

    // Get plan details for purchase
    public function getPlan($planCode)
    {
        $plan = $this->findPlan($planCode);

        return $plan
            ? response()->json($plan)
            : response()->json(['error' => 'Plan not found'], 404);
    }

    /**
     * Resolve a plan from the server-side catalogue by product code or id.
     *
     * This is the only authoritative price. A request must never be able to
     * tell us what a plan costs — the pricelist markup previously carried
     * `plan_price` from a data attribute and posted it back, so editing the
     * page in devtools bought a ₦20,000 bundle for ₦1.
     */
    private function findPlan(string $planCode): ?array
    {
        $plans = Cache::remember('clubkonnect_data_plans', 600, fn () => $this->fetchDataPlans());

        foreach ($plans['data_plans'] ?? [] as $plan) {
            if ((string) ($plan['plan_code'] ?? '') === $planCode
                || (string) ($plan['plan_id'] ?? '') === $planCode) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Buy a data bundle from the pricelist.
     *
     * The plan and its price are resolved server-side, the wallet is debited
     * through BillPaymentService, and the upstream call happens afterwards with
     * a compensating refund on failure.
     */
    public function purchaseData(Request $request)
    {
        $validated = $request->validate([
            'plan_code' => 'required|string|max:60',
            'phone' => ['required', 'string', 'regex:/^0[7-9][0-9]{9}$/'],
            'pin' => 'required|string|size:4',
            'idempotency_key' => 'nullable|string|min:8|max:64',
        ]);

        $plan = $this->findPlan($validated['plan_code']);

        if (! $plan) {
            return back()->withInput()
                ->with('error', 'That data plan is no longer available. Please refresh the pricelist and try again.');
        }

        $networkCode = (string) ($plan['network_code'] ?? '');
        $amount = round((float) ($plan['your_price'] ?? 0), 2);

        if ($amount <= 0 || $networkCode === '') {
            return back()->withInput()
                ->with('error', 'That data plan has no valid price. Please contact support.');
        }

        $user = Auth::user();

        $result = $this->bills->purchase(
            user: $user,
            product: 'data',
            amount: $amount,
            fee: (float) Transactions::calculateServiceFee('data', $amount),
            recipient: $validated['phone'],
            providerLabel: $plan['network'] ?? $this->networkMapping[$networkCode] ?? 'Unknown',
            description: 'Data — ' . ($plan['plan_name'] ?? $plan['plan_code']),
            meta: [
                'network_code' => $networkCode,
                'plan_id' => $plan['plan_id'] ?? null,
                'plan_code' => $plan['plan_code'] ?? null,
                'plan_name' => $plan['plan_name'] ?? null,
                'plan_type' => $plan['plan_type'] ?? null,
                'phone_number' => $validated['phone'],
                'source' => 'pricelist',
            ],
            dispatch: fn ($provider, Transactions $transaction) => $provider->purchase(
                'data',
                [
                    'network' => $networkCode,
                    'plan' => (string) ($plan['plan_id'] ?? $plan['plan_code']),
                    'phone' => $validated['phone'],
                ],
                $transaction->reference,
            ),
            successMessage: ($plan['plan_name'] ?? 'Data bundle') . ' sent to ' . $validated['phone'] . '.',
            pin: $validated['pin'],
            idempotencyKey: $validated['idempotency_key'] ?? null,
        );

        if (! $result['ok']) {
            return back()->withInput()->with('error', $result['message']);
        }

        $this->bills->sendReceipts($user, $result['transaction']);

        return redirect()->route('transactions.success', $result['transaction']->reference)
            ->with('success', $result['message']);
    }

    /**
     * Status poll for a purchase initiated from the pricelist.
     */
    public function queryTransaction(string $orderId)
    {
        $transaction = Transactions::where('user_id', Auth::id())
            ->whereReference($orderId)
            ->first();

        if (! $transaction) {
            return response()->json(['success' => false, 'message' => 'Transaction not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'reference' => $transaction->reference,
                'status' => $transaction->status,
                'payment_status' => $transaction->payment_status,
                'amount' => (float) $transaction->amount,
                'description' => $transaction->description,
                'provider_reference' => $transaction->api_reference,
                'created_at' => $transaction->created_at?->toIso8601String(),
                'completed_at' => $transaction->completed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Upstream status callback for pricelist purchases.
     *
     * Authenticated by an HMAC of the raw body in `X-Reup-Signature`. Without
     * verification this endpoint would let anyone mark any transaction
     * successful.
     */
    public function callback(Request $request)
    {
        $secret = (string) config('services.clubkonnect.api_key');
        $signature = (string) $request->header('X-Reup-Signature', '');

        if ($secret === '' || $signature === '') {
            abort(401, 'Missing signature.');
        }

        if (! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            Log::warning('Pricelist callback rejected: bad signature', ['ip' => $request->ip()]);
            abort(401, 'Invalid signature.');
        }

        $reference = $request->input('reference') ?? $request->input('RequestID');

        if (! $reference) {
            return response()->json(['success' => false, 'message' => 'No reference supplied.'], 422);
        }

        $transaction = Transactions::whereReference($reference)->first();

        if (! $transaction) {
            Log::warning('Pricelist callback for unknown reference', ['reference' => $reference]);

            return response()->json(['success' => false, 'message' => 'Unknown reference.'], 404);
        }

        Log::info('Pricelist callback received', [
            'transaction_id' => $transaction->id,
            'status' => $request->input('status'),
        ]);

        // Status is reconciled by a provider status query, not by an inbound
        // callback asserting success — that would be a credit with no funds.
        return response()->json(['success' => true]);
    }
}