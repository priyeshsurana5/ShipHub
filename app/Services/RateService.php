<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Models\CarrierAccount;

class RateService
{
    protected $carrier;
    protected $key;
    protected $secret;
    protected $accountNumber;
    protected $baseUrl;

    public function __construct(string $carrier, $userId = null)
    {
        $this->carrier = strtolower($carrier);
        $carrierAccount = CarrierAccount::where('carrier_name', $carrier)
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->first();

        if (!$carrierAccount) {
            throw new \Exception("$carrier account not found.");
        }

        $this->key           = $carrierAccount->client_id;
        $this->secret        = $carrierAccount->client_secret;
        $this->accountNumber = $carrierAccount->account_number;
        $env = strtolower($carrierAccount->api_environment);
        $this->baseUrl = match($this->carrier) {
            'fedex' => $env === 'production'
                ? 'https://apis.fedex.com'
                : 'https://apis-sandbox.fedex.com',
            'usps' => $env === 'production'
                ? 'https://secure.shippingapis.com'
                : 'https://secure.shippingapis.com/sandbox',
            default => throw new \Exception("Unsupported carrier: $carrier"),
        };
    }
 protected function getAccessToken()
{
    $response = Http::asForm()->post('https://apis-sandbox.fedex.com/oauth/token', [
        'grant_type'    => 'client_credentials',
        'client_id'     => $this->key,
        'client_secret' => $this->secret,
    ]);

    if ($response->failed()) {
        throw new \Exception('Failed to get FedEx token: ' . $response->body());
    }

    return $response->json()['access_token'];
}


    /**
     * Get rate quotes from FedEx
     */
    public function getRate(array $params)
    {
            $token = $this->getAccessToken();
            $payload = [
            "accountNumber" => [
                "value" => $this->accountNumber // keep your account number here
            ],
            "requestedShipment" => [
                "shipper" => [
                    "contact" => [
                        "personName"  => $params['shipper_name'],
                        "phoneNumber" => $params['shipper_phone'],
                        "companyName" => $params['shipper_company']
                    ],
                    "address" => [
                        "streetLines"          => [$params['shipper_street']],
                        "city"                 => $params['shipper_city'],
                        "stateOrProvinceCode"  => $params['shipper_state'],
                        "postalCode"           => $params['shipper_postal'],
                        "countryCode"          => $params['shipper_country']
                    ]
                ],
                "recipient" => [
                    "contact" => [
                        "personName"  => $params['recipient_name'],
                        "phoneNumber" => $params['recipient_phone'],
                        "companyName" => $params['recipient_company']
                    ],
                    "address" => [
                        "streetLines"         => [$params['recipient_street']],
                        "city"                => $params['recipient_city'],
                        "stateOrProvinceCode" => $params['recipient_state'],
                        "postalCode"          => $params['recipient_postal'],
                        "countryCode"         => $params['recipient_country'],
                        "residential"         => $params['residential'] ?? false
                    ]
                ],
                "pickupType"     => $params['pickup_type'],
                "serviceType"    => $params['service_type'],
                "packagingType"  => $params['packaging_type'],
                "rateRequestType"=> ["ACCOUNT"],
                "requestedPackageLineItems" => [
                    [
                        "weight" => [
                            "units" => $params['weight_unit'],
                            "value" => $params['weight']
                        ],
                        "dimensions" => [
                            "length" => $params['length'],
                            "width"  => $params['width'],
                            "height" => $params['height'],
                            "units"  => $params['dimension_unit']
                        ]
                    ]
                ]
            ]
        ];
        $response = Http::withToken($token)
            ->post('https://apis-sandbox.fedex.com/rate/v1/rates/quotes', $payload);

        if ($response->failed()) {
            throw new \Exception('FedEx Rate API failed: ' . $response->body());
        }

        return $response->json();
    }
}
 