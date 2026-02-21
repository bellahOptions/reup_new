<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PricelistController extends Controller
{
    private $profitMargin = 0.015; // 1.5% profit
    
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
            $userId = config('services.clubkonnect.user_id');
            
            // Fetch data plans from ClubKonnect API
            $response = Http::timeout(30)->get('https://www.nellobytesystems.com/APIDatabundlePlansV2.asp', [
                'UserID' => $userId,
            ]);
            
            if (!$response->successful()) {
                throw new \Exception('Failed to fetch data plans from ClubKonnect');
            }
            
            $apiData = $response->json();
            
            // Log the raw response for debugging
            Log::info('ClubKonnect Raw Response:', $apiData);
            
            $processedPlans = $this->processPlans($apiData);
            
            return [
                'data_plans' => $processedPlans,
                'last_updated' => now()->toDateTimeString(),
                'total_plans' => count($processedPlans),
            ];
            
        } catch (\Exception $e) {
            Log::error('ClubKonnect data plans error: ' . $e->getMessage());
            
            // Return cached or empty data
            return Cache::get('clubkonnect_data_plans_fallback', [
                'data_plans' => [],
                'last_updated' => now()->toDateTimeString(),
                'total_plans' => 0,
                'error' => 'Unable to fetch real-time data. Please try again later.',
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
        $data = $this->fetchDataPlans();
        Cache::put('clubkonnect_data_plans', $data, 600);
        
        return redirect()->route('pricelist')
            ->with('success', 'Data plans refreshed successfully!');
    }
    
    // Get plan details for purchase
    public function getPlan($planCode)
    {
        $plans = Cache::get('clubkonnect_data_plans', ['data_plans' => []]);
        
        foreach ($plans['data_plans'] as $plan) {
            if ($plan['plan_code'] == $planCode) {
                return response()->json($plan);
            }
        }
        
        return response()->json(['error' => 'Plan not found'], 404);
    }
}