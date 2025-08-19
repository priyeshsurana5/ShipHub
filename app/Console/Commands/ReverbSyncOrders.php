<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;

class ReverbSyncOrders extends Command
{
    protected $signature = 'reverb:sync-orders';
    protected $description = 'Fetch and sync Reverb orders and items into orders and order_items tables (ShipStation style)';

    public function handle()
    {
        $this->info('🔄 Syncing Reverb orders and items...');

        $token = config('services.reverb.token');
        if (!$token) {
            $this->error('❌ Reverb API token is missing in configuration.');
            Log::error('Reverb API token missing');
            return;
        }

        $url = 'https://api.reverb.com/api/my/orders/selling/all';
        $startOfDay = Carbon::today()->setTimezone('America/New_York')->toIso8601String();
        $endOfDay = Carbon::today()->endOfDay()->setTimezone('America/New_York')->toIso8601String();
        $page = 1;
        $perPage = 50;
        $hasMore = true;

        $this->info("📅 Fetching orders from {$startOfDay} to {$endOfDay}");

        while ($hasMore) {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/hal+json',
                'Accept-Version' => '3.0',
            ])->get($url, [
                'updated_start_date' => $startOfDay,
                'updated_end_date' => $endOfDay,
                'per_page' => $perPage,
                'page' => $page,
            ]);

            if ($response->failed()) {
                $this->error('❌ Failed to fetch orders: ' . $response->body());
                Log::error('Reverb API fetch failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return;
            }

            $data = $response->json();

            Log::info('Reverb API Response', [
                'page' => $page,
                'total_orders' => count($data['orders'] ?? []),
                'response' => $data,
            ]);

            $orders = isset($data['orders']) ? $data['orders'] : [];
            if (empty($orders)) {
                $this->info('ℹ️ No more orders found.');
                break;
            }

            foreach ($orders as $order) {
                try {
                    // Log order details
                    Log::info('Processing Order', [
                        'order_number' => $order['order_number'] ?? 'unknown',
                        'uuid' => $order['uuid'] ?? 'unknown',
                        'status' => $order['status'] ?? 'unknown',
                        'shipment_status' => $order['shipment_status'] ?? 'unknown',
                        'shipping_address' => $order['shipping_address'] ?? [],
                        'buyer_name' => $order['buyer_name'] ?? 'unknown',
                        'name_in_shipping' => $order['shipping_address']['name'] ?? 'missing',
                    ]);

                    $orderUuid = isset($order['uuid']) ? $order['uuid'] : (isset($order['id']) ? $order['id'] : 'unknown');
                    $orderNumber = isset($order['order_number']) ? $order['order_number'] : null;
                    $createdAt = isset($order['created_at']) ? $order['created_at'] : null;
                    $buyerEmail = isset($order['buyer_email']) ? $order['buyer_email'] : null;
                    $shippingAddress = isset($order['shipping_address']) ? $order['shipping_address'] : [];

                    if (empty($shippingAddress)) {
                        Log::warning('Missing shipping_address for order ' . $orderNumber, ['order' => $order]);
                    } elseif (!isset($shippingAddress['name'])) {
                        Log::warning('Missing name in shipping_address for order ' . $orderNumber, [
                            'shipping_address' => $shippingAddress,
                            'falling_back_to_buyer_name' => $order['buyer_name'] ?? 'none',
                        ]);
                    }

                    $dataToSave = [
                        'store_id' => 1,
                        'order_number' => $orderNumber,
                        'order_date' => $createdAt ? Carbon::parse($createdAt) : null,
                        'order_total' => isset($order['total']['amount']) ? $order['total']['amount'] : 0,
                        'amount_product' => isset($order['amount_product']['amount']) ? $order['amount_product']['amount'] : 0,
                        'amount_shipping' => isset($order['shipping']['amount']) ? $order['shipping']['amount'] : 0,
                        'amount_tax' => isset($order['amount_tax']['amount']) ? $order['amount_tax']['amount'] : 0,
                        'recipient_name' => isset($shippingAddress['name']) ? $shippingAddress['name'] : (isset($order['buyer_name']) ? $order['buyer_name'] : null),
                        'recipient_company' => isset($shippingAddress['company']) ? $shippingAddress['company'] : null,
                        'recipient_email' => $buyerEmail,
                        'recipient_phone' => isset($shippingAddress['phone']) ? $shippingAddress['phone'] : null,
                        'ship_address1' => isset($shippingAddress['street_address']) ? $shippingAddress['street_address'] : null,
                        'ship_address2' => isset($shippingAddress['extended_address']) ? $shippingAddress['extended_address'] : null,
                        'ship_city' => isset($shippingAddress['locality']) ? $shippingAddress['locality'] : null,
                        'ship_state' => isset($shippingAddress['region']) ? $shippingAddress['region'] : null,
                        'ship_postal_code' => isset($shippingAddress['postal_code']) ? $order['shipping_address']['postal_code'] : null,
                        'ship_country' => isset($shippingAddress['country_code']) ? $shippingAddress['country_code'] : null,
                        'shipping_service' => isset($order['shipping_method']) ? $order['shipping_method'] : null,
                        'order_status' => isset($order['status']) ? $order['status'] : null,
                        'payment_status' => isset($order['payment_method']) ? $order['payment_method'] : null,
                        'fulfillment_status' => isset($order['shipment_status']) ? $order['shipment_status'] : null,
                        'sku' => isset($order['sku']) ? $order['sku'] : null,
                        'title' => isset($order['title']) ? $order['title'] : null,
                        'quantity' => isset($order['quantity']) ? $order['quantity'] : 1,
                        'raw_data' => json_encode($order),
                    ];
                    Log::info('Data to Save for Order ' . $orderNumber, [
                        'recipient_name' => $dataToSave['recipient_name'],
                        'data' => $dataToSave,
                    ]);
                    $existingOrder = Order::where('marketplace', 'reverb')
                        ->where('order_number', $orderNumber)
                        ->first();

                    if ($existingOrder && $existingOrder->marketplace_order_id !== $orderUuid) {
                        Log::warning('Duplicate order_number detected with different UUID', [
                            'order_number' => $orderNumber,
                            'existing_uuid' => $existingOrder->marketplace_order_id,
                            'new_uuid' => $orderUuid,
                        ]);
                        $existingOrder->update($dataToSave);
                        $this->info("✅ Order {$orderNumber} updated");
                        $orderModel = $existingOrder;
                    } else {
                        $orderModel = Order::updateOrCreate(
                            [
                                'marketplace' => 'reverb',
                                'marketplace_order_id' => $orderUuid,
                                'order_number' => $orderNumber,
                            ],
                            $dataToSave
                        );
                        $this->info("✅ Order {$orderNumber} synced");
                    }
                    $itemData = [
                        'order_id' => $orderModel->id,
                        'order_number' => $orderNumber,
                        'order_item_id' => isset($order['product_id']) ? $order['product_id'] : $orderUuid, // Use product_id or fallback to order UUID
                        'sku' => isset($order['sku']) ? $order['sku'] : null,
                        'asin' => null, // Not available in Reverb API
                        'upc' => null, // Not available
                        'product_name' => isset($order['title']) ? $order['title'] : null,
                        'quantity_ordered' => isset($order['quantity']) ? $order['quantity'] : 1,
                        'quantity_shipped' => (isset($order['shipment_status']) && in_array($order['shipment_status'], ['shipped', 'delivered'])) ? (isset($order['quantity']) ? $order['quantity'] : 1) : 0,
                        'unit_price' => isset($order['amount_product']['amount']) ? $order['amount_product']['amount'] : 0,
                        'item_tax' => isset($order['amount_tax']['amount']) ? $order['amount_tax']['amount'] : 0,
                        'promotion_discount' => 0, 
                        'currency' => isset($order['total']['currency']) ? $order['total']['currency'] : 'USD',
                        'is_gift' => false, 
                        'weight' => null, 
                        'weight_unit' => null, 
                        'dimensions' => null,
                        'marketplace' => 'reverb',
                        'raw_data' => json_encode($order), 
                    ];
                    Log::info('Data to Save for Order Item ' . $orderNumber, [
                        'order_item_id' => $itemData['order_item_id'],
                        'sku' => $itemData['sku'],
                        'product_name' => $itemData['product_name'],
                        'data' => $itemData,
                    ]);

                    OrderItem::updateOrCreate(
                        [
                            'order_id' => $orderModel->id,
                            'order_item_id' => $itemData['order_item_id'],
                            'marketplace' => 'reverb',
                        ],
                        $itemData
                    );

                    $this->info("✅ Order Item for {$orderNumber} synced");

                } catch (\Exception $e) {
                    $this->error("⚠️ Error saving order {$orderUuid}: " . $e->getMessage());
                    Log::error('Reverb order save error', [
                        'order_number' => $orderNumber ?? 'unknown',
                        'uuid' => $orderUuid,
                        'exception' => $e->getMessage(),
                        'order' => $order,
                    ]);
                }
            }

            $page++;
            $hasMore = isset($data['_links']['next']['href']);
            $this->info("📄 Processed page {$page}");
        }

        $this->info('🎉 Reverb order and item sync completed!');
    }
}