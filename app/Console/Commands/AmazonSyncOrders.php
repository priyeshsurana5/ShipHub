<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Integration;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;

class AmazonSyncOrders extends Command
{
    protected $signature = 'amazon:sync-orders';
    protected $description = 'Fetch and sync Amazon orders and items into orders and order_items tables';

    public function handle()
    {
        $this->info('🔄 Syncing Amazon orders and items...');

        // Fetch integration for store_id 1
        $integration = Integration::where('store_id', 1)->first();
        if (!$integration) {
            $this->error('❌ No integration found for store_id 1.');
            Log::error('Amazon integration missing for store_id 1');
            return 1;
        }

        // Refresh access token if expired
        if ($integration->expires_at->lt(now())) {
            $response = Http::asForm()->post('https://api.amazon.com/auth/o2/token', [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $integration->refresh_token ?? null,
                'client_id'     => env('AMAZON_CLIENT_ID'),
                'client_secret' => env('AMAZON_CLIENT_SECRET'),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $integration->update([
                    'access_token'  => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? $integration->refresh_token,
                    'expires_at'    => now()->addSeconds($data['expires_in']),
                ]);
                $this->info('✅ Access token refreshed');
            } else {
                $this->error('❌ Amazon token refresh failed');
                Log::error('Amazon token refresh failed', [
                    'response_status' => $response->status(),
                    'response_body'   => $response->body(),
                ]);
                return 1;
            }
        }

        // Set endpoint and dynamic date
        $endpoint = env('AMAZON_BASE_URL', 'https://sellingpartnerapi-na.amazon.com') . '/orders/v0/orders';
        $createdAfter = Carbon::today()->setTimezone('America/New_York')->toIso8601String();
        $this->info("📅 Fetching orders created after {$createdAfter}");

        // Fetch orders
        $response = Http::withHeaders([
            'Authorization'      => 'Bearer ' . $integration->access_token,
            'x-amz-access-token' => $integration->access_token,
        ])->get($endpoint, [
            'MarketplaceIds' => 'ATVPDKIKX0DER',
            'CreatedAfter'   => $createdAfter,
        ]);

        if ($response->failed()) {
            $this->error('❌ Failed to fetch orders: ' . $response->body());
            Log::error('Failed to fetch Amazon orders', [
                'response_status' => $response->status(),
                'response_body'   => $response->body(),
            ]);
            return 1;
        }

        $orders = $response->json()['payload']['Orders'] ?? [];
        Log::info('Amazon API Response', [
            'total_orders' => count($orders),
            'response' => $response->json(),
        ]);

        if (empty($orders)) {
            $this->info('ℹ️ No orders found.');
            return 0;
        }

        foreach ($orders as $order) {
            try {
                $orderId = $order['AmazonOrderId'];
                Log::info('Processing Order', [
                    'order_id' => $orderId,
                    'status' => $order['OrderStatus'] ?? 'unknown',
                    'shipping_address' => $order['ShippingAddress'] ?? [],
                    'recipient_name' => $order['ShippingAddress']['Name'] ?? ($order['BuyerName'] ?? 'missing'),
                ]);

                // Fetch order items
                $itemsEndpoint = env('AMAZON_BASE_URL', 'https://sellingpartnerapi-na.amazon.com') . "/orders/v0/orders/{$orderId}/orderItems";
                $itemsResponse = Http::withHeaders([
                    'Authorization'      => 'Bearer ' . $integration->access_token,
                    'x-amz-access-token' => $integration->access_token,
                ])->get($itemsEndpoint);

                $items = [];
                if ($itemsResponse->successful()) {
                    $items = $itemsResponse->json()['payload']['OrderItems'] ?? [];
                    Log::info('Order Items Fetched', [
                        'order_id' => $orderId,
                        'item_count' => count($items),
                    ]);
                } else {
                    Log::warning("Failed to fetch items for Order {$orderId}", [
                        'status' => $itemsResponse->status(),
                        'body' => $itemsResponse->body(),
                    ]);
                }

                // Calculate total quantity from items
                $totalQuantity = array_sum(array_column($items, 'QuantityOrdered')) ?? ($order['NumberOfItemsUnshipped'] ?? 1);

                // Save order
                $orderData = [
                    'marketplace'        => 'amazon',
                    'store_id'           => $integration->store_id,
                    'order_number'       => $orderId,
                    'external_order_id'  => $orderId,
                    'order_date'         => $order['PurchaseDate'] ? Carbon::parse($order['PurchaseDate']) : null,
                    'order_age'          => isset($order['PurchaseDate']) ? now()->diffInDays(Carbon::parse($order['PurchaseDate'])) : null,
                    'quantity'           => $totalQuantity,
                    'order_total'        => $order['OrderTotal']['Amount'] ?? 0.00,
                    'recipient_name'     => $order['ShippingAddress']['Name'] ?? ($order['BuyerName'] ?? null),
                    'recipient_email'    => $order['BuyerEmail'] ?? null,
                    'recipient_phone'    => $order['ShippingAddress']['Phone'] ?? null,
                    'ship_address1'      => $order['ShippingAddress']['AddressLine1'] ?? null,
                    'ship_address2'      => $order['ShippingAddress']['AddressLine2'] ?? null,
                    'ship_city'          => $order['ShippingAddress']['City'] ?? null,
                    'ship_state'         => $order['ShippingAddress']['StateOrRegion'] ?? null,
                    'ship_postal_code'   => $order['ShippingAddress']['PostalCode'] ?? null,
                    'ship_country'       => $order['ShippingAddress']['CountryCode'] ?? null,
                    'shipper_name'       => $order['DefaultShipFromLocationAddress']['Name'] ?? null,
                    'shipper_street'     => $order['DefaultShipFromLocationAddress']['AddressLine1'] ?? null,
                    'shipper_city'       => $order['DefaultShipFromLocationAddress']['City'] ?? null,
                    'shipper_state'      => $order['DefaultShipFromLocationAddress']['StateOrRegion'] ?? null,
                    'shipper_postal'     => $order['DefaultShipFromLocationAddress']['PostalCode'] ?? null,
                    'shipper_country'    => $order['DefaultShipFromLocationAddress']['CountryCode'] ?? null,
                    'order_status'       => $order['OrderStatus'] ?? null,
                    'raw_data'           => json_encode($order),
                    'raw_items'          => json_encode($items),
                ];

                // Check for duplicate order_number
                $existingOrder = Order::where('marketplace', 'amazon')
                    ->where('order_number', $orderId)
                    ->first();

                if ($existingOrder && $existingOrder->external_order_id !== $orderId) {
                    Log::warning('Duplicate order_number detected with different external_order_id', [
                        'order_number' => $orderId,
                        'existing_external_order_id' => $existingOrder->external_order_id,
                        'new_external_order_id' => $orderId,
                    ]);
                    $existingOrder->update($orderData);
                    $orderModel = $existingOrder;
                    $this->info("✅ Order {$orderId} updated");
                } else {
                    $orderModel = Order::updateOrCreate(
                        [
                            'marketplace' => 'amazon',
                            'order_number' => $orderId,
                            'external_order_id' => $orderId,
                        ],
                        $orderData
                    );
                    $this->info("✅ Order {$orderId} synced");
                }

                // Save order items
                foreach ($items as $item) {
                    // Convert IsGift string to boolean (1 or 0)
                    $isGift = isset($item['IsGift']) ? ($item['IsGift'] === 'true' ? 1 : 0) : 0;

                    $itemData = [
                        'order_id'           => $orderModel->id,
                        'order_number'       => $orderId,
                        'order_item_id'      => $item['OrderItemId'] ?? null,
                        'sku'                => $item['SellerSKU'] ?? null,
                        'asin'               => $item['ASIN'] ?? null,
                        'upc'                => null, // Not available in API response
                        'product_name'       => $item['Title'] ?? null,
                        'quantity_ordered'   => $item['QuantityOrdered'] ?? 0,
                        'quantity_shipped'   => $item['QuantityShipped'] ?? 0,
                        'unit_price'         => $item['ItemPrice']['Amount'] ?? 0.00,
                        'item_tax'           => $item['ItemTax']['Amount'] ?? 0.00,
                        'promotion_discount' => $item['PromotionDiscount']['Amount'] ?? 0.00,
                        'currency'           => $item['ItemPrice']['CurrencyCode'] ?? 'USD',
                        'is_gift'            => $isGift,
                        'weight'             => $item['ItemWeight']['Value'] ?? null,
                        'weight_unit'        => $item['ItemWeight']['Unit'] ?? null,
                        'dimensions'         => isset($item['ItemDimensions']) ? json_encode($item['ItemDimensions']) : null,
                        'marketplace'        => 'amazon',
                        'raw_data'           => json_encode($item),
                    ];

                    Log::info('Data to Save for Order Item ' . $orderId, [
                        'order_item_id' => $itemData['order_item_id'],
                        'sku' => $itemData['sku'],
                        'product_name' => $itemData['product_name'],
                        'is_gift' => $itemData['is_gift'],
                        'data' => $itemData,
                    ]);

                    OrderItem::updateOrCreate(
                        [
                            'order_id' => $orderModel->id,
                            'order_item_id' => $item['OrderItemId'] ?? null,
                            'marketplace' => 'amazon',
                        ],
                        $itemData
                    );

                    $this->info("✅ Order Item {$item['OrderItemId']} for {$orderId} synced");
                }

            } catch (\Exception $e) {
                $this->error("⚠️ Error saving order {$orderId}: " . $e->getMessage());
                Log::error('Amazon order save error', [
                    'order_id' => $orderId,
                    'exception' => $e->getMessage(),
                    'order' => $order,
                ]);
            }
        }

        $this->info('🎉 Amazon order and item sync completed!');
        return 0;
    }
}