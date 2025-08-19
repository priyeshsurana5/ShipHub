<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SalesChannel;
use App\Models\Shipment;
use Illuminate\Http\Request; 
use App\Services\RateService;
use App\Services\ShipmentService;
use Illuminate\Support\Facades\DB;
use App\Models\ShippingService;
class AwaitingShipmentOrderController extends Controller
{
     public function index()
     {
        $orders = Order::whereIn('order_status', ['Unshipped', 'PartiallyShipped'])
         ->orderBy('created_at', 'desc')
         ->get();
        $services = ShippingService::where('active', true)
        ->orderBy('carrier_name')
        ->get()
        ->groupBy('carrier_name');

         return view('admin.orders.awaiting-shipment', compact('orders','services'));
     }
     public function getAwaitingShipmentOrders(Request $request)
    {
    $query = Order::query()
        ->select(
            'orders.*',
            'order_items.sku',
            'order_items.product_name'
        )
        ->join('order_items', 'orders.id', '=', 'order_items.order_id')
        ->whereIn('orders.order_status', ['Unshipped', 'PartiallyShipped']);
    if (!empty($request->marketplace)) {
        $query->where('orders.marketplace', $request->marketplace);
    }
    if (!empty($request->from_date)) {
        $query->whereDate('orders.created_at', '>=', $request->from_date);
    }

    if (!empty($request->to_date)) {
        $query->whereDate('orders.created_at', '<=', $request->to_date);
    }
    if (!empty($request->status)) {
        $query->where('orders.order_status', $request->status);
    }
    if (!empty($request->search['value'])) {
        $search = $request->search['value'];
        $query->where(function ($q) use ($search) {
            $q->where('orders.order_number', 'like', "%{$search}%")
              ->orWhere('order_items.sku', 'like', "%{$search}%")
              ->orWhere('order_items.product_name', 'like', "%{$search}%");
        });
    }
    $totalRecords = $query->count();
    if (!empty($request->order)) {
        $orderColumnIndex = $request->order[0]['column'];
        $orderColumnName = $request->columns[$orderColumnIndex]['data'];
        $orderDir = $request->order[0]['dir'];
        $query->orderBy($orderColumnName, $orderDir);
    } else {
        $query->orderBy('orders.created_at', 'desc');
    }
    $orders = $query
        ->skip($request->start)
        ->take($request->length)
        ->get();

    return response()->json([
        'draw' => intval($request->draw),
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $totalRecords,
        'data' => $orders,
    ]);
}
public function getRate(Request $request)
{
    try {
        $carrier = $request->input('carrier', 'FedEx');
        $userId = auth()->id();
        $rateService = new RateService($carrier, $userId);

        // Multiple orders
        $selectedOrders = $request->input('selectedOrderValues', []); // array of orders
        if (empty($selectedOrders)) {
            return response()->json([
                'success' => false,
                'message' => 'No orders selected.'
            ]);
        }

        $totalAmount = 0;
        $currency = 'USD';
        $details = [];

        foreach ($selectedOrders as $orderItem) {
            $orderId = $orderItem['order_number'] ?? null;
            if (!$orderId) {
                continue;
            }

            $order = \App\Models\Order::where('order_number', $orderId)->first();
            if (!$order) {
                $details[] = [
                    'order_number' => $orderId,
                    'success' => false,
                    'message' => 'Order not found.'
                ];
                continue;
            }

            $params = [
                'shipper_name'     => $order->shipper_name ?? 'Your Warehouse Name',
                'shipper_phone'    => $order->shipper_phone ?? '0000000000',
                'shipper_company'  => $order->shipper_company ?? 'Warehouse Inc',
                'shipper_street'   => $order->shipper_address ?? '123 Main Street',
                'shipper_city'     => $order->shipper_city ?? 'Los Angeles',
                'shipper_state'    => $order->shipper_state ?? 'CA',
                'shipper_postal'   => $order->shipper_postal ?? '90001',
                'shipper_country'  => $order->shipper_country ?? 'US',
                'recipient_name'   => $request->input('to_name', 'Mike Hall'),
                'recipient_phone'  => $request->input('to_phone', '+1 207-835-4259'),
                'recipient_company'=> $request->input('to_company', ''),
                'recipient_street' => $request->input('to_address', '4165 HOLBERT AVE'),
                'recipient_city'   => $request->input('to_city', 'DRAPER'),
                'recipient_state'  => $request->input('to_state', 'VA'),
                'recipient_postal' => $request->input('to_postal', '24324-2813'),
                'recipient_country'=> $request->input('to_country', 'US'),
                'residential'      => $request->input('residential', true),
                'weight'           => $request->input('weight', 20),
                'weight_unit'      => $request->input('weight_unit', 'LB'),
                'length'           => $request->input('length', 4),
                'width'            => $request->input('width', 7),
                'height'           => $request->input('height', 4),
                'dimension_unit'   => $request->input('dimension_unit', 'IN'),

                'service_type'     => $request->input('service_code', 'FEDEX_GROUND'),
                'packaging_type'   => $request->input('packaging_type', 'YOUR_PACKAGING'),
                'pickup_type'      => $request->input('pickup_type', 'DROPOFF_AT_FEDEX_LOCATION'),
            ];

            $rates = $rateService->getRate($params);
            $rateDetails = collect($rates['output']['rateReplyDetails'] ?? [])
                ->firstWhere('serviceType', $params['service_type']);

            if ($rateDetails && !empty($rateDetails['ratedShipmentDetails'])) {
                $shipmentDetail = $rateDetails['ratedShipmentDetails'][0];
                $amount = $shipmentDetail['totalNetCharge'] ?? 0;
                $currency = $shipmentDetail['currency'] ?? 'USD';

                $totalAmount += $amount;

                $details[] = [
                    'order_number' => $orderId,
                    'success' => true,
                    'rate' => $amount,
                    'currency' => $currency
                ];
            } else {
                $details[] = [
                    'order_number' => $orderId,
                    'success' => false,
                    'message' => 'Rate not available'
                ];
            }
        }

        return response()->json([
            'success' => true,
            'rate' => $totalAmount,
            'currency' => $currency,
            'details' => $details
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}
public function createPrintLabels(Request $request)
{
    $validated = $request->validate([
        'order_ids'     => 'required|array',
        'order_ids.*'   => 'exists:orders,id',
        'service_code'  => 'required|string',
        'package_type'  => 'required|string',
        'weight_lb'     => 'required|numeric|min:0',
        'weight_oz'     => 'required|numeric|min:0',
        'length'        => 'required|numeric|min:0',
        'width'         => 'required|numeric|min:0',
        'height'        => 'required|numeric|min:0',
    ]);

    try {
        foreach ($validated['order_ids'] as $orderId) {
            $order = Order::findOrFail($orderId);
            $shipmentService = new ShipmentService('fedex', auth()->id());

            $shipmentData = [
                'shipper_name'      => $order->shipper_name ?? 'Default Shipper',
                'shipper_phone'     => $order->shipper_phone ?? '1234567890',
                'shipper_company'   => $order->shipper_company ?? 'My Company',
                'shipper_street'    => $order->shipper_street ?? '123 Main Street',
                'shipper_city'      => $order->shipper_city ?? 'Los Angeles',
                'shipper_state'     => $order->shipper_state ?? 'CA',
                'shipper_postal'    => $order->shipper_postal ?? '90001',
                'shipper_country'   => $order->shipper_country ?? 'US',

                'recipient_name'    => $order->recipient_name ?? 'Default Recipient',
                'recipient_phone'   => $order->recipient_phone ?? '9876543210',
                'recipient_company' => $order->recipient_company ?? 'Customer',
                'recipient_street'  => trim(($order->ship_address1 ?? '') . ' ' . ($order->ship_address2 ?? '')) ?: 'Unknown Street',
                'recipient_city'    => $order->ship_city ?? 'New York',
                'recipient_state'   => $order->ship_state ?? 'NY',
                'recipient_postal'  => $order->ship_postal_code ?? '10001',
                'recipient_country' => $order->ship_country ?? 'US',

                'residential'       => $order->recipient_company ? false : true,
                'service_type'      => $validated['service_code'],
                'packaging_type'    => $validated['package_type'],
                'weight_unit'       => 'LB',
                'weight'            => $validated['weight_lb'] + ($validated['weight_oz'] / 16),
                'length'            => $validated['length'],
                'width'             => $validated['width'],
                'height'            => $validated['height'],
                'dimension_unit'    => 'IN',
                'label_type'        => 'PDF',
                'label_stock'       => 'PAPER_7X475',
                'pickup_type'       => $request->input('pickup_type', 'DROPOFF_AT_FEDEX_LOCATION'),
            ];

            DB::transaction(function () use ($order, $shipmentData, $validated, $shipmentService) {
                $result = $shipmentService->createShipment($shipmentData);

                Shipment::create([
                    'order_id'          => $order->id,
                    'carrier'           => 'fedex',
                    'service_type'      => $validated['service_code'],
                    'package_weight'    => $shipmentData['weight'],
                    'package_dimensions'=> json_encode([
                        'length' => $validated['length'],
                        'width'  => $validated['width'],
                        'height' => $validated['height'],
                    ]),
                    'tracking_number'   => $result['tracking_number'] ?? null,
                    'label_url'         => $result['label'] ?? null,
                    'shipment_status'   => 'generated',
                    'label_data'        => json_encode($result), 
                    'ship_date'         => now(),
                    'cost'              => $result['cost'] ?? null,
                    'currency'          => $result['currency'] ?? 'USD',
                ]);
            });
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Labels generated successfully',
        ]);

    } catch (\Exception $e) {
        \Log::error('Shipment creation failed', ['message' => $e->getMessage()]);
        return response()->json([
            'status'  => 'error',
            'message' => $e->getMessage(),
        ], 500);
    }
}
}
