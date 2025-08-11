<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class AdminOrderController extends Controller
{
    /**
     * Display a listing of orders.
     */
    public function index()
    {
        $orders = Sale::with('store')->get();
        return view('admin.orders.index', compact('orders'));
    }

    /**
     * Show the form for creating a new order.
     */
    public function create()
    {
        $stores = Store::all();
        return view('admin.orders.create', compact('stores'));
    }

    /**
     * Store a newly created order in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'required|exists:stores,id',
            'order_id' => 'required|unique:sales,order_id',
            'amount' => 'required|numeric',
            'status' => 'required|in:pending,shipped,paid,awaiting_shipment,on_hold,cancelled,alert',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        Sale::create([
            'store_id' => $request->store_id,
            'order_id' => $request->order_id,
            'amount' => $request->amount,
            'status' => $request->status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.orders.index')->with('success', 'Order created successfully');
    }

    /**
     * Fetch orders from Amazon API and store them.
     */
    public function fetchOrders()
    {
        $integration = Integration::where('store_id',1)->get();

        if (!$integration || $integration->expires_at->lt(now())) {
            $response = Http::asForm()->post('https://api.amazon.com/auth/o2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $integration->refresh_token,
                'client_id' => env('AMAZON_CLIENT_ID'),
                'client_secret' => env('AMAZON_CLIENT_SECRET'),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $integration->update([
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? $integration->refresh_token,
                    'expires_at' => Carbon::now()->addSeconds($data['expires_in']),
                    'updated_at' => now(),
                ]);
            } else {
                return redirect()->back()->with('error', 'Token refresh failed');
            }
        }

        $endpoint = 'https://sellingpartnerapi-na.amazon.com/orders/v0/orders';
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $integration->access_token,
            'x-amz-access-token' => $integration->access_token,
        ])->get($endpoint, [
            'MarketplaceIds' => 'ATVPDKIKX0DER', 
        ]);

        if ($response->successful()) {
            $orders = $response->json()['payload']['Orders'] ?? [];
            foreach ($orders as $order) {
                Sale::updateOrCreate(
                    ['order_id' => $order['AmazonOrderId']],
                    [
                        'store_id' => $integration->store_id,
                        'amount' => $order['OrderTotal']['Amount'] ?? 0.00,
                        'status' => $order['OrderStatus'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
            return redirect()->route('admin.orders.index')->with('success', 'Orders fetched and stored');
        }

        return redirect()->back()->with('error', 'Failed to fetch orders');
    }
}