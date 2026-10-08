<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class ClubKonnectService 
{
    /**
     * Credentials.
     *
     * These were declared as non-nullable `string` properties and assigned
     * straight from config(), so with CLUBKONNECT_* unset — which is the
     * default in .env — PHP threw
     *   TypeError: Cannot assign null to property ... of type string
     * during container resolution. Because the service is constructor-injected
     * into AirtimeDataController, that fatal took out `php artisan route:list`
     * and every airtime/data request.
     */
    protected ?string $clientId;
    protected ?string $apiKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->clientId = config('services.clubkonnect.client_id');
        $this->apiKey = config('services.clubkonnect.api_key');
        $this->baseUrl = (string) config('services.clubkonnect.base_url', 'https://www.clubkonnect.com');
    }

    /**
     * Whether the provider can actually be called.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->clientId) && ! empty($this->apiKey);
    }

    /**
     * Generic GET request method
     */
    public function get(string $endpoint, array $params = [], int $timeout = 60): ?array
    {
        if (! $this->isConfigured()) {
            Log::error('ClubKonnect called without credentials configured.', ['endpoint' => $endpoint]);

            return [
                'status' => 'NOT_CONFIGURED',
                'message' => 'Airtime and data services are temporarily unavailable.',
            ];
        }

        try {
            // Inject credentials - Use exact parameter names from documentation
            $params = array_merge($params, [
                'UserID' => $this->clientId,
                'APIKey' => $this->apiKey,
            ]);

            // Log the request shape, never the credential.
            Log::info('ClubKonnect request', [
                'endpoint' => $endpoint,
                'params' => array_merge(
                    $this->maskPersonalData(collect($params)->except(['APIKey', 'UserID'])->all()),
                    ['APIKey' => '[redacted]', 'UserID' => '[redacted]']
                ),
            ]);

            // Make GET request
            $response = Http::timeout($timeout)
                ->get($endpoint, $params);

            // Body is logged at debug level only, scrubbed and truncated: it
            // contains customer phone numbers and, on failure, an echo of the
            // request parameters — which include both credentials.
            Log::debug('ClubKonnect response', [
                'status' => $response->status(),
                'body' => Str::limit($this->redact($response->body()), 2000),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (is_null($data)) {
                    Log::error('ClubKonnect returned invalid JSON', [
                        'endpoint' => $endpoint,
                    ]);
                    return [
                        'status' => 'INVALID_RESPONSE',
                        'message' => 'Invalid API response format'
                    ];
                }
                
                return $data;
            }

            Log::error('ClubKonnect API error', [
                'status' => $response->status(),
                'endpoint' => $endpoint,
            ]);

            return [
                'status' => 'API_ERROR',
                'message' => 'API request failed with status ' . $response->status()
            ];

        } catch (Exception $e) {
            /*
             * Never log or return the raw transport message.
             *
             * Laravel wraps a failed connection in a ConnectionException whose
             * message embeds the full request URL, and every endpoint here
             * carries `UserID=...&APIKey=...` in the query string — so the
             * previous `$e->getMessage()` put both credentials into the log
             * (LOG_LEVEL=debug) and returned them to the caller. The trace was
             * logged too, and PHP's default trace format includes call
             * arguments.
             */
            Log::error('ClubKonnect Exception', [
                'message' => $this->redact($e->getMessage()),
                'exception' => get_class($e),
                'endpoint' => $endpoint,
            ]);

            return [
                'status' => 'EXCEPTION',
                'message' => 'We could not reach the service provider. Please try again.',
            ];
        }
    }

    /**
     * Replace anything sensitive in a string that crosses a log or return
     * boundary: the configured credentials, URL query strings, and the phone
     * numbers the endpoints carry.
     */
    private function redact(string $text): string
    {
        foreach ([$this->apiKey, $this->clientId] as $credential) {
            if (is_string($credential) && $credential !== '') {
                $text = str_replace($credential, '[redacted]', $text);
            }
        }

        // Drop query strings outright: the parameters are the credentials.
        $text = preg_replace('~\?[^\s\'"]+~', '?[redacted]', $text) ?? $text;

        return $this->maskPhoneNumbers($text);
    }

    /**
     * The same scrub applied to a structured payload before it is logged.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function maskPersonalData(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->maskPersonalData($value);

                continue;
            }

            if (is_scalar($value) && preg_match('/(mobile|phone|msisdn|smartcard|iuc|meter|customer_?id|account_?no)/i', (string) $key)) {
                $payload[$key] = $this->maskPhone((string) $value);
            }
        }

        return $payload;
    }

    /**
     * Keep a recognisable tail so support can correlate a request, and nothing
     * more: `08031234567` becomes `*******4567`.
     */
    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (strlen($digits) < 4) {
            return '[redacted]';
        }

        return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    private function maskPhoneNumbers(string $text): string
    {
        return preg_replace_callback(
            '/\b0?\d{9,13}\b/',
            fn (array $m) => $this->maskPhone($m[0]),
            $text
        ) ?? $text;
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

        // The customer's number is personal data: the log keeps a tail only.
        Log::info('Airtime purchase requested', [
            'network' => $network,
            'amount' => $amount,
            'mobile_number' => $this->maskPhone($phone),
            'request_id' => $requestId,
        ]);

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

        Log::info('Data purchase requested', [
            'network' => $network,
            'data_plan' => $dataPlan,
            'mobile_number' => $this->maskPhone($phone),
            'request_id' => $requestId,
        ]);

        return $this->get($endpoint, $params);
    }

    /**
     * Check wallet balance.
     *
     * The timeout is a parameter because this doubles as the liveness probe:
     * sixty seconds is right for a vend or a float enquiry a customer is waiting
     * on, and far too long to hang a purchase decision on.
     */
    public function checkBalance(int $timeout = 60): ?array
    {
        $endpoint = 'https://www.nellobytesystems.com/APIWalletBalanceV1.asp';
        return $this->get($endpoint, [], $timeout);
    }

    /* =====================================================================
     | Bill payments
     |=================================================================== */

    /**
     * Validate a smartcard / IUC number against a cable TV provider.
     *
     * This must happen server-side. The cable TV page used to call NelloBytes
     * directly from the browser and interpolate the API key into the markup,
     * which published the credential to every visitor.
     */
    public function verifyCableTvCustomer(string $provider, string $smartcardNumber, string $requestId): ?array
    {
        return $this->get('https://www.nellobytesystems.com/APIVerifyCableTVV1.asp', [
            'CableTV' => $provider,
            'SmartCardNo' => $smartcardNumber,
            'RequestID' => $requestId,
        ]);
    }

    /**
     * Pay a cable TV subscription.
     */
    public function purchaseCableTv(
        string $provider,
        string $package,
        string $smartcardNumber,
        float $amount,
        string $phone,
        string $requestId
    ): ?array {
        return $this->get('https://www.nellobytesystems.com/APICableTVV1.asp', [
            'CableTV' => $provider,
            'Package' => $package,
            'SmartCardNo' => $smartcardNumber,
            'Amount' => $amount,
            'PhoneNo' => $phone,
            'RequestID' => $requestId,
        ]);
    }

    /**
     * Validate a meter number with a disco.
     */
    public function verifyMeter(string $disco, string $meterNumber, string $meterType, string $requestId): ?array
    {
        return $this->get('https://www.nellobytesystems.com/APIVerifyElectricityV1.asp', [
            'ElectricCompany' => $disco,
            'MeterNo' => $meterNumber,
            'MeterType' => $meterType,
            'RequestID' => $requestId,
        ]);
    }

    /**
     * Pay an electricity bill and return the token on success.
     */
    public function purchaseElectricity(
        string $disco,
        string $meterNumber,
        string $meterType,
        float $amount,
        string $phone,
        string $requestId
    ): ?array {
        return $this->get('https://www.nellobytesystems.com/APIElectricityV1.asp', [
            'ElectricCompany' => $disco,
            'MeterNo' => $meterNumber,
            'MeterType' => $meterType,
            'Amount' => $amount,
            'PhoneNo' => $phone,
            'RequestID' => $requestId,
        ]);
    }

    /**
     * Buy an exam PIN (WAEC / JAMB / NECO).
     */
    public function purchaseExamPin(string $examType, int $quantity, string $phone, string $requestId): ?array
    {
        return $this->get('https://www.nellobytesystems.com/APIExamPinV1.asp', [
            'ExamType' => $examType,
            'Quantity' => $quantity,
            'PhoneNo' => $phone,
            'RequestID' => $requestId,
        ]);
    }

    /**
     * Resolve a JAMB profile to a candidate name.
     */
    public function verifyJambProfile(string $profileId, string $examType, string $requestId): ?array
    {
        return $this->get('https://www.nellobytesystems.com/APIJAMBVerifyV1.asp', [
            'ExamType' => $examType,
            'ProfileID' => $profileId,
            'RequestID' => $requestId,
        ]);
    }

    /**
     * Validate a betting account number against a bookmaker.
     */
    public function verifyBettingCustomer(string $bettingCode, string $customerId, string $requestId): ?array
    {
        return $this->get('https://www.nellobytesystems.com/APIVerifyBettingV1.asp', [
            'BettingCompany' => $bettingCode,
            'CustomerID' => $customerId,
            'RequestID' => $requestId,
        ]);
    }

    /**
     * Fund a betting wallet.
     */
    public function purchaseBetting(
        string $bettingCode,
        string $customerId,
        float $amount,
        string $phone,
        string $requestId
    ): ?array {
        return $this->get('https://www.nellobytesystems.com/APIBettingV1.asp', [
            'BettingCompany' => $bettingCode,
            'CustomerID' => $customerId,
            'Amount' => $amount,
            'PhoneNo' => $phone,
            'RequestID' => $requestId,
        ]);
    }

    /**
     * Public data-plan catalogue, keyed by network.
     *
     * Cached briefly: it changes rarely and every page view was otherwise an
     * upstream round trip.
     */
    public function dataPlans(): ?array
    {
        return Cache::remember('clubkonnect.data_plans', 900, function () {
            return $this->get('https://www.nellobytesystems.com/APIDatabundleV2.asp');
        });
    }

    /**
     * Public cable TV bouquet catalogue.
     */
    public function cableTvPackages(): ?array
    {
        return Cache::remember('clubkonnect.cable_packages', 900, function () {
            return $this->get('https://www.nellobytesystems.com/APICableTVPackagesV2.asp');
        });
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