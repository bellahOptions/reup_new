<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class ClubKonnectService 
{
    protected string $clientId;
    protected string $apiKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->clientId = config('services.clubkonnect.client_id');
        $this->apiKey   = config('services.clubkonnect.api_key');
        $this->baseUrl  = config('services.clubkonnect.base_url');
    }

    /**
     * Generic GET request method
     */
    public function get(string $endpoint, array $params = []): ?array
    {
        try {
            // Inject credentials - Use exact parameter names from documentation
            $params = array_merge($params, [
                'UserID' => $this->clientId,
                'APIKey' => $this->apiKey,
            ]);

            Log::info('ClubKonnect API Request', [
                'endpoint' => $endpoint,
                'params' => array_merge($params, ['APIKey' => '[REDACTED]'])
            ]);

            // Make GET request
            $response = Http::timeout(60)
                ->get($endpoint, $params);

            // Log raw response
            Log::info('ClubKonnect Raw Response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (is_null($data)) {
                    Log::error('ClubKonnect returned invalid JSON', [
                        'body' => $response->body()
                    ]);
                    return [
                        'status' => 'INVALID_RESPONSE',
                        'message' => 'Invalid API response format'
                    ];
                }
                
                Log::info('ClubKonnect Parsed Response', ['data' => $data]);
                return $data;
            }

            Log::error('ClubKonnect API Error', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return [
                'status' => 'API_ERROR',
                'message' => 'API request failed with status ' . $response->status()
            ];

        } catch (Exception $e) {
            Log::error('ClubKonnect Exception', [
                'message' => $e->getMessage(),
                'endpoint' => $endpoint,
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'status' => 'EXCEPTION',
                'message' => 'Connection error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Purchase Airtime
     * According to docs: UserID, APIKey, MobileNetwork, Amount, MobileNumber, RequestID
     */
    public function purchaseAirtime(string $network, string $phone, float $amount, string $requestId): ?array
    {
        $endpoint = 'https://www.nellobytesystems.com/APIAirtimeV1.asp';
        
        $params = [
            'MobileNetwork' => $network,
            'Amount' => $amount, // No formatting - send as is
            'MobileNumber' => $phone,
            'RequestID' => $requestId,
        ];

        Log::info('Airtime Purchase Request', $params);

        return $this->get($endpoint, $params);
    }

    /**
     * Purchase Data Bundle
     * According to docs: UserID, APIKey, MobileNetwork, DataPlan, MobileNumber, RequestID
     */
    public function purchaseData(string $network, string $dataPlan, string $phone, string $requestId): ?array
    {
        $endpoint = 'https://www.nellobytesystems.com/APIDatabundleV1.asp';
        
        $params = [
            'MobileNetwork' => $network,
            'DataPlan' => $dataPlan, // This should be the PRODUCT_ID
            'MobileNumber' => $phone,
            'RequestID' => $requestId,
        ];

        Log::info('Data Purchase Request', $params);

        return $this->get($endpoint, $params);
    }

    /**
     * Check wallet balance
     */
    public function checkBalance(): ?array
    {
        $endpoint = 'https://www.nellobytesystems.com/APIWalletBalanceV1.asp';
        return $this->get($endpoint);
    }

    /**
     * Verify Transaction Status
     */
    public function verifyTransaction(string $requestId): ?array
    {
        $endpoint = 'https://www.nellobytesystems.com/APIStatusQueryV1.asp';
        
        $params = [
            'RequestID' => $requestId,
        ];

        return $this->get($endpoint, $params);
    }

    /**
     * Check if response indicates success
     */
    public function isSuccessResponse(?array $response): bool
    {
        if (!$response || !isset($response['status'])) {
            return false;
        }

        $status = strtoupper(trim($response['status']));
        
        // Based on documentation, successful status is ORDER_RECEIVED
        return $status === 'ORDER_RECEIVED';
    }

    /**
     * Check if response indicates error
     */
    public function isErrorResponse(?array $response): bool
    {
        if (!$response) {
            return true;
        }

        if (!isset($response['status'])) {
            return true;
        }

        $status = strtoupper(trim($response['status']));
        
        // All error statuses from documentation
        $errorStatuses = [
            'INVALID_CREDENTIALS',
            'MISSING_CREDENTIALS',
            'MISSING_USERID',
            'MISSING_APIKEY',
            'MISSING_MOBILENETWORK',
            'MISSING_AMOUNT',
            'INVALID_AMOUNT',
            'MINIMUM_50',
            'MINIMUM_200000',
            'INVALID_RECIPIENT',
            'INSUFFICIENT_BALANCE',
            'API_ERROR',
            'EXCEPTION',
            'INVALID_RESPONSE',
        ];
        
        return in_array($status, $errorStatuses);
    }

    /**
     * Get error message from response
     */
    public function getErrorMessage(?array $response): string
    {
        if (!$response) {
            return 'No response from service provider';
        }

        $status = $response['status'] ?? 'UNKNOWN_ERROR';
        $message = $response['message'] ?? '';

        // Map status codes to user-friendly messages
        $messages = [
            'INVALID_CREDENTIALS' => 'Invalid service provider credentials. Please contact support.',
            'MISSING_CREDENTIALS' => 'Missing required credentials. Please contact support.',
            'INSUFFICIENT_BALANCE' => 'Service provider has insufficient balance. Please contact support.',
            'INVALID_AMOUNT' => 'Invalid amount entered.',
            'MINIMUM_50' => 'Minimum amount is ₦50.',
            'MINIMUM_200000' => 'Maximum amount is ₦200,000.',
            'INVALID_RECIPIENT' => 'Invalid phone number format.',
            'MISSING_MOBILENETWORK' => 'Please select a network.',
            'API_ERROR' => 'Service temporarily unavailable. Please try again.',
            'EXCEPTION' => 'Connection error. Please check your internet and try again.',
        ];

        return $messages[$status] ?? ($message ?: 'Transaction failed: ' . $status);
    }
}