<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentGateway extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'type',
        'is_active',
        'logo',
        'website',
        'credentials',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'credentials' => 'array',
        'settings' => 'array',
    ];

    /**
     * Relation : un gateway peut être utilisé dans plusieurs pays/services
     */
    public function countryServices()
    {
        return $this->hasMany(GatewayCountryService::class, 'gateway_id');
    }

    /**
     * Scope : uniquement actifs
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope : par code (flutterwave, wace, etc.)
     */
    public function scopeCode($query, string $code)
    {
        return $query->where('code', $code);
    }

    /**
     * Vérifie si le gateway supporte un pays + service
     */
    public function supports(string $countryCode, string $serviceType): bool
    {
        return $this->countryServices()
            ->where('country_code', $countryCode)
            ->where('service_type', $serviceType)
            ->where('is_enabled', true)
            ->exists();
    }
}