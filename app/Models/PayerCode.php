<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayerCode extends Model
{
    protected $table = 'payercodes';

    protected $fillable = [
        'payer_code',
        'country_code',
        'country_name',
        'service_code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCountry($query, string $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    public function scopeByService($query, string $service)
    {
        return $query->where('service_code', $service);
    }

    public function scopeByPayerCode($query, string $payerCode)
    {
        return $query->where('payer_code', $payerCode);
    }
}
