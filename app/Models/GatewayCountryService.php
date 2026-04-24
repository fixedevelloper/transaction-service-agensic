<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GatewayCountryService extends Model
{
    use HasFactory;

    protected $table = 'gateway_country_services';

    protected $fillable = [
        'gateway_id',
        'country_code',
        'service_type',
        'is_enabled',
        'is_default',
        'priority',
        'fixed_fee',
        'percent_fee',
        'min_amount',
        'max_amount',
        'currency',
        'meta',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_default' => 'boolean',
        'priority' => 'integer',
        'fixed_fee' => 'float',
        'percent_fee' => 'float',
        'min_amount' => 'float',
        'max_amount' => 'float',
        'meta' => 'array',
    ];

    /**
     * Relation vers le gateway
     */
    public function gateway()
    {
        return $this->belongsTo(PaymentGateway::class, 'gateway_id');
    }

    /**
     * Scope : actifs uniquement
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope : par pays
     */
    public function scopeCountry($query, string $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * Scope : par type de service (mobile / bank)
     */
    public function scopeService($query, string $serviceType)
    {
        return $query->where('service_type', $serviceType);
    }

    /**
     * Scope : tri par priorité (routing intelligent)
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('priority', 'asc');
    }

    /**
     * Vérifie si cette configuration est utilisable
     */
    public function isUsable(): bool
    {
        return $this->is_enabled && $this->gateway?->is_active;
    }

    /**
     * Calcule les frais d'une transaction
     */
    public function calculateFee(float $amount): float
    {
        $fee = $this->fixed_fee;

        if ($this->percent_fee > 0) {
            $fee += ($amount * $this->percent_fee / 100);
        }

        return $fee;
    }
}