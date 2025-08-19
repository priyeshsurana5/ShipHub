<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        // Basic order details
        'marketplace',
        'store_id',
        'order_number',
        'order_date',
        'order_age',
        'notes',
        'is_gift',
        'item_sku',
        'item_name',
        'batch',
        'quantity',
        'order_total',
        'recipient_name',
        'recipient_company',
        'recipient_email',
        'recipient_phone',
        'ship_address1',
        'ship_address2',
        'ship_city',
        'ship_state',
        'ship_postal_code',
        'ship_country',
        'shipping_carrier',
        'shipping_service',
        'shipping_cost',
        'tracking_number',
        'ship_date',
        'label_status',
        'order_status',
        'payment_status',
        'fulfillment_status',
        'external_order_id',
        'raw_data',
        'shipper_name',
        'shipper_phone',
        'shipper_company',
        'shipper_street',
        'shipper_city',
        'shipper_state',
        'shipper_country',
        'shipper_postal'  
    ];

    protected $casts = [
        'order_date' => 'datetime',
        'ship_date'  => 'datetime',
        'raw_data'   => 'array',
        'is_gift'    => 'boolean',
        'shipping_cost' => 'decimal:2',
        'order_total'   => 'decimal:2',
    ];
}
