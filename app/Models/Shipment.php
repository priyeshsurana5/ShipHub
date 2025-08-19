<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'carrier',
        'service_type',
        'package_weight',
        'package_dimensions',
        'tracking_number',
        'label_url',
        'shipment_status',
        'label_data',
        'ship_date',
        'cost',
        'currency',
    ];

    protected $casts = [
        'ship_date' => 'date',
        'label_data' => 'array',
    ];
}
