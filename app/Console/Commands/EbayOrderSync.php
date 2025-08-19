<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Integration;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;

class EbayOrderSync extends Command
{
    protected $signature = 'ebay:sync-orders';
    protected $description = 'Fetch and sync eBay orders and items into orders and order_items tables';

    public function handle()
    {
        $this->info('Syncing eBay orders and items...');
        $integration = Integration::where('store_id', 3)->first();
        if (!$integration) {
            $this->error('No integration found for store_id 3.');
            Log::error('eBay integration missing for store_id 3');
            return 1;
        }
        if ($integration->expires_at->lt(now())) {
            $response = Http::asForm()->withBasicAuth(
                env('EBAY_APP_ID'),
                env('EBAY_CERT_ID')
            )->post('https://api.ebay.com/identity/v1/oauth2/token', [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $integration->refresh_token ?? null,
                'scope'         => 'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly',
            ]);
            if ($response->successful()) {
                $data = $response->json();
                $integration->update([
                    'access_token'  => $data['access_token'],
                    'expires_at'    => now()->addSeconds($data['expires_in']),
                ]);
                $this->info('eBay Access token refreshed');
            } else {
                $this->error('eBay token refresh failed');
                Log::error('eBay token refresh failed', [
                    'response_status' => $response->status(),
                    'response_body'   => $response->body(),
                ]);
                return 1;
            }
        }
        $endpoint = "https://api.ebay.com/sell/fulfillment/v1/order";
        $createdAfter = Carbon::today()->toIso8601ZuluString();
        $this->info("📅 Fetching orders created after {$createdAfter}");
        $response = Http::withToken($integration->access_token)->get($endpoint, [
            'filter' => "creationdate:[{$createdAfter}..]",
            'limit'  => 20,
        ]);

        if ($response->failed()) {
            $this->error('❌ Failed to fetch eBay orders: ' . $response->body());
            Log::error('Failed to fetch eBay orders', [
                'response_status' => $response->status(),
                'response_body'   => $response->body(),
            ]);
            return 1;
        }

        $orders = $response->json()['orders'] ?? [];
        Log::info('eBay API Response', [
            'total_orders' => count($orders),
            'response' => $response->json(),
        ]);

        if (empty($orders)) {
            $this->info('ℹ️ No orders found.');
            return 0;
        }

        foreach ($orders as $order) {
            try {
                $orderId = $order['orderId'];
                Log::info('Processing Order', [
                    'order_id' => $orderId,
                    'status' => $order['orderFulfillmentStatus'] ?? 'unknown',
                    'buyer' => $order['buyer'] ?? [],
                ]);

                $items = $order['lineItems'] ?? [];
                $totalQuantity = array_sum(array_column($items, 'quantity')) ?? 1;
                $status = $order['orderFulfillmentStatus'] ?? null;

                $shipstationStatus = match ($status) {
                    'FULFILLED' => 'shipped',
                    'CANCELLED' => 'cancelled',
                    'ACTIVE'    => 'awaiting_shipment',
                    default     => strtolower($status ?? 'unknown'),
                };
                $orderData = [
                    'marketplace'        => 'ebay',
                    'store_id'           => $integration->store_id,
                    'order_number'       => $orderId,
                    'external_order_id'  => $orderId,
                    'order_date'         => $order['creationDate'] ? Carbon::parse($order['creationDate']) : null,
                    'order_age'          => isset($order['creationDate']) ? now()->diffInDays(Carbon::parse($order['creationDate'])) : null,
                    'quantity'           => $totalQuantity,
                    'order_total'        => $order['pricingSummary']['total']['value'] ?? 0.00,
                    'recipient_name'     => $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['fullName'] ?? null,
                    'recipient_email'    => $order['buyer']['email'] ?? null,
                    'recipient_phone'    => $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['primaryPhone']['phoneNumber'] ?? null,
                    'ship_address1'      => $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['contactAddress']['addressLine1'] ?? null,
                    'ship_address2'      => $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['contactAddress']['addressLine2'] ?? null,
                    'ship_city'          => $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['contactAddress']['city'] ?? null,
                    'ship_state'         => $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['contactAddress']['stateOrProvince'] ?? null,
                    'ship_postal_code'   => $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['contactAddress']['postalCode'] ?? null,
                    'ship_country'       => $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['contactAddress']['countryCode'] ?? null,
                    'order_status'       => $shipstationStatus,
                    'raw_data'           => json_encode($order),
                    'raw_items'          => json_encode($items),
                ];

                $orderModel = Order::updateOrCreate(
                    [
                        'marketplace' => 'ebay',
                        'order_number' => $orderId,
                        'external_order_id' => $orderId,
                    ],
                    $orderData
                );
                $this->info("Order {$orderId} synced");
                foreach ($items as $item) {
                    $itemData = [
                        'order_id'           => $orderModel->id,
                        'order_number'       => $orderId,
                        'order_item_id'      => $item['lineItemId'] ?? null,
                        'sku'                => $item['sku'] ?? null,
                        'asin'               => null,
                        'upc'                => $item['productIdentifier']['gtin'] ?? null,
                        'product_name'       => $item['title'] ?? null,
                        'quantity_ordered'   => $item['quantity'] ?? 0,
                        'quantity_shipped'   => $item['quantityShipped'] ?? 0,
                        'unit_price'         => $item['lineItemCost']['value'] ?? 0.00,
                        'item_tax'           => 0.00, 
                        'promotion_discount' => 0.00,
                        'currency'           => $item['lineItemCost']['currency'] ?? 'USD',
                        'is_gift'            => $item['giftDetails']['isGift'] ?? 0,
                        'weight'             => null,
                        'weight_unit'        => null,
                        'dimensions'         => null,
                        'marketplace'        => 'ebay',
                        'raw_data'           => json_encode($item),
                    ];

                    OrderItem::updateOrCreate(
                        [
                            'order_id' => $orderModel->id,
                            'order_item_id' => $item['lineItemId'] ?? null,
                            'marketplace' => 'ebay',
                        ],
                        $itemData
                    );

                    $this->info("✅ Order Item {$item['lineItemId']} for {$orderId} synced");
                }

            } catch (\Exception $e) {
                $this->error("⚠️ Error saving order {$orderId}: " . $e->getMessage());
                Log::error('eBay order save error', [
                    'order_id' => $orderId,
                    'exception' => $e->getMessage(),
                    'order' => $order,
                ]);
            }
        }

        $this->info('🎉 eBay order and item sync completed!');
        return 0;
    }
}
