<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierPayoutLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['carrier_payout_id', 'shipment_id', 'remote_tracking_code', 'collected', 'fees', 'net', 'expected_net', 'difference'];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
